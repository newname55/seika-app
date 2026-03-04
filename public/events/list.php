<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

/**
 * public/events/list.php
 * haruto_core.events を一覧・検索する（layout.php に統一）
 */

$root = dirname(__DIR__, 2); // /var/www/html/seika-app

require_once $root . '/app/auth.php';
require_once $root . '/app/db.php';
require_once $root . '/app/layout.php';
require_once $root . '/app/store.php';

if (function_exists('require_login')) {
  require_login();
}

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('has_role')) {
  function has_role(string $role): bool {
    return isset($_SESSION['roles']) && is_array($_SESSION['roles']) && in_array($role, $_SESSION['roles'], true);
  }
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$userId = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);

$isSuper   = has_role('super_user');
$isAdmin   = has_role('admin');
$isManager = has_role('manager');
$isCast    = has_role('cast');

// cast専用へ（dashboard.php と同じ方針）
if ($isCast && !$isAdmin && !$isManager && !$isSuper) {
  header('Location: /seika-app/public/dashboard_cast.php');
  exit;
}

// ✅ super_user は「未選択なら店舗選択を挟む」
if (function_exists('require_store_selected_for_super')) {
  require_store_selected_for_super($isSuper, '/seika-app/public/events/list.php');
}

/**
 * ✅ 店舗一覧の方針
 * - super_user: 全店
 * - admin: 全店
 * - manager: 自分に紐づく店だけ
 */
$stores = [];
if ($isSuper || $isAdmin) {
  $stores = $pdo->query("SELECT id,name FROM stores WHERE is_active=1 ORDER BY id ASC")
                ->fetchAll(PDO::FETCH_ASSOC) ?: [];
} elseif ($isManager && function_exists('repo_allowed_stores')) {
  $stores = repo_allowed_stores($pdo, $userId, false);
}

// store_id 決定（GET優先 → セッション → 先頭）
$storeId = 0;
if ($stores) {
  $candidate = (int)($_GET['store_id'] ?? 0);
  if ($candidate <= 0 && function_exists('get_current_store_id')) $candidate = (int)get_current_store_id();
  if ($candidate <= 0) $candidate = (int)$stores[0]['id'];

  $allowedIds = array_map(fn($s)=>(int)$s['id'], $stores);
  if (!in_array($candidate, $allowedIds, true)) $candidate = (int)$stores[0]['id'];

  $storeId = $candidate;

  if (function_exists('set_current_store_id')) {
    set_current_store_id($storeId);
  } else {
    $_SESSION['store_id'] = $storeId;
  }
}

// 店舗名
$storeName = '';
foreach ($stores as $s) {
  if ((int)$s['id'] === $storeId) { $storeName = (string)$s['name']; break; }
}

// ---- 入力系ヘルパ ----
function ymd(?string $s): ?string {
  $s = trim((string)$s);
  if ($s === '') return null;
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
  return $s;
}
function strq(?string $s, int $max=200): ?string {
  $s = trim((string)$s);
  if ($s === '') return null;
  if (mb_strlen($s) > $max) $s = mb_substr($s, 0, $max);
  return $s;
}

// ---- 検索パラメータ ----
$from  = ymd($_GET['from'] ?? '') ?? (new DateTimeImmutable('today'))->format('Y-m-d');
$to    = ymd($_GET['to'] ?? '')   ?? (new DateTimeImmutable('today'))->modify('+60 days')->format('Y-m-d');
$venue = strq($_GET['venue'] ?? '');
$org   = strq($_GET['org'] ?? '');
$q     = strq($_GET['q'] ?? '', 300);

$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = max(10, min(200, (int)($_GET['limit'] ?? 50)));
$offset = ($page - 1) * $limit;

// ---- WHERE 組み立て ----
$where = [];
$params = [];



// 日付範囲（starts_atがあるものだけ）
$where[] = "e.starts_at IS NOT NULL";
$where[] = "e.starts_at >= :from_dt";
$where[] = "e.starts_at <  :to_dt";
$params['from_dt'] = $from . ' 00:00:00';
$params['to_dt']   = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

// 部分一致（プレースホルダは全部ユニーク）
if ($venue !== null && $venue !== '') {
  $where[] = "(e.venue_name LIKE :venue_kw1 OR e.address LIKE :venue_kw2 OR e.city LIKE :venue_kw3 OR e.prefecture LIKE :venue_kw4)";
  $params['venue_kw1'] = '%' . $venue . '%';
  $params['venue_kw2'] = '%' . $venue . '%';
  $params['venue_kw3'] = '%' . $venue . '%';
  $params['venue_kw4'] = '%' . $venue . '%';
}
if ($org !== null && $org !== '') {
  $where[] = "(e.organizer_name LIKE :org_kw1 OR e.contact_name LIKE :org_kw2)";
  $params['org_kw1'] = '%' . $org . '%';
  $params['org_kw2'] = '%' . $org . '%';
}
if ($q !== null && $q !== '') {
  $where[] = "(e.title LIKE :q_kw1 OR e.description LIKE :q_kw2 OR e.source_url LIKE :q_kw3)";
  $params['q_kw1'] = '%' . $q . '%';
  $params['q_kw2'] = '%' . $q . '%';
  $params['q_kw3'] = '%' . $q . '%';
}

$sql_where = 'WHERE ' . implode(' AND ', $where);

// ---- 件数 ----
$sql_count = "SELECT COUNT(*) FROM events e $sql_where";
$st = $pdo->prepare($sql_count);
$st->execute($params);
$total = (int)$st->fetchColumn();
$pages = (int)ceil(max(1, $total) / $limit);
if ($page > $pages) $page = $pages;

// ---- 一覧 ----
$sql = "SELECT
          e.event_id, e.title, e.starts_at, e.ends_at, e.all_day, e.status,
          e.venue_name, e.address,
          e.organizer_name, e.contact_name,
          e.source_url,
          s.name AS source_name
        FROM events e
        JOIN event_sources s ON s.source_id = e.source_id
        $sql_where
        ORDER BY e.starts_at ASC, e.event_id ASC
        LIMIT $limit OFFSET $offset";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
// 最終更新情報（管理用）
$sql_meta = "
  SELECT
    MAX(updated_at) AS last_updated,
    COUNT(*)        AS total_events
  FROM events
";
$meta = $pdo->query($sql_meta)->fetch(PDO::FETCH_ASSOC);

$last_updated = $meta['last_updated'] ?? null;
$total_events = (int)($meta['total_events'] ?? 0);

// ---- URL組み立て（ページャ用） ----
function build_qs(array $overrides = []): string {
  $q = $_GET;
  foreach ($overrides as $k => $v) {
    if ($v === null) unset($q[$k]);
    else $q[$k] = $v;
  }
  return http_build_query($q);
}

render_page_start('イベント一覧');

$right = '';
if ($lastUpdated) {
  $right .= '<span class="pill">最終更新(DB): ' . h((string)$lastUpdated) . '</span>';
}

render_header('岡山県のイベント一覧', [
  'active' => 'events',
  'back_href' => '/seika-app/public/dashboard.php',
  'right_html' => $right,
]);

?>
<div class="container">
<div class="page">
  <span style="color:#aab3d6;font-size:12px;">
  岡山県のイベント一覧
    （<?= h((string)$total) ?>件）
  </span>
<div style="margin-top:4px;color:#aab3d6;font-size:12px;">
  最終更新：
  <?= $last_updated ? h($last_updated) : '未更新' ?>
  ／ DB登録件数：<?= h((string)$total_events) ?>件
</div>
  <div class="card" style="padding:14px;">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;">
      <div>
        <div class="muted" style="font-size:12px;">検索結果</div>
        <div style="font-size:18px;font-weight:700;"><?= h((string)$total) ?> 件</div>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a class="btn" href="list.php">リセット</a>
        <a class="btn" href="list.php?<?= h(build_qs(['page'=>1])) ?>">この条件で先頭へ</a>
      </div>
    </div>

    <form method="get" style="margin-top:12px;">
      <div class="grid" style="display:grid;grid-template-columns:repeat(12,1fr);gap:10px;align-items:end;">

        <div style="grid-column: span 3;">
          <label class="muted" style="display:block;font-size:12px;margin-bottom:6px;">開始日（from）</label>
          <input type="date" name="from" value="<?= h($from) ?>">
        </div>

        <div style="grid-column: span 3;">
          <label class="muted" style="display:block;font-size:12px;margin-bottom:6px;">終了日（to）</label>
          <input type="date" name="to" value="<?= h($to) ?>">
        </div>

        <div style="grid-column: span 3;">
          <label class="muted" style="display:block;font-size:12px;margin-bottom:6px;">表示件数</label>
          <select name="limit">
            <?php foreach ([20,50,100,200] as $n): ?>
              <option value="<?= $n ?>" <?= $limit===$n?'selected':'' ?>><?= $n ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div style="grid-column: span 8;">
          <label class="muted" style="display:block;font-size:12px;margin-bottom:6px;">キーワード</label>
          <input type="text" name="q" value="<?= h((string)$q) ?>" placeholder="タイトル/本文/URL">
        </div>

        <div style="grid-column: span 12; display:flex; gap:10px; flex-wrap:wrap;">
          <button class="btn primary" type="submit">検索</button>
          <a class="btn" href="list.php">クリア</a>
        </div>
      </div>
    </form>
  </div>
  <div class="card">
    <div style="overflow:auto;">
      <table class="table" style="width:100%;border-collapse:collapse;">
        <thead>
          <tr>
            <th style="width:200px;">日時</th>
            <th style="width:600px;">タイトル</th>
            <!-- <th style="width:120px;">ソース</th> -->
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="5" class="muted">該当なし</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <?php
              $when = '';
              if (!empty($r['starts_at'])) {
                $dt = new DateTimeImmutable((string)$r['starts_at']);
                if ((int)($r['all_day'] ?? 0) === 1) $when = $dt->format('Y-m-d') . '（終日）';
                else $when = $dt->format('Y-m-d H:i');
              }

              $venue_txt = trim((string)($r['venue_name'] ?? ''));
              $addr_txt  = trim((string)($r['address'] ?? ''));
              $place = $venue_txt !== '' ? $venue_txt : $addr_txt;

              $org_txt = trim((string)($r['organizer_name'] ?? ''));
              $contact_txt = trim((string)($r['contact_name'] ?? ''));
              $orgline = $org_txt !== '' ? $org_txt : $contact_txt;

              $src = trim((string)($r['source_name'] ?? ''));
              $url = (string)($r['source_url'] ?? '');
            ?>
            <tr>
              <td><?= h($when) ?></td>
              <td>
                <?php if ($url !== ''): ?>
                  <a href="<?= h($url) ?>" target="_blank" rel="noopener noreferrer"><?= h((string)$r['title']) ?></a>
                <?php else: ?>
                  <?= h((string)$r['title']) ?>
                <?php endif; ?>

              <!-- <td class="muted"><?= h($orgline) ?></td> -->
              <td class="muted"><?= h($src) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:space-between;margin-top:12px;">
      <div class="muted">page <?= h((string)$page) ?> / <?= h((string)$pages) ?></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <?php if ($page > 1): ?>
          <a class="btn" href="list.php?<?= h(build_qs(['page'=>1])) ?>">«</a>
          <a class="btn" href="list.php?<?= h(build_qs(['page'=>$page-1])) ?>">‹</a>
        <?php endif; ?>
        <?php if ($page < $pages): ?>
          <a class="btn" href="list.php?<?= h(build_qs(['page'=>$page+1])) ?>">›</a>
          <a class="btn" href="list.php?<?= h(build_qs(['page'=>$pages])) ?>">»</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
  </div>
</div>
<?php render_page_end(); ?>