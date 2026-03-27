<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/attendance.php';
require_once __DIR__ . '/../app/repo_casts.php';
require_once __DIR__ . '/../app/service_transport.php';
require_once __DIR__ . '/../app/store.php';

require_login();
require_role(['admin', 'manager', 'super_user', ROLE_ALL_STORE_SHIFT_VIEW]);

if (!function_exists('h')) {
  function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
  }
}

function shift_plan_format_hm(?string $value, string $fallback = '—'): string {
  $value = trim((string)$value);
  if ($value === '') return $fallback;
  if (strlen($value) >= 16) return substr($value, 11, 5);
  if (strlen($value) >= 5) return substr($value, 0, 5);
  return $value;
}

function shift_plan_read_end_from_note(?string $note): string {
  $note = (string)($note ?? '');
  if (preg_match('/#end=(\d{2}:\d{2}|LAST)\b/u', $note, $m)) {
    return strtoupper((string)$m[1]);
  }
  return 'LAST';
}

function shift_plan_sort_shop_tag(string $value): string {
  $value = trim($value);
  if ($value === '') return '999999:';
  if (ctype_digit($value)) return sprintf('%06d:', (int)$value);
  return '999999:' . $value;
}

function shift_plan_note_text(?string $note): string {
  $note = trim((string)($note ?? ''));
  if ($note === '') return '';

  $note = preg_replace('/(^|\s)#end=(\d{2}:\d{2}|LAST)\b/u', ' ', $note);
  $note = preg_replace('/(^|\s)#douhan\b/u', ' 同伴', $note);
  $note = preg_replace('/\s+/u', ' ', (string)$note);
  return trim((string)$note);
}

function shift_plan_douhan_text(array $row): string {
  $rawNote = trim((string)($row['plan_note'] ?? ''));
  $noteText = trim((string)($row['note_text'] ?? ''));
  if ($rawNote !== '' && preg_match('/(^|\s)#douhan\b/u', $rawNote)) {
    return 'あり';
  }
  if ($noteText !== '' && mb_strpos($noteText, '同伴') !== false) {
    return 'あり';
  }
  return '—';
}

function shift_plan_transport_text(array $row): string {
  $pickupTarget = trim((string)($row['pickup_target'] ?? ''));
  $pickupEnabledRaw = $row['pickup_enabled'] ?? null;
  $hasTransportProfile = (int)($row['has_transport_profile'] ?? 0) === 1;

  if (!$hasTransportProfile && $pickupEnabledRaw === null && $pickupTarget === '') {
    return '未設定';
  }

  $pickupEnabled = is_numeric($pickupEnabledRaw) ? (int)$pickupEnabledRaw : 1;
  if ($pickupTarget === 'self') {
    return '自走';
  }
  if ($pickupEnabled !== 1) {
    return '送迎対象外';
  }

  return transport_pickup_target_label($pickupTarget !== '' ? $pickupTarget : 'primary', true);
}

function shift_plan_state(array $row, string $targetDate, string $currentBusinessDate, DateTimeImmutable $now): array {
  $clockIn = trim((string)($row['clock_in'] ?? ''));
  $clockOut = trim((string)($row['clock_out'] ?? ''));
  $attendanceStatus = trim((string)($row['attendance_status'] ?? ''));
  $startHm = shift_plan_format_hm((string)($row['start_time'] ?? ''), '');

  if ($attendanceStatus === 'absent') {
    return ['code' => 'absent', 'label' => '欠勤', 'class' => 'bad'];
  }
  if ($clockOut !== '') {
    return ['code' => 'finished', 'label' => '退勤済', 'class' => 'ok'];
  }
  if ($clockIn !== '') {
    return ['code' => 'working', 'label' => '出勤中', 'class' => 'primary'];
  }

  $isMissing = false;
  if ($targetDate < $currentBusinessDate) {
    $isMissing = true;
  } elseif ($targetDate === $currentBusinessDate && $startHm !== '') {
    $planAt = DateTimeImmutable::createFromFormat(
      'Y-m-d H:i',
      $targetDate . ' ' . $startHm,
      new DateTimeZone('Asia/Tokyo')
    );
    if ($planAt instanceof DateTimeImmutable && $now > $planAt) {
      $isMissing = true;
    }
  }

  if ($isMissing) {
    return ['code' => 'missing', 'label' => '未打刻', 'class' => 'warn'];
  }

  return ['code' => 'planned', 'label' => '予定のみ', 'class' => 'muted'];
}

function shift_plan_store_meta(PDO $pdo, array $allowedStores): array {
  $ids = [];
  foreach ($allowedStores as $store) {
    $sid = (int)($store['id'] ?? 0);
    if ($sid > 0) $ids[] = $sid;
  }
  $ids = array_values(array_unique($ids));
  if ($ids === []) return [];

  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $st = $pdo->prepare("
    SELECT id, name, business_day_start
    FROM stores
    WHERE is_active = 1
      AND id IN ({$placeholders})
    ORDER BY id ASC
  ");
  $st->execute($ids);

  $meta = [];
  foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
    $sid = (int)($row['id'] ?? 0);
    if ($sid > 0) $meta[$sid] = $row;
  }
  return $meta;
}

function shift_plan_tab_counts(PDO $pdo, array $storeIds, string $date): array {
  if ($storeIds === []) return [];

  $placeholders = implode(',', array_fill(0, count($storeIds), '?'));
  $params = array_merge([$date], $storeIds);
  $st = $pdo->prepare("
    SELECT store_id, COUNT(*) AS planned_count
    FROM cast_shift_plans
    WHERE business_date = ?
      AND status = 'planned'
      AND is_off = 0
      AND store_id IN ({$placeholders})
    GROUP BY store_id
  ");
  $st->execute($params);

  $counts = [];
  foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
    $counts[(int)($row['store_id'] ?? 0)] = (int)($row['planned_count'] ?? 0);
  }
  return $counts;
}

function shift_plan_allowed_stores(PDO $pdo): array {
  $userId = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
  $canViewAll = function_exists('can_view_all_store_shift') && can_view_all_store_shift();

  if ($canViewAll) {
    $st = $pdo->query("
      SELECT id, name
      FROM stores
      WHERE is_active = 1
      ORDER BY id ASC
    ");
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  return repo_allowed_stores($pdo, $userId, false);
}

function shift_plan_resolve_store_id(array $stores, int $requestedStoreId): int {
  if ($stores === []) {
    throw new RuntimeException('閲覧できる店舗がありません');
  }

  $allowed = [];
  foreach ($stores as $store) {
    $sid = (int)($store['id'] ?? 0);
    if ($sid > 0) $allowed[$sid] = true;
  }

  if ($requestedStoreId > 0 && isset($allowed[$requestedStoreId])) {
    set_current_store_id($requestedStoreId);
    return $requestedStoreId;
  }

  $currentStoreId = function_exists('get_current_store_id') ? (int)get_current_store_id() : 0;
  if ($currentStoreId > 0 && isset($allowed[$currentStoreId])) {
    return $currentStoreId;
  }

  $firstStoreId = (int)($stores[0]['id'] ?? 0);
  if ($firstStoreId <= 0) {
    throw new RuntimeException('有効な店舗がありません');
  }

  set_current_store_id($firstStoreId);
  return $firstStoreId;
}

function shift_plan_rows(PDO $pdo, int $storeId, string $date): array {
  $hasUserShopTag = att_has_column($pdo, 'users', 'shop_tag');
  $hasStoreUsersStaffCode = att_has_column($pdo, 'store_users', 'staff_code');
  $hasCastProfilesShopTag = att_has_column($pdo, 'cast_profiles', 'shop_tag');
  $hasCastProfilesStoreId = att_has_column($pdo, 'cast_profiles', 'store_id');

  $shopParts = [];
  if ($hasStoreUsersStaffCode) {
    $shopParts[] = "(
      SELECT NULLIF(TRIM(su.staff_code), '')
      FROM store_users su
      WHERE su.user_id = sp.user_id
        AND su.store_id = sp.store_id
      ORDER BY su.id DESC
      LIMIT 1
    )";
  }
  if ($hasCastProfilesShopTag) {
    if ($hasCastProfilesStoreId) {
      $shopParts[] = "(
        SELECT NULLIF(TRIM(cp.shop_tag), '')
        FROM cast_profiles cp
        WHERE cp.user_id = sp.user_id
          AND (cp.store_id = sp.store_id OR cp.store_id IS NULL)
        ORDER BY
          CASE WHEN cp.store_id = sp.store_id THEN 0 ELSE 1 END ASC,
          cp.store_id DESC,
          cp.user_id DESC
        LIMIT 1
      )";
    } else {
      $shopParts[] = "(
        SELECT NULLIF(TRIM(cp.shop_tag), '')
        FROM cast_profiles cp
        WHERE cp.user_id = sp.user_id
        ORDER BY cp.user_id DESC
        LIMIT 1
      )";
    }
  }
  if ($hasUserShopTag) {
    $shopParts[] = "NULLIF(TRIM(u.shop_tag), '')";
  }
  $shopExpr = $shopParts ? "COALESCE(" . implode(', ', $shopParts) . ", '')" : "''";

  $sql = "
    SELECT
      sp.user_id,
      sp.start_time,
      sp.note AS plan_note,
      COALESCE(u.display_name, '') AS display_name,
      {$shopExpr} AS shop_tag,
      (
        SELECT MAX(a.clock_in)
        FROM attendances a
        WHERE a.store_id = sp.store_id
          AND a.user_id = sp.user_id
          AND a.business_date = sp.business_date
      ) AS clock_in,
      (
        SELECT MAX(a.clock_out)
        FROM attendances a
        WHERE a.store_id = sp.store_id
          AND a.user_id = sp.user_id
          AND a.business_date = sp.business_date
      ) AS clock_out,
      (
        SELECT SUBSTRING_INDEX(
          GROUP_CONCAT(COALESCE(a.status, '') ORDER BY a.id DESC SEPARATOR ','),
          ',',
          1
        )
        FROM attendances a
        WHERE a.store_id = sp.store_id
          AND a.user_id = sp.user_id
          AND a.business_date = sp.business_date
      ) AS attendance_status,
      (
        SELECT 1
        FROM cast_transport_profiles ctp
        WHERE ctp.store_id = sp.store_id
          AND ctp.user_id = sp.user_id
        LIMIT 1
      ) AS has_transport_profile,
      (
        SELECT ctp.pickup_enabled
        FROM cast_transport_profiles ctp
        WHERE ctp.store_id = sp.store_id
          AND ctp.user_id = sp.user_id
        LIMIT 1
      ) AS pickup_enabled,
      (
        SELECT ctp.pickup_target
        FROM cast_transport_profiles ctp
        WHERE ctp.store_id = sp.store_id
          AND ctp.user_id = sp.user_id
        LIMIT 1
      ) AS pickup_target
    FROM cast_shift_plans sp
    LEFT JOIN users u
      ON u.id = sp.user_id
    WHERE sp.store_id = ?
      AND sp.business_date = ?
      AND sp.status = 'planned'
      AND sp.is_off = 0
  ";

  $st = $pdo->prepare($sql);
  $st->execute([$storeId, $date]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  usort($rows, static function(array $a, array $b): int {
    $aStart = shift_plan_format_hm((string)($a['start_time'] ?? ''), '');
    $bStart = shift_plan_format_hm((string)($b['start_time'] ?? ''), '');
    if ($aStart !== $bStart) {
      if ($aStart === '') return 1;
      if ($bStart === '') return -1;
      return strcmp($aStart, $bStart);
    }

    $aShop = shift_plan_sort_shop_tag((string)($a['shop_tag'] ?? ''));
    $bShop = shift_plan_sort_shop_tag((string)($b['shop_tag'] ?? ''));
    if ($aShop !== $bShop) return strcmp($aShop, $bShop);

    $aName = trim((string)($a['display_name'] ?? ''));
    $bName = trim((string)($b['display_name'] ?? ''));
    if ($aName !== $bName) return strcmp($aName, $bName);

    return ((int)($a['user_id'] ?? 0)) <=> ((int)($b['user_id'] ?? 0));
  });

  return $rows;
}

$pdo = db();
$stores = shift_plan_allowed_stores($pdo);
if ($stores === []) {
  http_response_code(403);
  exit('閲覧できる店舗がありません');
}

$storeMeta = shift_plan_store_meta($pdo, $stores);
$requestedStoreId = (int)($_GET['store_id'] ?? 0);
$storeId = shift_plan_resolve_store_id($stores, $requestedStoreId);
$selectedStore = $storeMeta[$storeId] ?? null;
if (!is_array($selectedStore)) {
  http_response_code(404);
  exit('店舗が見つかりません');
}

$defaultDate = business_date_for_store($selectedStore, null);
$targetDate = (string)($_GET['date'] ?? $defaultDate);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
  $targetDate = $defaultDate;
}

$allowedStoreIds = array_map(static fn(array $store): int => (int)($store['id'] ?? 0), $stores);
$tabCounts = shift_plan_tab_counts($pdo, array_values(array_filter($allowedStoreIds)), $targetDate);
$rows = shift_plan_rows($pdo, $storeId, $targetDate);

$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
$currentBusinessDate = business_date_for_store($selectedStore, null);

$summary = [
  'planned' => count($rows),
  'working' => 0,
  'finished' => 0,
  'missing' => 0,
  'planned_only' => 0,
  'absent' => 0,
];

foreach ($rows as &$row) {
  $row['start_hm'] = shift_plan_format_hm((string)($row['start_time'] ?? ''));
  $row['end_hm'] = shift_plan_read_end_from_note((string)($row['plan_note'] ?? ''));
  $row['note_text'] = shift_plan_note_text((string)($row['plan_note'] ?? ''));
  $row['transport_text'] = shift_plan_transport_text($row);
  $row['douhan_text'] = shift_plan_douhan_text($row);
  $row['state'] = shift_plan_state($row, $targetDate, $currentBusinessDate, $now);
  $summaryKey = match ((string)$row['state']['code']) {
    'working' => 'working',
    'finished' => 'finished',
    'missing' => 'missing',
    'absent' => 'absent',
    default => 'planned_only',
  };
  $summary[$summaryKey]++;
}
unset($row);

$prevDate = (new DateTimeImmutable($targetDate, new DateTimeZone('Asia/Tokyo')))->modify('-1 day')->format('Y-m-d');
$nextDate = (new DateTimeImmutable($targetDate, new DateTimeZone('Asia/Tokyo')))->modify('+1 day')->format('Y-m-d');
$todayDate = $defaultDate;
$yesterdayDate = (new DateTimeImmutable($todayDate, new DateTimeZone('Asia/Tokyo')))->modify('-1 day')->format('Y-m-d');
$tomorrowDate = (new DateTimeImmutable($todayDate, new DateTimeZone('Asia/Tokyo')))->modify('+1 day')->format('Y-m-d');

$pageTitle = '全店出勤予定ビュー';
$dashboardUrl = '/wbss/public/dashboard.php?store_id=' . $storeId;
$attendanceUrl = '/wbss/public/attendance/index.php?store_id=' . $storeId . '&date=' . urlencode($targetDate);
$managerTodayUrl = '/wbss/public/manager_today_schedule.php?store_id=' . $storeId . '&business_date=' . urlencode($targetDate);
$storeCastsUrl = '/wbss/public/store_casts.php?store_id=' . $storeId;
$showAllStoreStatus = function_exists('is_role') && (is_role('admin') || is_role('super_user'));

$headerActions = [];
if ($showAllStoreStatus) {
  $headerActions[] = '<a class="btn" href="/wbss/public/all_store_status.php">全店統合ビュー</a>';
}
$headerActions[] = '<a class="btn" href="' . h($dashboardUrl) . '">ダッシュボード</a>';

render_page_start($pageTitle);
render_header($pageTitle, [
  'back_href' => '/wbss/public/dashboard.php',
  'back_label' => '← ダッシュボード',
  'right_html' => implode(' ', $headerActions),
]);
?>
<style>
.shift-plans-shell{ display:grid; gap:16px; }
.shift-hero,
.shift-card{
  border:1px solid var(--line);
  background:var(--cardA);
  border-radius:18px;
  box-shadow:var(--shadow);
}
.shift-hero{ padding:18px; display:grid; gap:14px; }
.shift-title{ display:grid; gap:6px; }
.shift-title h1{ margin:0; font-size:24px; line-height:1.2; }
.shift-lead{ margin:0; opacity:.78; }
.shift-tools{
  display:flex;
  flex-wrap:wrap;
  gap:10px;
  align-items:center;
  justify-content:space-between;
}
.tab-strip{
  display:flex;
  gap:10px;
  overflow-x:auto;
  padding-bottom:2px;
}
.tab-strip::-webkit-scrollbar{ height:8px; }
.tab-strip::-webkit-scrollbar-thumb{
  background:rgba(148,163,184,.35);
  border-radius:999px;
}
.store-tab{
  min-width:max-content;
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding:10px 14px;
  border-radius:999px;
  border:1px solid var(--line);
  background:var(--cardB);
  color:inherit;
  text-decoration:none;
  font-weight:700;
}
.store-tab.active{
  border-color:rgba(59,130,246,.45);
  background:rgba(59,130,246,.16);
}
.store-tab__count{
  min-width:1.8em;
  padding:2px 8px;
  border-radius:999px;
  background:rgba(15,23,42,.16);
  font-size:12px;
  text-align:center;
}
.date-row{
  display:flex;
  flex-wrap:wrap;
  gap:10px;
  align-items:center;
  justify-content:space-between;
}
.date-nav,
.quick-links{ display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
.btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:8px;
  min-height:42px;
  padding:10px 14px;
  border-radius:12px;
  border:1px solid var(--line);
  background:var(--cardA);
  color:inherit;
  text-decoration:none;
}
.date-nav .date-btn{
  min-width:74px;
  font-weight:700;
}
.date-nav .date-btn.is-active{
  border-color:color-mix(in srgb, var(--accent) 72%, var(--line)) !important;
  background:var(--accent) !important;
  color:#08111f !important;
  font-weight:800;
  box-shadow:
    inset 0 0 0 1px rgba(255,255,255,.18),
    0 0 0 3px color-mix(in srgb, var(--accent) 22%, transparent),
    0 10px 18px rgba(0,0,0,.16);
}
body[data-theme="dark"] .date-nav .date-btn.is-active{
  color:#08111f !important;
}
body[data-theme="light"] .date-nav .date-btn.is-active{
  background:#2563eb !important;
  border-color:#1d4ed8 !important;
  color:#fff !important;
}
body[data-theme="staff"] .date-nav .date-btn.is-active{
  background:#2b9af3 !important;
  border-color:#1f7fcc !important;
  color:#fff !important;
}
body[data-theme="cast"] .date-nav .date-btn.is-active{
  background:#ff6fa3 !important;
  border-color:#e9568f !important;
  color:#fff !important;
}
.summary-grid{
  display:grid;
  grid-template-columns:repeat(5, minmax(120px, 1fr));
  gap:10px;
}
.summary-pill{
  border:1px solid var(--line);
  background:var(--cardB);
  border-radius:16px;
  padding:12px 14px;
}
.summary-pill__label{ font-size:12px; opacity:.72; }
.summary-pill__value{ margin-top:4px; font-size:22px; font-weight:800; }
.shift-card{ padding:16px; }
.card-head{
  display:flex;
  flex-wrap:wrap;
  gap:10px;
  align-items:flex-end;
  justify-content:space-between;
  margin-bottom:14px;
}
.card-head h2{ margin:0; font-size:20px; }
.card-head p{ margin:4px 0 0; opacity:.75; }
.plan-table{
  width:100%;
  border-collapse:collapse;
}
.plan-table th,
.plan-table td{
  padding:12px 10px;
  border-bottom:1px solid var(--line);
  vertical-align:middle;
  text-align:left;
}
.plan-table th{ font-size:12px; opacity:.72; }
.cast-meta{
  display:flex;
  flex-wrap:wrap;
  gap:8px;
  align-items:center;
}
.shop-chip{
  display:inline-flex;
  align-items:center;
  padding:3px 8px;
  border-radius:999px;
  border:1px solid var(--line);
  background:var(--cardB);
  font-size:12px;
}
.status-badge{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  padding:5px 10px;
  border-radius:999px;
  border:1px solid transparent;
  font-size:12px;
  font-weight:700;
}
.status-badge.primary{
  color:#1d4ed8;
  background:rgba(59,130,246,.14);
  border-color:rgba(59,130,246,.28);
}
.status-badge.ok{
  color:#166534;
  background:rgba(34,197,94,.14);
  border-color:rgba(34,197,94,.26);
}
.status-badge.warn{
  color:#b45309;
  background:rgba(245,158,11,.18);
  border-color:rgba(245,158,11,.26);
}
.status-badge.bad{
  color:#b91c1c;
  background:rgba(239,68,68,.12);
  border-color:rgba(239,68,68,.22);
}
.status-badge.muted{
  color:inherit;
  background:rgba(148,163,184,.12);
  border-color:rgba(148,163,184,.2);
}
.note{
  white-space:pre-wrap;
  font-size:12px;
  opacity:.82;
}
.empty-state{
  border:1px dashed var(--line);
  background:var(--cardB);
  border-radius:16px;
  padding:26px 18px;
  text-align:center;
  opacity:.82;
}
.plan-cards{ display:none; gap:12px; }
.plan-card{
  border:1px solid var(--line);
  background:var(--cardB);
  border-radius:16px;
  padding:14px;
  display:grid;
  gap:10px;
}
.plan-card__head{
  display:flex;
  gap:10px;
  align-items:flex-start;
  justify-content:space-between;
}
.plan-card__name{ font-size:17px; font-weight:800; line-height:1.2; }
.plan-card__grid{
  display:grid;
  grid-template-columns:repeat(2, minmax(0, 1fr));
  gap:10px;
}
.plan-card__item{ display:grid; gap:4px; }
.plan-card__label{ font-size:12px; opacity:.7; }
.mono{ font-variant-numeric:tabular-nums; }
@media (max-width: 900px){
  .summary-grid{ grid-template-columns:repeat(2, minmax(120px, 1fr)); }
}
@media (max-width: 720px){
  .plan-table{ display:none; }
  .plan-cards{ display:grid; }
  .shift-title h1{ font-size:21px; }
}
</style>

<div class="page">
  <div class="shift-plans-shell">
    <section class="shift-hero">
      <div class="shift-title">
        <h1>全店出勤予定ビュー</h1>
        <p class="shift-lead">各店舗のキャスト出勤予定をタブで切り替えて確認します。予定の正本は `cast_shift_plans`、状態表示は `attendances` を補助参照しています。</p>
      </div>

      <div class="tab-strip" aria-label="店舗タブ">
        <?php foreach ($stores as $store): ?>
          <?php
            $sid = (int)($store['id'] ?? 0);
            if ($sid <= 0) continue;
            $href = '/wbss/public/all_store_shift_plans.php?store_id=' . $sid . '&date=' . urlencode($targetDate);
            $isActive = ($sid === $storeId);
          ?>
          <a class="store-tab <?= $isActive ? 'active' : '' ?>" href="<?= h($href) ?>">
            <span><?= h((string)($store['name'] ?? ('#' . $sid))) ?></span>
            <span class="store-tab__count"><?= (int)($tabCounts[$sid] ?? 0) ?></span>
          </a>
        <?php endforeach; ?>
      </div>

      <div class="date-row">
        <div class="date-nav">
          <a class="btn date-btn <?= $targetDate === $yesterdayDate ? 'is-active' : '' ?>" href="/wbss/public/all_store_shift_plans.php?store_id=<?= $storeId ?>&date=<?= h($prevDate) ?>">前日</a>
          <a class="btn date-btn <?= $targetDate === $todayDate ? 'is-active' : '' ?>" href="/wbss/public/all_store_shift_plans.php?store_id=<?= $storeId ?>&date=<?= h($todayDate) ?>">今日</a>
          <a class="btn date-btn <?= $targetDate === $tomorrowDate ? 'is-active' : '' ?>" href="/wbss/public/all_store_shift_plans.php?store_id=<?= $storeId ?>&date=<?= h($nextDate) ?>">翌日</a>
        </div>

        <div class="quick-links">
          <a class="btn" href="<?= h($attendanceUrl) ?>">この店舗の出勤一覧</a>
          <a class="btn" href="<?= h($managerTodayUrl) ?>">この店舗の勤務予定</a>
          <a class="btn" href="<?= h($storeCastsUrl) ?>">店別キャスト管理</a>
        </div>
      </div>

      <div class="summary-grid">
        <div class="summary-pill">
          <div class="summary-pill__label">表示店舗</div>
          <div class="summary-pill__value"><?= h((string)($selectedStore['name'] ?? ('#' . $storeId))) ?></div>
        </div>
        <div class="summary-pill">
          <div class="summary-pill__label">営業日</div>
          <div class="summary-pill__value mono"><?= h($targetDate) ?></div>
        </div>
        <div class="summary-pill">
          <div class="summary-pill__label">予定人数</div>
          <div class="summary-pill__value"><?= (int)$summary['planned'] ?></div>
        </div>
        <div class="summary-pill">
          <div class="summary-pill__label">出勤中 / 退勤済</div>
          <div class="summary-pill__value"><?= (int)$summary['working'] ?> / <?= (int)$summary['finished'] ?></div>
        </div>
        <div class="summary-pill">
          <div class="summary-pill__label">未打刻</div>
          <div class="summary-pill__value"><?= (int)$summary['missing'] ?></div>
        </div>
      </div>
    </section>

    <section class="shift-card">
      <div class="card-head">
        <div>
          <h2><?= h((string)($selectedStore['name'] ?? ('#' . $storeId))) ?> の出勤予定</h2>
          <p>予定時刻順で表示しています。未打刻は、対象営業日が現在営業日以前かつ予定時刻を過ぎている場合に表示します。</p>
        </div>
      </div>

      <?php if ($rows === []): ?>
        <div class="empty-state">この日の出勤予定はありません。</div>
      <?php else: ?>
        <table class="plan-table">
          <thead>
            <tr>
              <th>キャスト名</th>
              <th>出勤予定</th>
              <th>状態</th>
              <th>出勤手段</th>
              <th>同伴</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <?php
                $name = trim((string)($row['display_name'] ?? ''));
                if ($name === '') $name = '#' . (int)($row['user_id'] ?? 0);
                $shopTag = trim((string)($row['shop_tag'] ?? ''));
              ?>
              <tr>
                <td>
                  <div class="cast-meta">
                    <?php if ($shopTag !== ''): ?>
                      <span class="shop-chip">店番 <?= h($shopTag) ?></span>
                    <?php endif; ?>
                    <strong><?= h($name) ?></strong>
                  </div>
                </td>
                <td class="mono"><?= h((string)($row['start_hm'] ?? '—')) ?></td>
                <td><span class="status-badge <?= h((string)($row['state']['class'] ?? 'muted')) ?>"><?= h((string)($row['state']['label'] ?? '予定のみ')) ?></span></td>
                <td><?= h((string)($row['transport_text'] ?? '未設定')) ?></td>
                <td><?= h((string)($row['douhan_text'] ?? '—')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <div class="plan-cards">
          <?php foreach ($rows as $row): ?>
            <?php
              $name = trim((string)($row['display_name'] ?? ''));
              if ($name === '') $name = '#' . (int)($row['user_id'] ?? 0);
              $shopTag = trim((string)($row['shop_tag'] ?? ''));
            ?>
            <article class="plan-card">
              <div class="plan-card__head">
                <div>
                  <div class="plan-card__name"><?= h($name) ?></div>
                  <?php if ($shopTag !== ''): ?>
                    <div class="note">店番 <?= h($shopTag) ?></div>
                  <?php endif; ?>
                </div>
                <span class="status-badge <?= h((string)($row['state']['class'] ?? 'muted')) ?>"><?= h((string)($row['state']['label'] ?? '予定のみ')) ?></span>
              </div>
              <div class="plan-card__grid">
                <div class="plan-card__item">
                  <div class="plan-card__label">出勤予定</div>
                  <div class="mono"><?= h((string)($row['start_hm'] ?? '—')) ?></div>
                </div>
                <div class="plan-card__item">
                  <div class="plan-card__label">状態</div>
                  <div><span class="status-badge <?= h((string)($row['state']['class'] ?? 'muted')) ?>"><?= h((string)($row['state']['label'] ?? '予定のみ')) ?></span></div>
                </div>
                <div class="plan-card__item">
                  <div class="plan-card__label">出勤手段</div>
                  <div><?= h((string)($row['transport_text'] ?? '未設定')) ?></div>
                </div>
                <div class="plan-card__item">
                  <div class="plan-card__label">同伴</div>
                  <div><?= h((string)($row['douhan_text'] ?? '—')) ?></div>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>
</div>

<?php render_page_end(); ?>
