<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/layout.php';
require_once __DIR__ . '/../../app/store_decommission.php';

require_login();
if (function_exists('require_role')) {
  require_role(['cast','admin','manager','super_user']);
}

$pdo = db();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* =========================
  helpers
========================= */
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function has_role(string $role): bool {
  return isset($_SESSION['roles']) && is_array($_SESSION['roles']) && in_array($role, $_SESSION['roles'], true);
}
function current_user_id_safe(): int {
  return function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
}
function can_view_staff_notes(): bool {
  return has_role('admin') || has_role('manager') || has_role('super_user');
}
function customer_status_label(string $status): string {
  return $status === 'active' ? 'アクティブ' : '休眠';
}
function customer_status_class(string $status): string {
  return $status === 'active' ? 'st-active' : 'st-inactive';
}
function note_preview(?string $text, int $max = 90): string {
  $value = trim((string)$text);
  if ($value === '') {
    return '';
  }

  $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
  if (mb_strlen($value) <= $max) {
    return $value;
  }

  return mb_substr($value, 0, $max) . '…';
}

function current_store_business_date_local(PDO $pdo, int $storeId): string {
  try {
    $st = $pdo->prepare("SELECT id, business_day_start FROM stores WHERE id=? LIMIT 1");
    $st->execute([$storeId]);
    $store = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($store) && function_exists('business_date_for_store')) {
      return business_date_for_store($store, null);
    }
  } catch (Throwable $e) {
    // noop
  }

  return (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
}

/* =========================
  input
========================= */
$storeId = (int)($_GET['store_id'] ?? ($_SESSION['store_id'] ?? 0));
$q = trim((string)($_GET['q'] ?? ''));
$limit = 200;

if ($storeId <= 0) {
  http_response_code(400);
  echo "store_id が必要です";
  exit;
}
store_decommission_assert_store_writable($pdo, $storeId, '停止中の店舗では顧客画面を開けません');

$me = current_user_id_safe();
$isStaff = can_view_staff_notes();
$currentBusinessDate = current_store_business_date_local($pdo, $storeId);

/**
 * 可視性ルール
 * - staff: public + staff + 自分private
 * - cast : public + 自分private
 *
 * 一覧では「他人private」は絶対に拾わない
 */
$visSql = $isStaff
  ? "(n.visibility IN ('public','staff') OR (n.visibility='private' AND n.author_user_id = :me))"
  : "(n.visibility = 'public' OR (n.visibility='private' AND n.author_user_id = :me))";

/* =========================
  SQL
========================= */
$sql = "
SELECT
  c.id,
  c.display_name,
  c.features,
  c.status,
  c.last_visit_at,

  MAX(CASE WHEN l.link_role='primary' THEN l.cast_user_id END) AS primary_cast_user_id,
  MAX(CASE WHEN l.link_role='primary' THEN l.last_seen_at END)   AS primary_last_seen_at,

  (
    SELECT n.note_text
    FROM customer_notes n
    WHERE n.store_id = c.store_id
      AND n.customer_id = c.id
      AND n.note_type = 'summary'
      AND {$visSql}
    ORDER BY n.created_at DESC
    LIMIT 1
  ) AS summary_text

FROM customers c
LEFT JOIN customer_cast_links l
  ON l.customer_id = c.id
WHERE c.store_id = :store_id
  AND c.merged_into_customer_id IS NULL
";

$params = [
  ':store_id' => $storeId,
  ':me' => $me,
];

if ($q !== '') {
  $sql .= "
  AND (
    c.display_name LIKE :q
    OR c.features LIKE :q
    OR EXISTS (
      SELECT 1
      FROM customer_notes nn
      WHERE nn.store_id = c.store_id
        AND nn.customer_id = c.id
        AND nn.note_text LIKE :q
        AND {$visSql}
    )
  )
  ";
  $params[':q'] = '%' . $q . '%';
}

$sql .= "
GROUP BY c.id
ORDER BY COALESCE(c.last_visit_at, c.created_at) DESC
LIMIT {$limit}
";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$hasQuery = ($q !== '');

/* =========================
  render
========================= */
$pageTitle = "顧客台帳（店舗）";
render_page_start($pageTitle);
?>
<style>
  .page{ max-width:1100px; margin:0 auto; padding:14px; display:grid; gap:12px; }
  .card{ background:var(--card,#fff); border:1px solid var(--line,#e5e7eb); border-radius:14px; padding:12px; }
  .row{ display:flex; gap:10px; flex-wrap:wrap; align-items:end; }
  .field{ display:grid; gap:6px; }
  .label{ font-size:12px; color:var(--muted,#6b7280); font-weight:800; }
  .input{ min-height:44px; padding:10px 12px; border-radius:12px; border:1px solid var(--line,#e5e7eb); background:transparent; }
  table{ width:100%; border-collapse:collapse; }
  th,td{ padding:10px; border-bottom:1px solid var(--line,#e5e7eb); vertical-align:top; }
  th{ text-align:left; font-size:12px; color:var(--muted,#6b7280); }
  .muted{ color:var(--muted,#6b7280); }
  .name a{ font-weight:900; text-decoration:none; }
  .sum{ font-size:13px; }
  .status{ font-weight:900; }
  .st-active{ color:#059669; }
  .st-inactive{ color:#b45309; }
  .pill{ display:inline-flex; align-items:center; border:1px solid var(--line,#e5e7eb); border-radius:999px; padding:2px 8px; font-size:12px; }
  .hero{ display:flex; gap:12px; flex-wrap:wrap; justify-content:space-between; align-items:flex-start; }
  .stats{ display:flex; gap:8px; flex-wrap:wrap; }
  .stat{ min-width:110px; padding:10px 12px; border:1px solid var(--line,#e5e7eb); border-radius:12px; background:color-mix(in srgb, var(--card,#fff) 82%, transparent); }
  .stat b{ display:block; font-size:18px; font-weight:1000; }
  .summaryCell{ min-width:240px; }
  .tableWrap{ overflow:auto; }
  .empty{ padding:28px 16px; text-align:center; border:1px dashed var(--line,#e5e7eb); border-radius:14px; }
  .customerMeta{ display:flex; gap:6px; flex-wrap:wrap; margin-top:6px; }
  @media (max-width: 860px){
    th:nth-child(4), td:nth-child(4){ display:none; }
  }
</style>

<div class="page">
  <div class="card">
    <div class="hero">
      <div style="flex:1; min-width:240px;">
        <div style="font-size:18px; font-weight:1000;">顧客台帳（店舗）</div>
        <div class="muted" style="font-size:12px;">
          代表顧客のみ表示（マージ済みは除外） / 要約（summary）を一覧に表示
        </div>
      </div>
      <div class="stats">
        <div class="stat">
          <span class="muted" style="font-size:12px;">表示件数</span>
          <b><?= count($rows) ?></b>
        </div>
        <div class="stat">
          <span class="muted" style="font-size:12px;">検索</span>
          <b><?= $hasQuery ? '絞込中' : '全件' ?></b>
        </div>
        <div class="stat">
          <span class="muted" style="font-size:12px;">store_id</span>
          <b><?= (int)$storeId ?></b>
        </div>
      </div>
    </div>

    <form method="get" class="row" style="margin-top:10px;">
      <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
      <div class="field" style="flex:1; min-width:240px;">
        <div class="label">検索（名前 / 特徴 / メモ）</div>
        <input class="input" name="q" value="<?= h($q) ?>" placeholder="例：よく笑う / 焼酎 / 平成曲 / 野球">
      </div>
      <div class="field">
        <div class="label">　</div>
        <button class="input" style="cursor:pointer; font-weight:900;">表示</button>
      </div>
    </form>
  </div>

  <div class="card">
    <div class="muted" style="font-size:12px; margin-bottom:8px;">一覧（<?= count($rows) ?>件 / 最大<?= (int)$limit ?>件）</div>

    <?php if ($rows): ?>
      <div class="tableWrap">
        <table>
          <thead>
            <tr>
              <th style="width:90px;">ID</th>
              <th>名前 / 特徴</th>
              <th style="width:110px;">状態</th>
              <th style="width:160px;">最終来店</th>
              <th>引き継ぎ要約</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <?php
                $cid = (int)$r['id'];
                $status = (string)$r['status'];
                $sum = trim((string)($r['summary_text'] ?? ''));
                $sumPreview = note_preview($sum, 100);
                $features = trim((string)($r['features'] ?? ''));
              ?>
              <tr>
                <td class="muted">#<?= $cid ?></td>
                <td class="name">
                  <a href="detail.php?store_id=<?= (int)$storeId ?>&id=<?= $cid ?>">
                    <?= h((string)$r['display_name']) ?>
                  </a>
                  <?php if ($features !== ''): ?>
                    <div class="muted" style="margin-top:4px;">
                      <?= h($features) ?>
                    </div>
                  <?php endif; ?>
                  <div class="customerMeta">
                    <span class="pill <?= customer_status_class($status) ?>"><?= customer_status_label($status) ?></span>
                    <span class="pill">最終来店: <?= $r['last_visit_at'] ? h((string)$r['last_visit_at']) : '—' ?></span>
                    <a class="pill" href="/wbss/public/cashier/index.php?<?= http_build_query([
                      'store_id' => $storeId,
                      'action' => 'new',
                      'business_date' => $currentBusinessDate,
                      'customer_id' => $cid,
                      'visit_type' => 'repeat',
                    ]) ?>">会計開始</a>
                  </div>
                </td>
                <td class="status <?= customer_status_class($status) ?>">
                  <?= customer_status_label($status) ?>
                </td>
                <td class="muted">
                  <?= $r['last_visit_at'] ? h((string)$r['last_visit_at']) : '—' ?>
                </td>
                <td class="sum summaryCell">
                  <?php if ($sumPreview !== ''): ?>
                    <?= nl2br(h($sumPreview)) ?>
                  <?php else: ?>
                    <span class="muted">（要約なし）</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <div class="empty">
        <div style="font-weight:1000;">該当する顧客が見つかりませんでした</div>
        <div class="muted" style="font-size:12px; margin-top:6px;">
          <?= $hasQuery ? '検索ワードを短くするか、名前・特徴・メモの別表現で試してください。' : 'まだ顧客データがないか、表示条件に合うデータがありません。'; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php render_page_end(); ?>
