<?php
declare(strict_types=1);

/* =====================================
   requires
===================================== */
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';

require_login();
// cast も通す（← ここが 403 の原因だった）
require_role(['cast','admin','manager','super_user']);

$pdo = db();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* =====================================
   helpers
===================================== */
function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function has_role(string $role): bool {
  return isset($_SESSION['roles']) && in_array($role, $_SESSION['roles'], true);
}
function current_user_id_safe(): int {
  return function_exists('current_user_id')
    ? (int)current_user_id()
    : (int)($_SESSION['user_id'] ?? 0);
}
function business_date_jst(string $start='05:00'): string {
  $tz = new DateTimeZone('Asia/Tokyo');
  $now = new DateTime('now', $tz);
  $d = $now->format('Y-m-d');
  $startDt = new DateTime($d.' '.$start.':00', $tz);
  if ($now < $startDt) $now->modify('-1 day');
  return $now->format('Y-m-d');
}

/* =====================================
   role / user
===================================== */
$userId   = current_user_id_safe();
$isSuper  = has_role('super_user');
$isAdmin  = has_role('admin');
$isMgr    = has_role('manager');
$isCast   = has_role('cast');
$isStaff  = ($isSuper || $isAdmin || $isMgr);

/* =====================================
   store resolve
===================================== */
$storeId = 0;

// staff
if ($isStaff) {
  if ($isSuper) {
    $storeId = (int)($_GET['store_id'] ?? 0);
    if ($storeId <= 0) {
      $storeId = (int)$pdo
        ->query("SELECT id FROM stores WHERE is_active=1 ORDER BY id ASC LIMIT 1")
        ->fetchColumn();
    }
  } else {
    $st = $pdo->prepare("
      SELECT ur.store_id
      FROM user_roles ur
      JOIN roles r ON r.id=ur.role_id AND r.code IN ('admin','manager')
      WHERE ur.user_id=?
        AND ur.store_id IS NOT NULL
      ORDER BY ur.store_id ASC
      LIMIT 1
    ");
    $st->execute([$userId]);
    $storeId = (int)$st->fetchColumn();
  }
}

// cast
if (!$storeId && $isCast) {
  $st = $pdo->prepare("
    SELECT store_id
    FROM cast_profiles
    WHERE user_id=?
    LIMIT 1
  ");
  $st->execute([$userId]);
  $storeId = (int)$st->fetchColumn();

  if ($storeId <= 0) {
    $st = $pdo->prepare("
      SELECT ur.store_id
      FROM user_roles ur
      JOIN roles r ON r.id=ur.role_id AND r.code='cast'
      WHERE ur.user_id=?
      LIMIT 1
    ");
    $st->execute([$userId]);
    $storeId = (int)$st->fetchColumn();
  }
}

if ($storeId <= 0) {
  http_response_code(403);
  exit('store not resolved');
}

/* =====================================
   date / store
===================================== */
$bizDate = business_date_jst('05:00');

$st = $pdo->prepare("SELECT name FROM stores WHERE id=?");
$st->execute([$storeId]);
$storeName = (string)$st->fetchColumn();

/* =====================================
   load attendance
===================================== */
$sql = "
  SELECT
    u.id AS user_id,
    u.display_name,
    u.is_active,
    a.clock_in,
    a.clock_out,
    a.status,
    a.source_in,
    a.source_out,
    CASE WHEN ui.id IS NULL THEN 0 ELSE 1 END AS has_line
  FROM user_roles ur
  JOIN roles r ON r.id=ur.role_id AND r.code='cast'
  JOIN users u ON u.id=ur.user_id
  LEFT JOIN attendances a
    ON a.user_id=u.id
   AND a.store_id=ur.store_id
   AND a.business_date=?
  LEFT JOIN user_identities ui
    ON ui.user_id=u.id AND ui.provider='line' AND ui.is_active=1
  WHERE ur.store_id=?
";

if ($isCast) {
  // 🔹 cast は自分を最上段
  $sql .= "
    ORDER BY
      CASE WHEN u.id = ? THEN 0 ELSE 1 END,
      (a.clock_in IS NULL) ASC,
      a.clock_in ASC,
      u.id ASC
  ";
} else {
  $sql .= "
    ORDER BY
      (a.clock_in IS NULL) ASC,
      a.clock_in ASC,
      u.id ASC
  ";
}

$st = $pdo->prepare($sql);
$isCast
  ? $st->execute([$bizDate, $storeId, $userId])
  : $st->execute([$bizDate, $storeId]);

$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* =====================================
   render
===================================== */
render_page_start('本日の出勤');
render_header('本日の出勤',[
  'back_href' => $isCast
    ? '/seika-app/public/dashboard_cast.php'
    : '/seika-app/public/dashboard.php',
  'back_label' => '← ダッシュボード',
]);
?>
<div class="page"><div class="admin-wrap">

  <div class="topRow">
    <div class="title">📍 本日の出勤</div>
    <div class="muted">
      店舗：<b><?= h($storeName) ?></b> / 営業日：<b><?= h($bizDate) ?></b>
    </div>
  </div>

  <table class="tbl">
    <thead>
      <tr>
        <th>キャスト</th>
        <th>出勤</th>
        <th>退勤</th>
        <th>LINE</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <?php
          $isMe = ($r['user_id'] == $userId);
          $rowCls = $isMe ? 'me' : '';
        ?>
        <tr class="<?= $rowCls ?>">
          <td>
            <b><?= h($r['display_name']) ?></b>
            <?php if ($isMe): ?>
              <span class="badge me">あなた</span>
            <?php endif; ?>
          </td>
          <td><?= $r['clock_in'] ? substr($r['clock_in'],11,5) : '--:--' ?></td>
          <td><?= $r['clock_out'] ? substr($r['clock_out'],11,5) : '--:--' ?></td>
          <td><?= $r['has_line'] ? '✅' : '—' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

</div></div>

<style>
.topRow{display:flex;justify-content:space-between;gap:12px;align-items:flex-end;flex-wrap:wrap}
.title{font-weight:1000;font-size:20px}
.tbl{width:100%;border-collapse:collapse;margin-top:12px}
.tbl th,.tbl td{padding:10px;border-bottom:1px solid rgba(255,255,255,.08)}
.tbl tr.me{background:rgba(59,130,246,.12)}
.badge.me{
  margin-left:6px;
  font-size:11px;
  padding:2px 6px;
  border-radius:999px;
  background:rgba(59,130,246,.25);
}
.muted{opacity:.75;font-size:12px}
</style>

<?php render_page_end(); ?>