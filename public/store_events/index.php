<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../../app/db.php';
if (is_file(__DIR__ . '/../../app/auth.php')) require_once __DIR__ . '/../../app/auth.php';
if (is_file(__DIR__ . '/../../app/layout.php')) require_once __DIR__ . '/../../app/layout.php';

if (function_exists('require_login')) require_login();
if (function_exists('require_role')) {
  // 店舗イベントは店長/管理/キャストでも見れる想定（必要に応じて絞って）
  require_role(['cast','staff','manager','admin','super_user']);
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$storeId = (int)($_GET['store_id'] ?? 0);
if ($storeId <= 0) {
  http_response_code(400);
  echo "store_id required";
  exit;
}

$tab = ($_GET['tab'] ?? 'external');
$tab = in_array($tab, ['external','internal'], true) ? $tab : 'external';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* =========================
  EXTERNAL filters
========================= */
$q       = trim((string)($_GET['q'] ?? ''));
$source  = trim((string)($_GET['source'] ?? ''));
$from    = trim((string)($_GET['from'] ?? ''));
$to      = trim((string)($_GET['to'] ?? ''));
$limit   = max(20, min(300, (int)($_GET['limit'] ?? 120)));

if ($from === '' || $to === '') {
  // デフォルト：当月〜3ヶ月先
  $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
  $from = $from ?: $now->format('Y-m-01');
  $to   = $to   ?: $now->modify('+3 months')->format('Y-m-t');
}

/* =========================
  EXTERNAL sources list
========================= */
$sources = [];
try {
  $st = $pdo->prepare("SELECT source, COUNT(*) c
                         FROM store_external_events
                        WHERE store_id=:sid
                        GROUP BY source
                        ORDER BY c DESC");
  $st->execute(['sid'=>$storeId]);
  $sources = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  // table not exist etc
  $sources = [];
}

/* =========================
  Query external events
========================= */
$externalRows = [];
$externalCount = 0;

if ($tab === 'external') {
  $where = ["e.store_id = :sid"];
  $params = ['sid'=>$storeId];

  if ($source !== '') {
    $where[] = "e.source = :source";
    $params['source'] = $source;
  }
  if ($q !== '') {
    $where[] = "(e.title LIKE :q OR e.venue_name LIKE :q OR e.venue_addr LIKE :q)";
    $params['q'] = '%' . $q . '%';
  }

  // 期間フィルタ（starts_at/ends_at がNULLでも出したいなら調整）
  if ($from !== '') {
    $where[] = "(e.ends_at IS NULL OR e.ends_at >= :from_dt)";
    $params['from_dt'] = $from . " 00:00:00";
  }
  if ($to !== '') {
    $where[] = "(e.starts_at IS NULL OR e.starts_at <= :to_dt)";
    $params['to_dt'] = $to . " 23:59:59";
  }

  $whereSql = implode(" AND ", $where);

  $stCnt = $pdo->prepare("SELECT COUNT(*) FROM store_external_events e WHERE {$whereSql}");
  $stCnt->execute($params);
  $externalCount = (int)$stCnt->fetchColumn();

  $sql = "SELECT e.*
            FROM store_external_events e
           WHERE {$whereSql}
           ORDER BY COALESCE(e.starts_at, '9999-12-31 00:00:00') ASC, e.id ASC
           LIMIT {$limit}";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $externalRows = $st->fetchAll(PDO::FETCH_ASSOC);
}

/* =========================
  Internal tab (minimum)
========================= */
$internalRows = [];
if ($tab === 'internal') {
  $st = $pdo->prepare("SELECT id, title, status, starts_at, ends_at
                         FROM store_event_instances
                        WHERE store_id=:sid
                        ORDER BY starts_at DESC
                        LIMIT 120");
  $st->execute(['sid'=>$storeId]);
  $internalRows = $st->fetchAll(PDO::FETCH_ASSOC);
}

/* =========================
  Layout
========================= */
$title = "店舗イベント";
if (function_exists('render_header')) {
  render_header($title);
} else {
  ?><!doctype html><html lang="ja"><head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h($title)?></title>
  <style>
    body{font-family:system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,"Hiragino Kaku Gothic ProN","Noto Sans JP","Yu Gothic",sans-serif;background:#0b1020;color:#e8ecff;margin:0}
    a{color:#8ab4ff;text-decoration:none}
    .wrap{max-width:1100px;margin:0 auto;padding:16px}
    .card{background:#121a33;border:1px solid rgba(255,255,255,.12);border-radius:14px;padding:14px;margin:12px 0}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    .tabs a{display:inline-block;padding:8px 12px;border-radius:999px;border:1px solid rgba(255,255,255,.15);margin-right:8px}
    .tabs a.active{background:#1b2850;border-color:rgba(255,255,255,.25)}
    input,select{background:#0f1730;color:#e8ecff;border:1px solid rgba(255,255,255,.18);border-radius:10px;padding:8px}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid rgba(255,255,255,.12);vertical-align:top}
    th{color:#aab3d6;text-align:left;font-weight:600}
    .muted{color:#aab3d6}
    .btn{display:inline-block;padding:8px 10px;border-radius:10px;border:1px solid rgba(255,255,255,.18);background:#0f1730}
    .btn.primary{background:#1b2850}
    .badge{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid rgba(255,255,255,.18);color:#aab3d6;font-size:12px}
  </style>
  </head><body><div class="wrap"><?php
}

?>
<div class="row" style="justify-content:space-between">
  <div>
    <h1 style="margin:8px 0 2px"><?=h($title)?></h1>
    <div class="muted">store_id=<?= (int)$storeId ?></div>
  </div>
  <div class="row">
    <a class="btn primary" href="./new.php?store_id=<?=$storeId?>">＋ 店内イベント作成</a>
  </div>
</div>

<div class="card tabs">
  <a class="<?= $tab==='external'?'active':'' ?>" href="?store_id=<?=$storeId?>&tab=external">外部イベント（岡山）</a>
  <a class="<?= $tab==='internal'?'active':'' ?>" href="?store_id=<?=$storeId?>&tab=internal">店内イベント</a>
</div>

<?php if ($tab === 'external'): ?>
  <div class="card">
    <form method="get" class="row">
      <input type="hidden" name="store_id" value="<?=$storeId?>">
      <input type="hidden" name="tab" value="external">

      <label class="muted">from
        <input type="date" name="from" value="<?=h($from)?>">
      </label>
      <label class="muted">to
        <input type="date" name="to" value="<?=h($to)?>">
      </label>

      <label class="muted">source
        <select name="source">
          <option value="">（全て）</option>
          <?php foreach ($sources as $s): ?>
            <option value="<?=h((string)$s['source'])?>" <?=((string)$s['source']===$source)?'selected':''?>>
              <?=h((string)$s['source'])?> (<?= (int)$s['c'] ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="muted">検索
        <input type="text" name="q" value="<?=h($q)?>" placeholder="タイトル/会場/住所">
      </label>

      <label class="muted">件数
        <input type="number" name="limit" value="<?=$limit?>" min="20" max="300" style="width:90px">
      </label>

      <button class="btn primary" type="submit">絞り込み</button>
      <a class="btn" href="?store_id=<?=$storeId?>&tab=external">リセット</a>
      <span class="badge">hit <?= (int)$externalCount ?></span>
    </form>
  </div>

  <div class="card">
    <table>
      <thead>
        <tr>
          <th style="width:170px">期間</th>
          <th>外部イベント</th>
          <th style="width:140px">ソース</th>
          <th style="width:170px">操作</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($externalRows as $r):
        $sa = (string)($r['starts_at'] ?? '');
        $ea = (string)($r['ends_at'] ?? '');
        $period = '';
        if ($sa !== '') $period .= substr($sa, 0, 16);
        if ($ea !== '') $period .= '〜' . substr($ea, 0, 16);
        if ($period === '') $period = '（日時不明）';

        $venue = trim((string)($r['venue_name'] ?? ''));
        $addr  = trim((string)($r['venue_addr'] ?? ''));
        $srcUrl = (string)($r['source_url'] ?? '');
      ?>
        <tr>
          <td>
            <div><?=h($period)?></div>
            <?php if ((int)($r['all_day'] ?? 0) === 1): ?>
              <div class="badge">終日</div>
            <?php endif; ?>
          </td>
          <td>
            <div style="font-weight:700"><?=h((string)$r['title'])?></div>
            <?php if ($venue !== '' || $addr !== ''): ?>
              <div class="muted"><?=h($venue)?><?= $addr!=='' ? ' / '.h($addr) : '' ?></div>
            <?php endif; ?>
            <?php if ($srcUrl !== ''): ?>
              <div><a href="<?=h($srcUrl)?>" target="_blank" rel="noopener">元ページ</a></div>
            <?php endif; ?>
          </td>
          <td>
            <div class="badge"><?=h((string)$r['source'])?></div>
            <div class="muted" style="margin-top:6px"><?=h((string)$r['source_id'])?></div>
          </td>
          <td>
            <a class="btn primary" href="./new.php?store_id=<?=$storeId?>&from_external_id=<?= (int)$r['id'] ?>">店内イベントを作る</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$externalRows): ?>
        <tr><td colspan="4" class="muted">該当なし</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

<?php else: /* internal */ ?>

  <div class="card">
    <div class="row" style="justify-content:space-between">
      <div class="muted">最近の店内イベント（最大120件）</div>
      <a class="btn primary" href="./new.php?store_id=<?=$storeId?>">＋ 店内イベント作成</a>
    </div>

    <table>
      <thead>
        <tr>
          <th style="width:160px">開始</th>
          <th>タイトル</th>
          <th style="width:120px">状態</th>
          <th style="width:140px">操作</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($internalRows as $r): ?>
        <tr>
          <td><?=h(substr((string)$r['starts_at'],0,16))?></td>
          <td style="font-weight:700"><?=h((string)$r['title'])?></td>
          <td><span class="badge"><?=h((string)$r['status'])?></span></td>
          <td><a class="btn" href="./edit.php?store_id=<?=$storeId?>&id=<?= (int)$r['id'] ?>">編集</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$internalRows): ?>
        <tr><td colspan="4" class="muted">まだ店内イベントがありません</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

<?php endif; ?>

<?php
if (function_exists('render_footer')) {
  render_footer();
} else {
  echo "</div></body></html>";
}