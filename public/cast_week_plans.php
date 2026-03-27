<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/service_transport.php';

/**
 * Shift planning weekly screen.
 *
 * Canonical rules:
 * - plan source of truth: cast_shift_plans
 * - actual source of truth: attendances
 * - cast_week_plans is not updated here
 */

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
function has_role(string $role): bool { return isset($_SESSION['roles']) && in_array($role, $_SESSION['roles'], true); }
function current_user_id_safe(): int {
  return function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
}
function jst_now(): DateTime { return new DateTime('now', new DateTimeZone('Asia/Tokyo')); }
function ymd(DateTime $d): string { return $d->format('Y-m-d'); }
function dow0(string $ymd): int { return (int)(new DateTime($ymd, new DateTimeZone('Asia/Tokyo')))->format('w'); } // 0=Sun..6=Sat
function jp_dow_label(string $ymd): string {
  static $jp = ['日','月','火','水','木','金','土'];
  return $jp[dow0($ymd)] ?? '';
}
function normalize_date(?string $ymd, ?string $fallback=null): string {
  $s = trim((string)$ymd);
  if ($s === '' && $fallback !== null) $s = $fallback;
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return ymd(new DateTime('today', new DateTimeZone('Asia/Tokyo')));
  return $s;
}
function week_start_date(string $anyDateYmd, int $weekStartDow0): string {
  $dt = new DateTime($anyDateYmd, new DateTimeZone('Asia/Tokyo'));
  $curDow = (int)$dt->format('w');
  $diff = ($curDow - $weekStartDow0 + 7) % 7;
  if ($diff > 0) $dt->modify("-{$diff} days");
  return $dt->format('Y-m-d');
}
function week_dates(string $weekStartYmd): array {
  $dt = new DateTime($weekStartYmd, new DateTimeZone('Asia/Tokyo'));
  $out = [];
  for ($i=0; $i<7; $i++) { $out[] = $dt->format('Y-m-d'); $dt->modify('+1 day'); }
  return $out;
}
function hm_from_time_value(?string $value, string $fallback): string {
  $value = trim((string)$value);
  if ($value === '') return $fallback;
  if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)) return substr($value, 0, 5);
  if (preg_match('/^\d{2}:\d{2}$/', $value)) return $value;
  return $fallback;
}
function is_weekend_business_date(string $ymd, int $weekendDowMask): bool {
  $dowBit = 1 << dow0($ymd); // Sun=1, Mon=2 ... Fri=32, Sat=64
  return (($weekendDowMask & $dowBit) === $dowBit);
}
function store_close_rule_for_date(array $storeRow, string $ymd): array {
  $isWeekend = is_weekend_business_date($ymd, (int)($storeRow['weekend_dow_mask'] ?? 96));
  return store_close_rule_by_kind($storeRow, $isWeekend ? 'weekend' : 'weekday');
}
function store_close_rule_by_kind(array $storeRow, string $kind): array {
  if ($kind === 'weekend') {
    return [
      'kind' => 'weekend',
      'close_hm' => hm_from_time_value((string)($storeRow['close_time_weekend'] ?? ''), '03:00'),
      'is_next_day' => (int)($storeRow['close_is_next_day_weekend'] ?? 1) === 1,
    ];
  }
  return [
    'kind' => 'weekday',
    'close_hm' => hm_from_time_value((string)($storeRow['close_time_weekday'] ?? ''), '02:30'),
    'is_next_day' => (int)($storeRow['close_is_next_day_weekday'] ?? 1) === 1,
  ];
}
function close_rule_label(array $rule): string {
  return ($rule['is_next_day'] ? '翌' : '当日') . $rule['close_hm'];
}
function hm_to_minutes(string $hm): int {
  [$hh, $mm] = array_map('intval', explode(':', $hm));
  return ($hh * 60) + $mm;
}
function build_time_options(string $startHm, string $endHm, bool $endNextDay, int $stepMinutes = 15): array {
  $start = hm_to_minutes($startHm);
  $end = hm_to_minutes($endHm);
  if ($endNextDay || $end < $start) $end += 1440;
  $out = [];
  for ($m = $start; $m <= $end; $m += $stepMinutes) {
    $cur = $m % 1440;
    $out[] = sprintf('%02d:%02d', intdiv($cur, 60), $cur % 60);
  }
  return array_values(array_unique($out));
}
function build_start_time_options(array $storeRow): array {
  $openHm = hm_from_time_value((string)($storeRow['open_time'] ?? ''), '20:00');
  $openMinutes = hm_to_minutes($openHm);
  $endMinutes = min($openMinutes + (5 * 60), (23 * 60) + 30);
  $out = [];
  for ($m = $openMinutes; $m <= $endMinutes; $m += 15) {
    $out[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
  }
  return array_values(array_unique($out));
}
function store_closed_dows(PDO $pdo, int $storeId): array {
  static $cache = [];
  if (isset($cache[$storeId])) return $cache[$storeId];

  $dows = [];
  try {
    $st = $pdo->prepare("SELECT dow FROM store_weekly_closed_days WHERE store_id=?");
    $st->execute([$storeId]);
    foreach (($st->fetchAll(PDO::FETCH_COLUMN) ?: []) as $value) {
      $dow = (int)$value;
      if ($dow >= 1 && $dow <= 7) {
        $dows[] = $dow;
      }
    }
  } catch (Throwable $e) {
    // テーブル未導入環境でも落とさない
  }

  return $cache[$storeId] = array_values(array_unique($dows));
}
function store_override_open(PDO $pdo, int $storeId, string $ymd): ?bool {
  try {
    $st = $pdo->prepare("SELECT is_open FROM store_closures WHERE store_id=? AND closed_date=? LIMIT 1");
    $st->execute([$storeId, $ymd]);
    $value = $st->fetchColumn();
    if ($value === false) return null;
    return ((int)$value === 1);
  } catch (Throwable $e) {
    return null;
  }
}
function is_store_open_for_week_plan(PDO $pdo, array $storeRow, string $ymd): bool {
  $storeId = (int)($storeRow['id'] ?? 0);
  if ($storeId <= 0) return true;

  $override = store_override_open($pdo, $storeId, $ymd);
  if ($override !== null) {
    return $override;
  }

  $closedDows = store_closed_dows($pdo, $storeId);
  if ($closedDows !== []) {
    $dow = (int)(new DateTime($ymd, new DateTimeZone('Asia/Tokyo')))->format('N');
    return !in_array($dow, $closedDows, true);
  }

  $holidayDow = $storeRow['weekly_holiday_dow'] ?? null;
  if ($holidayDow !== null && (int)$holidayDow === dow0($ymd)) {
    return false;
  }

  return true;
}

/* =========================
  role / store resolve
========================= */
$userId   = current_user_id_safe();
$isSuper  = has_role('super_user');
$isStaff  = $isSuper || has_role('admin') || has_role('manager');
$isCast   = has_role('cast');
$castOnly = (!$isStaff && $isCast);

function current_staff_store_id(PDO $pdo, int $userId): int {
  $st = $pdo->prepare("
    SELECT ur.store_id
    FROM user_roles ur
    JOIN roles r ON r.id=ur.role_id
    WHERE ur.user_id=?
      AND r.code IN ('admin','manager')
      AND ur.store_id IS NOT NULL
    ORDER BY ur.store_id ASC
    LIMIT 1
  ");
  $st->execute([$userId]);
  return (int)($st->fetchColumn() ?: 0);
}
function current_cast_store_id(PDO $pdo, int $userId): int {
  $st = $pdo->prepare("SELECT store_id FROM cast_profiles WHERE user_id=? LIMIT 1");
  $st->execute([$userId]);
  $sid = (int)($st->fetchColumn() ?: 0);
  if ($sid > 0) return $sid;

  $st = $pdo->prepare("
    SELECT ur.store_id
    FROM user_roles ur
    JOIN roles r ON r.id=ur.role_id AND r.code='cast'
    WHERE ur.user_id=? AND ur.store_id IS NOT NULL
    ORDER BY ur.store_id ASC
    LIMIT 1
  ");
  $st->execute([$userId]);
  return (int)($st->fetchColumn() ?: 0);
}

$storeId = 0;
if ($isSuper) {
  $storeId = (int)($_GET['store_id'] ?? ($_POST['store_id'] ?? 0));
  if ($storeId <= 0) {
    $storeId = (int)$pdo->query("SELECT id FROM stores WHERE is_active=1 ORDER BY id ASC LIMIT 1")->fetchColumn();
  }
} elseif ($isStaff) {
  $storeId = current_staff_store_id($pdo, $userId);
} else {
  $storeId = current_cast_store_id($pdo, $userId);
}
if ($storeId <= 0) { http_response_code(400); exit('店舗が特定できません'); }

$st = $pdo->prepare("
  SELECT
    id,
    name,
    business_day_start,
    weekly_holiday_dow,
    open_time,
    close_time_weekday,
    close_time_weekend,
    close_is_next_day_weekday,
    close_is_next_day_weekend,
    weekend_dow_mask
  FROM stores
  WHERE id=?
  LIMIT 1
");
$st->execute([$storeId]);
$storeRow = $st->fetch(PDO::FETCH_ASSOC);
if (!$storeRow) { http_response_code(404); exit('店舗が見つかりません'); }
$openTimeHm = hm_from_time_value((string)($storeRow['open_time'] ?? ''), '20:00');

$holidayDow = $storeRow['weekly_holiday_dow'];
$holidayDow = ($holidayDow === null) ? null : (int)$holidayDow;

/* 星華：日曜店休(=0)なら日曜列を非表示 */
$hideHolidayColumn = ($holidayDow === 0);

/* =========================
  week
========================= */
$weekStartDow0 = ($holidayDow === null) ? 1 : (($holidayDow + 1) % 7); // 店休日の翌日を週開始に寄せる
$baseDate  = normalize_date((string)($_GET['date'] ?? $_POST['date'] ?? ''), ymd(jst_now()));
$weekStart = week_start_date($baseDate, $weekStartDow0);
$datesAll  = week_dates($weekStart);

$dates = [];
foreach ($datesAll as $d) {
  if (!is_store_open_for_week_plan($pdo, $storeRow, $d)) continue;
  $dates[] = $d;
}

/* =========================
  store list (super)
========================= */
$stores = [];
if ($isSuper) {
  $stores = $pdo->query("SELECT id,name,is_active FROM stores ORDER BY is_active DESC, id ASC")
    ->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* =========================
  cast list
========================= */
$transportPickupTargetSelect = (function_exists('transport_profile_has_pickup_target_field') && transport_profile_has_pickup_target_field($pdo))
  ? "ctp.pickup_target"
  : "'primary' AS pickup_target";
$st = $pdo->prepare("
  SELECT
    u.id,
    u.display_name,
    u.is_active,

    -- 店番：store_users.staff_code 優先（なければ cast_profiles.shop_tag）
    COALESCE(
      NULLIF(su.staff_code, _utf8mb4'' COLLATE utf8mb4_bin),
      NULLIF(cp.shop_tag,   _utf8mb4'' COLLATE utf8mb4_bin),
      ''
    ) AS staff_code,

    COALESCE(
      NULLIF(su.employment_type, _utf8mb4'' COLLATE utf8mb4_bin),
      cp.employment_type,
      'part'
    ) AS employment_type,

    ctp.pickup_enabled,
    {$transportPickupTargetSelect},
    CASE WHEN ctp.user_id IS NULL THEN 0 ELSE 1 END AS has_transport_profile,

    -- セルのデフォルト開始（あれば優先）
    cp.default_start_time

  FROM user_roles ur
  JOIN roles r ON r.id=ur.role_id AND r.code='cast'
  JOIN users u ON u.id=ur.user_id

  LEFT JOIN store_users su
    ON su.store_id = ur.store_id AND su.user_id = u.id

  LEFT JOIN cast_profiles cp ON cp.user_id = u.id

  LEFT JOIN cast_transport_profiles ctp
    ON ctp.store_id = ur.store_id AND ctp.user_id = u.id

  WHERE ur.store_id = ?
    -- 退店キャストは除外（store_usersが無い古い人は通す）
    AND (su.status IS NULL OR su.status = 'active')
    AND COALESCE(
      NULLIF(su.staff_code, _utf8mb4'' COLLATE utf8mb4_bin),
      NULLIF(cp.shop_tag, _utf8mb4'' COLLATE utf8mb4_bin),
      _utf8mb4'' COLLATE utf8mb4_bin
    ) <> _utf8mb4'' COLLATE utf8mb4_bin

ORDER BY
  -- 空は最後
  CASE
    WHEN staff_code IS NULL OR staff_code = _utf8mb4'' COLLATE utf8mb4_bin THEN 2
    ELSE 0
  END,
  -- 数字っぽい店番を先に（CASTして 0 になるものは後ろへ）
  CASE
    WHEN CAST(staff_code AS UNSIGNED) > 0 OR staff_code = _utf8mb4'0' COLLATE utf8mb4_bin THEN 0
    ELSE 1
  END,
  CAST(staff_code AS UNSIGNED),
  staff_code,
  u.display_name,
  u.id
");
$st->execute([$storeId]);
$castRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* =========================
  load plans from canonical table
  - source of truth: cast_shift_plans
  - note uses #douhan / #end=HH:MM
  - off is stored as is_off=1
  - LAST is represented by missing #end tag
========================= */
$plans = []; // [uid][ymd] => ['start'=>'HH:MM','end'=>'LAST|HH:MM','douhan'=>bool]
if ($dates) {
  $minD = $dates[0];
  $maxD = $dates[count($dates)-1];

  $st = $pdo->prepare("
    SELECT user_id, business_date, start_time, is_off, note
    FROM cast_shift_plans
    WHERE store_id=?
      AND business_date BETWEEN ? AND ?
      AND status='planned'
  ");
  $st->execute([$storeId, $minD, $maxD]);

  foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
    $uid = (int)$r['user_id'];
    $d   = (string)$r['business_date'];

    $note = (string)($r['note'] ?? '');
    $douhan = (strpos($note, '#douhan') !== false);

    // end は note から読む（#end=HH:MM or #end=LAST）
    $end = 'LAST';
    if (preg_match('/#end=(\d{2}:\d{2}|LAST)\b/u', $note, $m)) {
      $end = strtoupper((string)$m[1]);
    }

    $off = ((int)$r['is_off'] === 1);
    $start = (!$off && $r['start_time'] !== null) ? substr((string)$r['start_time'], 0, 5) : '';

    $plans[$uid][$d] = [
      'start' => $start,
      'end'   => $end,
      'douhan'=> $douhan,
    ];
  }
}

$castRowMap = [];
foreach ($castRows as $castRow) {
  $castRowMap[(int)$castRow['id']] = $castRow;
}

$currentBusinessDate = function_exists('business_date_for_store')
  ? business_date_for_store($storeRow, null)
  : ymd(jst_now());
$isQuickBusinessDateOpen = is_store_open_for_week_plan($pdo, $storeRow, $currentBusinessDate);
$quickDateLabel = substr($currentBusinessDate, 5) . '（' . jp_dow_label($currentBusinessDate) . '）';
$viewMode = ((string)($_GET['mode'] ?? '') === 'quick') ? 'quick' : 'week';

if (!isset($plans[(int)($castRows[0]['id'] ?? 0)][$currentBusinessDate]) && $castRows !== []) {
  $st = $pdo->prepare("
    SELECT user_id, business_date, start_time, is_off, note
    FROM cast_shift_plans
    WHERE store_id=?
      AND business_date=?
      AND status='planned'
  ");
  $st->execute([$storeId, $currentBusinessDate]);
  foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
    $uid = (int)$r['user_id'];
    $d = (string)$r['business_date'];
    $note = (string)($r['note'] ?? '');
    $douhan = (strpos($note, '#douhan') !== false);
    $end = 'LAST';
    if (preg_match('/#end=(\d{2}:\d{2}|LAST)\b/u', $note, $m)) {
      $end = strtoupper((string)$m[1]);
    }
    $off = ((int)$r['is_off'] === 1);
    $start = (!$off && $r['start_time'] !== null) ? substr((string)$r['start_time'], 0, 5) : '';
    $plans[$uid][$d] = [
      'start' => $start,
      'end' => $end,
      'douhan' => $douhan,
    ];
  }
}


/* =========================
  POST save
========================= */
$err = '';
$ok  = !empty($_SESSION['flash_cast_week_plans_saved']);
unset($_SESSION['flash_cast_week_plans_saved']);

function normalize_time_hm(?string $hm): ?string {
  $hm = trim((string)$hm);
  if ($hm === '' || $hm === '--') return null;
  if (!preg_match('/^\d{2}:\d{2}$/', $hm)) return null;
  return $hm . ':00';
}
function note_from_flags(bool $douhan, string $endHm): string {
  $parts = [];
  if ($douhan) $parts[] = '#douhan';

  $endHm = trim($endHm);
  // LAST は保存しない（デフォルト扱い）
  if ($endHm !== '' && strtoupper($endHm) !== 'LAST' && preg_match('/^\d{2}:\d{2}$/', $endHm)) {
    $parts[] = '#end=' . $endHm;
  }
  return implode(' ', $parts);
}

function json_response(array $payload, int $status = 200): never {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function transport_needed_state(array $castRow): bool {
  $pickupEnabled = array_key_exists('pickup_enabled', $castRow) && $castRow['pickup_enabled'] !== null
    ? (int)$castRow['pickup_enabled']
    : 1;
  $pickupTarget = trim((string)($castRow['pickup_target'] ?? 'primary'));
  if (function_exists('transport_pickup_target_requires_pickup')) {
    return transport_pickup_target_requires_pickup($pickupTarget !== '' ? $pickupTarget : 'primary', $pickupEnabled);
  }
  return $pickupEnabled === 1 && $pickupTarget !== 'self';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if ((string)($_POST['action'] ?? '') === 'toggle_today_quick') {
    if (function_exists('csrf_verify')) {
      csrf_verify((string)($_POST['_csrf'] ?? ''));
    }

    $targetUserId = (int)($_POST['target_user_id'] ?? 0);
    $toggleKind = (string)($_POST['toggle_kind'] ?? '');
    if ($targetUserId <= 0 || !isset($castRowMap[$targetUserId])) {
      json_response(['ok' => false, 'error' => '対象キャストが見つかりません'], 404);
    }
    if ($castOnly && $targetUserId !== $userId) {
      json_response(['ok' => false, 'error' => '自分以外は変更できません'], 403);
    }
    if (!$isQuickBusinessDateOpen) {
      json_response(['ok' => false, 'error' => '本日は店休日のため特急モードは使えません'], 400);
    }

    $castRow = $castRowMap[$targetUserId];

    try {
      if ($toggleKind === 'shift') {
        $currentPlan = $plans[$targetUserId][$currentBusinessDate] ?? ['start'=>'','end'=>'LAST','douhan'=>false];
        $isCurrentlyWorking = trim((string)($currentPlan['start'] ?? '')) !== '';
        $nextWorking = !$isCurrentlyWorking;
        $defaultStart = trim((string)($castRow['default_start_time'] ?? ''));
        $startHm = preg_match('/^\d{2}:\d{2}/', $defaultStart)
          ? substr($defaultStart, 0, 5)
          : $openTimeHm;
        $startTime = $nextWorking ? normalize_time_hm($startHm) : null;

        $upPlan = $pdo->prepare("
          INSERT INTO cast_shift_plans
            (store_id, user_id, business_date, start_time, is_off, status, note, created_by_user_id)
          VALUES
            (?, ?, ?, ?, ?, 'planned', ?, ?)
          ON DUPLICATE KEY UPDATE
            start_time=VALUES(start_time),
            is_off=VALUES(is_off),
            status='planned',
            note=VALUES(note),
            created_by_user_id=VALUES(created_by_user_id),
            updated_at=NOW()
        ");
        $note = $nextWorking ? note_from_flags(false, 'LAST') : '';
        $upPlan->execute([
          $storeId,
          $targetUserId,
          $currentBusinessDate,
          $startTime,
          $nextWorking ? 0 : 1,
          $note,
          $userId ?: null,
        ]);

        json_response([
          'ok' => true,
          'kind' => 'shift',
          'is_working' => $nextWorking,
          'label' => $nextWorking ? '出勤' : '休み',
          'start' => $nextWorking ? $startHm : '',
          'message' => $nextWorking ? '出勤にしました' : '休みにしました',
        ]);
      }

      if ($toggleKind === 'transport') {
        $currentlyNeeded = transport_needed_state($castRow);
        $nextNeeded = !$currentlyNeeded;
        $currentTarget = trim((string)($castRow['pickup_target'] ?? 'primary'));
        if ($currentTarget === '') $currentTarget = 'primary';
        if ($nextNeeded && $currentTarget === 'self') {
          $currentTarget = 'primary';
        }

        $hasPickupTarget = function_exists('transport_profile_has_pickup_target_field') && transport_profile_has_pickup_target_field($pdo);
        $insertCols = [
          'store_id',
          'user_id',
          'pickup_enabled',
          'privacy_level',
          'created_by_user_id',
          'updated_by_user_id',
        ];
        $insertVals = [
          ':store_id',
          ':user_id',
          ':pickup_enabled',
          ':privacy_level',
          ':created_by_user_id',
          ':updated_by_user_id',
        ];
        $updateCols = [
          'pickup_enabled = VALUES(pickup_enabled)',
          'privacy_level = VALUES(privacy_level)',
          'updated_by_user_id = VALUES(updated_by_user_id)',
          'updated_at = NOW()',
        ];
        if ($hasPickupTarget) {
          $insertCols[] = 'pickup_target';
          $insertVals[] = ':pickup_target';
          $updateCols[] = 'pickup_target = VALUES(pickup_target)';
        }
        $sql = "
          INSERT INTO cast_transport_profiles (
            " . implode(", ", $insertCols) . "
          ) VALUES (
            " . implode(", ", $insertVals) . "
          )
          ON DUPLICATE KEY UPDATE
            " . implode(", ", $updateCols) . "
        ";
        $params = [
          ':store_id' => $storeId,
          ':user_id' => $targetUserId,
          ':pickup_enabled' => $nextNeeded ? 1 : 0,
          ':privacy_level' => 'manager_only',
          ':created_by_user_id' => $userId ?: null,
          ':updated_by_user_id' => $userId ?: null,
        ];
        if ($hasPickupTarget) {
          $params[':pickup_target'] = $currentTarget;
        }
        $pdo->prepare($sql)->execute($params);

        json_response([
          'ok' => true,
          'kind' => 'transport',
          'transport_needed' => $nextNeeded,
          'label' => $nextNeeded ? '送迎あり' : '送迎なし',
          'message' => $nextNeeded ? '送迎ありにしました' : '送迎なしにしました',
        ]);
      }

      json_response(['ok' => false, 'error' => '不明な操作です'], 400);
    } catch (Throwable $e) {
      json_response(['ok' => false, 'error' => $e->getMessage()], 500);
    }
  }

  try {
    if ($isSuper) $storeId = (int)($_POST['store_id'] ?? $storeId);

    $weekStart = normalize_date((string)($_POST['week_start'] ?? $weekStart), $weekStart);
    $datesAll  = week_dates($weekStart);
    $dates = [];
    foreach ($datesAll as $d) {
      if (!is_store_open_for_week_plan($pdo, $storeRow, $d)) continue;
      $dates[] = $d;
    }

    $pdo->beginTransaction();

    // cast_profiles（形態/基本開始）: castOnly は自分だけ
    $upProf = $pdo->prepare("
      INSERT INTO cast_profiles (user_id, store_id, employment_type, default_start_time, updated_at)
      VALUES (?, ?, ?, ?, NOW())
      ON DUPLICATE KEY UPDATE
        store_id=VALUES(store_id),
        employment_type=VALUES(employment_type),
        default_start_time=VALUES(default_start_time),
        updated_at=NOW()
    ");

    foreach ($castRows as $c) {
      $uid = (int)$c['id'];
      if ($castOnly && $uid !== $userId) continue;

      $etype = (string)($_POST["etype_{$uid}"] ?? 'part');
      if (!in_array($etype, ['regular','part'], true)) $etype = 'part';

      $dst = trim((string)($_POST["dst_{$uid}"] ?? ''));
      $currentDst = trim((string)($c['default_start_time'] ?? ''));
      $dstTime = null;
      if ($dst !== '' && preg_match('/^\d{2}:\d{2}$/', $dst)) {
        // 店舗オープン時間を表示上の基準にしつつ、未設定の人を自動で固定保存しない。
        if ($dst === $openTimeHm && $currentDst === '') {
          $dstTime = null;
        } else {
          $dstTime = $dst . ':00';
        }
      }

      $upProf->execute([$uid, $storeId, $etype, $dstTime]);
    }

    // Canonical plan table.
    // end_time column does not exist, so end is stored in note as #end=HH:MM.
    $upPlan = $pdo->prepare("
      INSERT INTO cast_shift_plans
        (store_id, user_id, business_date, start_time, is_off, status, note, created_by_user_id)
      VALUES
        (?, ?, ?, ?, ?, 'planned', ?, ?)
      ON DUPLICATE KEY UPDATE
        start_time=VALUES(start_time),
        is_off=VALUES(is_off),
        status='planned',
        note=VALUES(note),
        created_by_user_id=VALUES(created_by_user_id),
        updated_at=NOW()
    ");

    foreach ($castRows as $c) {
      $uid = (int)$c['id'];
      if ($castOnly && $uid !== $userId) continue;

      foreach ($dates as $d) {
        $off    = (int)($_POST["off_{$uid}_{$d}"] ?? 0) === 1;
        $douhan = (int)($_POST["douhan_{$uid}_{$d}"] ?? 0) === 1;

        $startHm = trim((string)($_POST["start_{$uid}_{$d}"] ?? ''));
        $endHm   = trim((string)($_POST["end_{$uid}_{$d}"] ?? 'LAST'));

        $startTime = normalize_time_hm($startHm); // 'HH:MM:00' or null

        if ($off) {
          // OFFはレコードを残して is_off=1（連動のため）
          $note = note_from_flags(false, 'LAST'); // OFFはnote不要
          $upPlan->execute([$storeId, $uid, $d, null, 1, $note, $userId ?: null]);
          continue;
        }

        if ($startTime === null) {
          // Treat empty start as off and keep the state in the canonical plan table.
          $note = note_from_flags(false, 'LAST');
          $upPlan->execute([$storeId, $uid, $d, null, 1, $note, $userId ?: null]);
          continue;
        }

        $note = note_from_flags($douhan, $endHm);
        $upPlan->execute([$storeId, $uid, $d, $startTime, 0, $note, $userId ?: null]);
      }
    }

    $pdo->commit();
    $_SESSION['flash_cast_week_plans_saved'] = 1;
    header('Location: /wbss/public/cast_week_plans.php?store_id='.$storeId.'&date='.urlencode($weekStart));
    exit;

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $err = $e->getMessage();
  }
}

/* =========================
  UI settings
========================= */
$timeOptions = build_start_time_options($storeRow);
$closeRuleWeekday = store_close_rule_by_kind($storeRow, 'weekday');
$closeRuleWeekend = store_close_rule_by_kind($storeRow, 'weekend');
$lastSummaryText = '平日' . close_rule_label($closeRuleWeekday) . ' / 週末' . close_rule_label($closeRuleWeekend);
$endOptionsByDate = [];
foreach ($dates as $d) {
  $rule = store_close_rule_for_date($storeRow, $d);
  $endOptionsByDate[$d] = build_time_options($openTimeHm, (string)$rule['close_hm'], (bool)$rule['is_next_day']);
}

render_page_start('出勤予定（週）');
render_header('出勤予定（週）', [
  'back_href'  => $castOnly ? '/wbss/public/dashboard_cast.php' : '/wbss/public/dashboard.php',
  'back_label' => '← 戻る',
]);
?>
<div class="page">
  <div class="admin-wrap">

    <?php if ($ok): ?>
      <div class="notice ok">✅ 保存しました</div>
    <?php endif; ?>
    <?php if ($err): ?>
      <div class="notice ng"><?= h($err) ?></div>
    <?php endif; ?>

    <div class="card head">
      <div class="headRow">
        <div class="headMain">
          <div class="eyebrow">Weekly Shift Planner</div>
          <div class="ttl">出勤予定（週）</div>
          <div class="sub">
            店舗：<b><?= h((string)$storeRow['name']) ?></b>
            <?php if (count($dates) < count($datesAll)): ?>
              / 店休日の列は非表示
            <?php endif; ?>
            <?php if ($castOnly): ?>
              / <b>※自分の行だけ編集</b>
            <?php endif; ?>
            / 保存先：<b>cast_shift_plans</b>
          </div>
          <div class="headMeta">
            <div class="metaCard">
              <span class="metaLabel">対象週</span>
              <strong><?= h(substr($weekStart,5)) ?>（<?= h(jp_dow_label($weekStart)) ?>）〜 <?= h(substr($datesAll[6],5)) ?>（<?= h(jp_dow_label($datesAll[6])) ?>）</strong>
            </div>
            <div class="metaCard">
              <span class="metaLabel">表示列</span>
              <strong><?= count($dates) ?>日分 / <?= count($castRows) ?>名</strong>
            </div>
          </div>
          <div class="chips">
            <span class="chip">休み：ボタンON/OFF</span>
            <span class="chip">同伴：ボタンON/OFF</span>
            <span class="chip">開始：開店 <?= h($openTimeHm) ?> ベース</span>
            <span class="chip">終了：基本LAST</span>
            <span class="chip">メモ：非表示</span>
          </div>
        </div>

        <form method="get" class="ctrl">
          <?php if ($isSuper): ?>
            <select name="store_id" class="sel">
              <?php foreach ($stores as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id']===(int)$storeId)?'selected':'' ?>>
                  <?= h((string)$s['name']) ?><?= ((int)$s['is_active']===1)?'':'（無効）' ?>
                </option>
              <?php endforeach; ?>
            </select>
          <?php else: ?>
            <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
          <?php endif; ?>

          <div class="dateBox">
            <div class="muted">基準日</div>
            <input type="date" name="date" value="<?= h($baseDate) ?>" class="sel">
          </div>

          <button class="btn primary" type="submit">表示</button>
        </form>
      </div>

      <div class="weekLine">
        <div class="weekLineItem"><b>週：</b><?= h(substr($weekStart,5)) ?>（<?= h(jp_dow_label($weekStart)) ?>）〜 <?= h(substr($datesAll[6],5)) ?>（<?= h(jp_dow_label($datesAll[6])) ?>）</div>
        <div class="weekLineItem"><b>操作：</b>左でキャスト確認、右で各曜日の出勤/同伴/時間をまとめて編集</div>
      </div>
    </div>

    <div class="modeSwitch" role="tablist" aria-label="表示モード">
      <button type="button" class="modeSwitchBtn <?= $viewMode === 'quick' ? 'is-active' : '' ?>" data-mode-switch="quick">今日の特急</button>
      <button type="button" class="modeSwitchBtn <?= $viewMode === 'week' ? 'is-active' : '' ?>" data-mode-switch="week">通常の週表示</button>
    </div>

    <section class="card quickModeCard<?= $viewMode === 'quick' ? ' is-active' : '' ?>" id="quickModeSection">
      <div class="quickModeHead">
        <div>
          <div class="quickModeEyebrow">Quick Attendance Mode</div>
          <div class="quickModeTitle">今日の特急モード</div>
          <div class="quickModeSub">
            対象営業日：<b><?= h($quickDateLabel) ?></b>
            <?php if (!$isQuickBusinessDateOpen): ?>
              / 本日は店休日
            <?php else: ?>
              / 名前と店番をタップして出勤切替
            <?php endif; ?>
          </div>
        </div>
        <div class="quickModeLegend">
          <span class="quickLegend is-work">出勤</span>
          <span class="quickLegend is-off">休み</span>
          <span class="quickLegend is-transport">送迎あり</span>
        </div>
      </div>

      <div class="quickStats">
        <?php
          $quickWorkingCount = 0;
          $quickTransportCount = 0;
          foreach ($castRows as $quickCast) {
            $quickUid = (int)$quickCast['id'];
            $quickPlan = $plans[$quickUid][$currentBusinessDate] ?? ['start'=>'','end'=>'LAST','douhan'=>false];
            if (trim((string)($quickPlan['start'] ?? '')) !== '') {
              $quickWorkingCount++;
            }
            if (transport_needed_state($quickCast)) {
              $quickTransportCount++;
            }
          }
        ?>
        <div class="quickStatCard">
          <span>出勤予定</span>
          <b id="quickWorkingCount"><?= (int)$quickWorkingCount ?></b>
        </div>
        <div class="quickStatCard">
          <span>送迎あり</span>
          <b id="quickTransportCount"><?= (int)$quickTransportCount ?></b>
        </div>
        <div class="quickStatCard">
          <span>対象人数</span>
          <b><?= count($castRows) ?></b>
        </div>
      </div>

      <?php if (!$isQuickBusinessDateOpen): ?>
        <div class="notice ng">本日は店休日設定のため、特急モードは編集できません。</div>
      <?php endif; ?>

      <div class="quickGrid">
        <?php foreach ($castRows as $quickCast): ?>
          <?php
            $quickUid = (int)$quickCast['id'];
            $quickPlan = $plans[$quickUid][$currentBusinessDate] ?? ['start'=>'','end'=>'LAST','douhan'=>false];
            $quickWorking = trim((string)($quickPlan['start'] ?? '')) !== '';
            $quickTransportNeeded = transport_needed_state($quickCast);
            $quickReadonly = ($castOnly && $quickUid !== $userId) || !$isQuickBusinessDateOpen;
            $quickCode = trim((string)($quickCast['staff_code'] ?? ''));
          ?>
          <article
            class="quickCastCard<?= $quickWorking ? ' is-working' : ' is-off' ?><?= $quickTransportNeeded ? ' needs-transport' : '' ?><?= $quickReadonly ? ' is-readonly' : '' ?>"
            data-quick-card
            data-uid="<?= (int)$quickUid ?>"
            data-working="<?= $quickWorking ? '1' : '0' ?>"
            data-transport="<?= $quickTransportNeeded ? '1' : '0' ?>"
          >
            <button
              type="button"
              class="quickMainBtn"
              data-quick-toggle="shift"
              <?= $quickReadonly ? 'disabled' : '' ?>
            >
              <span class="quickCode"><?= h($quickCode !== '' ? $quickCode : '--') ?></span>
              <span class="quickName"><?= h((string)$quickCast['display_name']) ?></span>
              <span class="quickState" data-quick-shift-label><?= $quickWorking ? '出勤' : '休み' ?></span>
            </button>
            <button
              type="button"
              class="quickTransportBtn<?= $quickTransportNeeded ? ' is-on' : '' ?>"
              data-quick-toggle="transport"
              <?= $quickReadonly ? 'disabled' : '' ?>
            >
              <span>送迎</span>
              <strong data-quick-transport-label><?= $quickTransportNeeded ? 'あり' : 'なし' ?></strong>
            </button>
          </article>
        <?php endforeach; ?>
      </div>
    </section>

    <div class="weeklyModeWrap<?= $viewMode === 'week' ? ' is-active' : '' ?>" id="weeklyModeSection">
    <form method="post">
      <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
      <input type="hidden" name="week_start" value="<?= h($weekStart) ?>">

      <!-- 日付ヘッダ（横スクロールなし：auto-fit で折り返し） -->
      <div class="dateHeader">
        <div class="dateHeaderLeft">キャスト</div>
        <div class="dateHeaderGrid" style="--days: <?= count($dates) ?>;">
          <?php foreach ($dates as $d): ?>
            <?php
              $dayWorkingCount = 0;
              $dayDouhanCount = 0;
              foreach ($castRows as $headerCast) {
                $headerUid = (int)$headerCast['id'];
                $headerPlan = $plans[$headerUid][$d] ?? ['start'=>'','end'=>'LAST','douhan'=>false];
                if ((string)$headerPlan['start'] !== '') {
                  $dayWorkingCount++;
                  if (!empty($headerPlan['douhan'])) {
                    $dayDouhanCount++;
                  }
                }
              }
            ?>
            <div class="dhCell">
              <div class="dhTop"><?= h(substr($d,5)) ?></div>
              <div class="dhSub"><?= h(jp_dow_label($d)) ?></div>
              <div class="dhMeta">
                <div class="dhMetric">
                  <span class="dhMetaLabel">出勤予定</span>
                  <strong class="js-day-total" data-date="<?= h($d) ?>"><?= (int)$dayWorkingCount ?></strong>
                </div>
                <div class="dhMetric dhMetricWarm">
                  <span class="dhMetaLabel">同伴</span>
                  <strong class="js-day-douhan-total" data-date="<?= h($d) ?>"><?= (int)$dayDouhanCount ?></strong>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <?php foreach ($castRows as $c): ?>
        <?php
          $uid = (int)$c['id'];
          $inactive = ((int)$c['is_active'] !== 1);
          $etype = (string)($c['employment_type'] ?? 'part');
          $dst = $c['default_start_time'] ? substr((string)$c['default_start_time'], 0, 5) : '';
          $dstDisplay = ($dst !== '') ? $dst : $openTimeHm;
          $readonlyRow = ($castOnly && $uid !== $userId);
          $workingCount = 0;
          $douhanCount = 0;
          foreach ($dates as $d) {
            $summaryPlan = $plans[$uid][$d] ?? ['start'=>'','end'=>'LAST','douhan'=>false];
            if ((string)$summaryPlan['start'] !== '') {
              $workingCount++;
              if (!empty($summaryPlan['douhan'])) {
                $douhanCount++;
              }
            }
          }
        ?>
        <div class="rowCard <?= $readonlyRow ? 'rowRO' : '' ?>">
          <div class="left">
              <?php
              $code = trim((string)($c['staff_code'] ?? ''));
              ?>
              <div class="castName">
                <span class="castCode"><?= h($code !== '' ? $code : '--') ?></span>
                <span class="castDisplay"><?= h((string)$c['display_name']) ?></span>
                <?php if ($uid === $userId): ?>
                  <span class="tag me">自分</span>
                <?php endif; ?>

                <?php if ($inactive): ?>
                  <span class="tag ng">無効</span>
                <?php endif; ?>
              </div>
	              <div class="castMeta">
	                <span class="castMetaBadge <?= $etype === 'regular' ? 'isRegular' : 'isPart' ?>">
	                  <?= $etype === 'regular' ? 'レギュラー' : 'スポット' ?>
	                </span>
                <span class="castMetaText">基本 <?= h($dstDisplay) ?> 出勤</span>
              </div>
              <div class="castBaseStart">
                <label class="castBaseStartLabel">基本開始</label>
                <input
                  class="sel mini castBaseStartInput"
                  type="time"
                  step="60"
                  name="dst_<?= (int)$uid ?>"
                  value="<?= h($dstDisplay) ?>"
                  <?= $readonlyRow ? 'disabled' : '' ?>
                >
              </div>
	              <div class="castStats">
                <span class="statPill">
                  <span class="statLabel">出勤</span>
                  <strong><?= (int)$workingCount ?>日</strong>
                </span>
                <span class="statPill isWarm">
                  <span class="statLabel">同伴</span>
                  <strong><?= (int)$douhanCount ?>件</strong>
                </span>
              </div>
              <?php if (($c['employment_type'] ?? '') === 'regular'): ?>
                <button type="button" class="btnMini" onclick="weekRegularOn(<?= (int)$uid ?>)">
                  週を出勤
                </button>
              <?php endif; ?>

          </div>

          <div class="grid" style="--days: <?= count($dates) ?>;">
            <?php foreach ($dates as $d): ?>
              <?php
                $p = $plans[$uid][$d] ?? ['start'=>'','end'=>'LAST','douhan'=>false];
                $start = (string)$p['start'];
                $end   = (string)$p['end'];
                $douhan= (bool)$p['douhan'];
                $closeRule = store_close_rule_for_date($storeRow, $d);
                $dateStartOptions = $timeOptions;
                if ($start !== '' && !in_array($start, $dateStartOptions, true)) {
                  $dateStartOptions[] = $start;
                }
                $dateEndOptions = $endOptionsByDate[$d] ?? [];
                if ($end !== '' && $end !== 'LAST' && !in_array($end, $dateEndOptions, true)) {
                  $dateEndOptions[] = $end;
                }

                // 休み判定：start空＝休み（DBにレコードが無い＝休みも同じ）
                $isOff = ($start === '');

                $nameStart = "start_{$uid}_{$d}";
                $nameEnd   = "end_{$uid}_{$d}";
                $nameOff   = "off_{$uid}_{$d}";
                $nameDou   = "douhan_{$uid}_{$d}";
              ?>
              <div class="cell"
                data-uid="<?= (int)$uid ?>"
                data-date="<?= h($d) ?>"
                data-default-start="<?= h($dstDisplay) ?>"
                data-readonly="<?= $readonlyRow ? '1' : '0' ?>">
                <input type="hidden" name="<?= h($nameOff) ?>" value="<?= $isOff ? '1' : '0' ?>" class="hidOff">
                <input type="hidden" name="<?= h($nameDou) ?>" value="<?= $douhan ? '1' : '0' ?>" class="hidDou">

                <div class="toggles">
                  <button type="button" class="tgl tglOff <?= $isOff?'on':'' ?>" <?= $readonlyRow?'disabled':'' ?>>
                    <?= $isOff ? '休み' : '出勤' ?>
                  </button>

                  <!-- 同伴：押せない問題を潰す（disabledを「休み or readonly」だけで決める） -->
                  <button type="button" class="tgl tglDou <?= $douhan?'on':'' ?>"
                          <?= ($readonlyRow || $isOff)?'disabled':'' ?>>
                    同伴
                  </button>
                </div>

                <div class="times">
                  <select class="sel mini startSel" name="<?= h($nameStart) ?>" <?= ($readonlyRow || $isOff)?'disabled':'' ?>>
                    <option value="">--</option>
                    <?php foreach ($dateStartOptions as $opt): ?>
                      <option value="<?= h($opt) ?>" <?= ($opt===$start)?'selected':'' ?>><?= h($opt) ?></option>
                    <?php endforeach; ?>
                  </select>

                  <select class="sel mini endSel" name="<?= h($nameEnd) ?>" <?= ($readonlyRow || $isOff)?'disabled':'' ?>>
                    <option value="LAST" <?= ('LAST'===$end)?'selected':'' ?>>LAST</option>
                    <?php foreach ($dateEndOptions as $opt): ?>
                      <option value="<?= h($opt) ?>" <?= ($opt===$end)?'selected':'' ?>><?= h($opt) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>

      <div class="foot">
        <button class="btn primary" type="submit">保存</button>
      </div>
    </form>
    </div>

  </div>
</div>

<style>
/* =========================================================
  cast_week_plans 見やすさ改善（Atlasでも崩れにくい版）
  - 左: キャスト欄固定
  - 上: 曜日ヘッダ固定
  - Dark時の境界/コントラストを強化
========================================================= */

/* ---------- base tokens (Light default) ---------- */
:root{
  --bg:   #f3f6fb;
  --card: #ffffff;
  --chip: #f8fafc;
  --panel: #eef4ff;

  --line:  #e2e8f0;
  --line2: #cbd5e1;

  --txt:  #0f172a;
  --mut:  #475569;

  --pri:  #2563eb;
  --priBg:#dbeafe;

  --ok:   #16a34a;
  --okBg: #dcfce7;

  --ng:   #ef4444;
  --ngBg: #fee2e2;

  --shadow: 0 10px 22px rgba(15,23,42,.08);

  --r: 16px;
  --gap: 10px;
  --tap: 36px;
}

/* body側で確実に反映（Atlasで安全） */
body{
  color-scheme: light;
  background: var(--bg);
  color: var(--txt);
}

/* App Theme: Dark（標準） */
body[data-theme="dark"]{
  color-scheme: dark;

  --bg:   #070c16;     /* 背景は暗めに固定 */
  --card: #0f172a;     /* カード */
  --chip: #111c33;     /* ヘッダ/左固定に使う */
  --panel:#16233f;

  --line:  #22314f;    /* 枠 */
  --line2: #32466f;    /* 強め枠 */

  --txt:  #eaf0ff;
  --mut:  #b3c0dd;

  --pri:  #7bb1ff;
  --priBg: rgba(123,177,255,.18);

  --ok:   #35d46a;
  --okBg: rgba(53,212,106,.20);

  --ng:   #ff6b7a;
  --ngBg: rgba(255,107,122,.20);

  --shadow: 0 14px 30px rgba(0,0,0,.45);

  background: var(--bg);
  color: var(--txt);
}

/* ---------- “カードっぽい”共通箱 ---------- */
.card, .rowCard, .dateHeaderLeft, .dhCell, .cell, .left, .notice{
  background: var(--card);
  color: var(--txt);
  border: 1px solid var(--line);
  border-radius: var(--r);
  box-shadow: var(--shadow);
}

/* Darkは境界が溶けやすいので枠を強める */
body[data-theme="dark"] .card,
body[data-theme="dark"] .rowCard,
body[data-theme="dark"] .dateHeaderLeft,
body[data-theme="dark"] .dhCell,
body[data-theme="dark"] .cell,
body[data-theme="dark"] .left,
body[data-theme="dark"] .notice{
  border-color: var(--line2);
}

/* chip */
.chip{
  background: var(--chip);
  border: 1px solid var(--line);
  color: var(--txt);
  border-radius: 999px;
}

.tag{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-height: 24px;
  padding: 0 10px;
  border-radius: 999px;
  border: 1px solid var(--line2);
  background: var(--chip);
  color: var(--txt);
  font-size: 12px;
  font-weight: 900;
}
.tag.me{
  color: var(--pri);
  background: var(--priBg);
  border-color: color-mix(in srgb, var(--pri) 45%, var(--line2) 55%);
}
.tag.ng{
  color: var(--ng);
  background: var(--ngBg);
  border-color: color-mix(in srgb, var(--ng) 40%, var(--line2) 60%);
}

/* =========================================================
   レイアウト：上ヘッダ固定 + 左固定
========================================================= */

.head{
  padding: 16px 18px;
}
.headRow{
  display:grid;
  grid-template-columns: minmax(0, 1.3fr) minmax(280px, 420px);
  gap: 16px;
  align-items: start;
}
.headMain{
  display:grid;
  gap: 10px;
}
.eyebrow{
  font-size: 12px;
  letter-spacing: .12em;
  text-transform: uppercase;
  color: var(--pri);
  font-weight: 900;
}
.ttl{
  font-size: clamp(28px, 3vw, 40px);
  font-weight: 1000;
  line-height: 1.05;
}
.sub{
  color: var(--mut);
  font-size: 14px;
  line-height: 1.6;
}
.headMeta{
  display:grid;
  grid-template-columns: repeat(2, minmax(180px, 1fr));
  gap: 10px;
}
.metaCard{
  display:grid;
  gap: 4px;
  padding: 12px 14px;
  border-radius: 16px;
  border: 1px solid var(--line);
  background: linear-gradient(180deg, color-mix(in srgb, var(--panel) 78%, var(--card) 22%), var(--card));
}
.metaLabel{
  font-size: 12px;
  color: var(--mut);
  font-weight: 800;
}
.chips{
  display:flex;
  flex-wrap:wrap;
  gap: 8px;
}
.chip{
  padding: 7px 12px;
  font-size: 12px;
  font-weight: 800;
}
.ctrl{
  display:grid;
  gap: 10px;
  align-self: stretch;
  padding: 14px;
  border: 1px solid var(--line);
  border-radius: 18px;
  background: linear-gradient(180deg, color-mix(in srgb, var(--panel) 72%, var(--card) 28%), var(--card));
}
.dateBox{
  display:grid;
  gap: 6px;
}
.weekLine{
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px solid var(--line);
  display:flex;
  flex-wrap:wrap;
  gap: 10px 18px;
  color: var(--mut);
  font-size: 14px;
}
.weekLineItem b{
  color: var(--txt);
}

.modeSwitch{
  margin-top: 12px;
  display:flex;
  gap:8px;
  flex-wrap:wrap;
}
.modeSwitchBtn{
  min-height: 40px;
  padding: 0 16px;
  border-radius: 999px;
  border: 1px solid var(--line2);
  background: var(--chip);
  color: var(--txt);
  font-weight: 1000;
  cursor: pointer;
}
.modeSwitchBtn.is-active{
  background: var(--priBg);
  border-color: color-mix(in srgb, var(--pri) 45%, var(--line2) 55%);
  color: var(--pri);
}
.quickModeCard,
.weeklyModeWrap{
  display:none;
}
.quickModeCard.is-active,
.weeklyModeWrap.is-active{
  display:block;
}
.quickModeCard{
  margin-top: 12px;
  padding: 16px;
  display:none;
  gap: 14px;
}
.quickModeHead{
  display:flex;
  justify-content:space-between;
  gap:12px;
  align-items:flex-start;
  flex-wrap:wrap;
}
.quickModeEyebrow{
  font-size:12px;
  letter-spacing:.12em;
  text-transform:uppercase;
  color:var(--pri);
  font-weight:900;
}
.quickModeTitle{
  font-size: clamp(24px, 3vw, 34px);
  font-weight: 1000;
  line-height: 1.05;
}
.quickModeSub{
  margin-top:6px;
  color:var(--mut);
  font-size:14px;
}
.quickModeLegend{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
}
.quickLegend{
  display:inline-flex;
  align-items:center;
  min-height:28px;
  padding:0 12px;
  border-radius:999px;
  border:1px solid var(--line2);
  background:var(--chip);
  font-size:12px;
  font-weight:900;
}
.quickLegend.is-work{
  background: var(--priBg);
  color: var(--pri);
}
.quickLegend.is-off{
  background: color-mix(in srgb, var(--ngBg) 85%, var(--card) 15%);
  color: var(--ng);
}
.quickLegend.is-transport{
  background: color-mix(in srgb, #f59e0b 14%, var(--card) 86%);
  color: #b45309;
}
.quickStats{
  display:grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap:10px;
  margin-top: 14px;
}
.quickStatCard{
  padding:12px 14px;
  border-radius:16px;
  border:1px solid var(--line);
  background:linear-gradient(180deg, color-mix(in srgb, var(--panel) 74%, var(--card) 26%), var(--card));
  display:grid;
  gap:4px;
}
.quickStatCard span{
  color:var(--mut);
  font-size:12px;
  font-weight:800;
}
.quickStatCard b{
  font-size:24px;
  line-height:1;
}
.quickGrid{
  margin-top: 14px;
  display:grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap:10px;
}
.quickCastCard{
  padding:10px;
  border-radius:16px;
  border:1px solid var(--line);
  background:var(--card);
  display:grid;
  gap:8px;
  transition: border-color .18s ease, background-color .18s ease, box-shadow .18s ease;
  box-shadow: var(--shadow);
}
.quickCastCard.is-working{
  border-color: rgba(37,99,235,.40);
  background: linear-gradient(180deg, rgba(37,99,235,.10), rgba(37,99,235,.04));
}
.quickCastCard.is-off{
  border-color: rgba(239,68,68,.24);
}
.quickCastCard.needs-transport{
  box-shadow: var(--shadow), 0 0 0 2px rgba(245,158,11,.16) inset;
}
.quickCastCard.is-readonly{
  opacity:.72;
}
.quickMainBtn,
.quickTransportBtn{
  width:100%;
  border:1px solid var(--line2);
  border-radius:14px;
  background:var(--chip);
  color:var(--txt);
  cursor:pointer;
}
.quickMainBtn{
  min-height:96px;
  padding:14px 12px;
  display:grid;
  gap:6px;
  justify-items:start;
  text-align:left;
}
.quickTransportBtn{
  min-height:42px;
  padding:0 12px;
  display:flex;
  justify-content:space-between;
  align-items:center;
  font-size:13px;
  font-weight:900;
}
.quickTransportBtn.is-on{
  border-color:#d97706;
  background:rgba(245,158,11,.18);
  color:#92400e;
}
.quickCode{
  font-size:14px;
  font-weight:1000;
  color:var(--mut);
}
.quickName{
  font-size:22px;
  line-height:1.1;
  font-weight:1000;
}
.quickState{
  display:inline-flex;
  align-items:center;
  min-height:28px;
  padding:0 12px;
  border-radius:999px;
  background:var(--card);
  border:1px solid var(--line2);
  font-size:12px;
  font-weight:1000;
}

/* 上の曜日ヘッダ：透明/ぼかしはやめて不透明に（読みやすさ優先） */
.dateHeader{
  margin-top: 10px;
  display:grid;
  grid-template-columns: 180px 1fr;
  gap: var(--gap);

  position: sticky;
  top: 0;
  z-index: 50;

  padding: 8px 0;
  background: var(--bg);            /* ← 不透明 */
  border-bottom: 1px solid var(--line);
}

/* 左ヘッダ */
.dateHeaderLeft{
  padding: 8px 12px;
  font-weight: 1000;
  background: var(--chip);
  display:flex;
  align-items:center;
}

/* 曜日ヘッダグリッド */
.dateHeaderGrid{
  display:grid;
  grid-template-columns: repeat(var(--days, 7), minmax(132px, 1fr));
  gap: 8px;
}

/* 1日分ヘッダセル：chipトーンにして視認性UP */
.dhCell{
  padding: 10px 8px;
  text-align:center;
  font-weight: 1000;
  line-height: 1.15;
  background: var(--chip);
}
.dhTop{
  font-size: 24px;
  letter-spacing: -.03em;
}
.dhSub{
  margin-top: 2px;
  font-size: 12px;
  color: var(--mut);
}
.dhMeta{
  margin-top: 8px;
  padding-top: 8px;
  border-top: 1px solid var(--line);
  display:grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 6px;
}
.dhMetric{
  display:grid;
  gap: 2px;
  padding: 6px 4px;
  border-radius: 10px;
  background: color-mix(in srgb, var(--card) 70%, var(--panel) 30%);
}
.dhMetricWarm{
  background: color-mix(in srgb, #f59e0b 12%, var(--card) 88%);
}
.dhMetaLabel{
  font-size: 10px;
  font-weight: 900;
  letter-spacing: .08em;
  color: var(--mut);
}
.dhMeta strong{
  font-size: 20px;
  line-height: 1;
}

/* 各キャスト行 */
.rowCard{
  margin-top: 8px;
  padding: 10px;
  display:grid;
  grid-template-columns: 180px 1fr;
  gap: var(--gap);
  align-items: start;
}

/* 左のキャスト欄：sticky + 不透明背景必須 */
.left{
  position: sticky;
  left: 0;
  z-index: 40;                 /* ヘッダ(50)より下、セルより上 */
  background: var(--chip);
  padding: 12px;
  border-color: var(--line2);
  display:grid;
  gap: 10px;
  align-content: start;
  min-height: 100%;
}

/* キャスト名表示 */
.castName{
  display:flex;
  align-items:center;
  gap:6px;
  flex-wrap:wrap;
  line-height: 1.15;
}
.castCode{
  min-width:36px;
  text-align:right;
  color: var(--mut);
  font-weight: 1000;
}
.castDisplay{
  font-weight: 1000;
  letter-spacing: .2px;
  font-size: 18px;
}
.castMeta{
  display:flex;
  flex-wrap:wrap;
  gap: 6px;
  align-items:center;
}
.castMetaBadge{
  display:inline-flex;
  align-items:center;
  min-height: 26px;
  padding: 0 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 900;
  border: 1px solid var(--line2);
}
.castMetaBadge.isRegular{
  color: var(--pri);
  background: var(--priBg);
}
.castMetaBadge.isPart{
  color: var(--mut);
  background: var(--card);
}
.castMetaText{
  font-size: 12px;
  color: var(--mut);
  font-weight: 800;
}
.castBaseStart{
  display:flex;
  align-items:center;
  gap:8px;
  flex-wrap:wrap;
}
.castBaseStartLabel{
  font-size:12px;
  color:var(--mut);
  font-weight:900;
}
.castBaseStartInput{
  min-width:112px;
}
.castStats{
  display:grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 6px;
}
.statPill{
  display:grid;
  gap: 2px;
  padding: 8px 10px;
  border-radius: 12px;
  border: 1px solid var(--line);
  background: var(--card);
}
.statPill.isWarm{
  background: color-mix(in srgb, #f59e0b 10%, var(--card) 90%);
}
.statLabel{
  font-size: 10px;
  font-weight: 900;
  letter-spacing: .08em;
  color: var(--mut);
}
.statPill strong{
  font-size: 16px;
  line-height: 1;
}

/* 「週を出勤」ボタン */
.btnMini{
  width: 100%;
  height: 30px;
  padding: 0 12px;
  border-radius: 999px;
  border: 1px solid var(--line2);
  background: var(--priBg);
  color: var(--pri);
  font-size: 12px;
  font-weight: 1000;
  cursor: pointer;
}
.btnMini:hover{ filter: brightness(1.06); }
.btnMini:active{ transform: translateY(1px); }

/* 右の7日グリッド */
.grid{
  display:grid;
  grid-template-columns: repeat(var(--days, 7), minmax(132px, 1fr));
  gap: 6px;
}

/* 保存バー：画面下に見えるよう sticky */
.foot{
  position: sticky;
  bottom: 12px;
  z-index: 60;
  display: flex;
  justify-content: flex-end;
  margin-top: 14px;
  padding: 10px 12px calc(10px + env(safe-area-inset-bottom, 0px));
  border: 1px solid var(--line2);
  border-radius: 18px;
  background: color-mix(in srgb, var(--bg) 82%, var(--card) 18%);
  backdrop-filter: blur(10px);
  box-shadow: var(--shadow);
}
body[data-theme="dark"] .foot{
  background: color-mix(in srgb, var(--bg) 72%, var(--card) 28%);
}
.foot .btn{
  min-width: 140px;
}

/* 1日分セル */
.cell{
  padding: 8px;
  border-radius: 12px;
  transition: background-color .18s ease, border-color .18s ease, box-shadow .18s ease;
  min-height: 128px;
  display:grid;
  align-content:start;
}
.cell.is-working{
  border-color: rgba(37,99,235,.40);
  background: linear-gradient(180deg, rgba(37,99,235,.10), rgba(37,99,235,.04));
}
body[data-theme="dark"] .cell.is-working{
  border-color: rgba(123,177,255,.45);
  background: linear-gradient(180deg, rgba(123,177,255,.20), rgba(123,177,255,.08));
}
.cell.is-off{
  border-color: rgba(239,68,68,.28);
}
.cell.is-douhan{
  border-color: rgba(245,158,11,.52);
  box-shadow:
    var(--shadow),
    0 0 0 2px rgba(245,158,11,.14) inset;
}
.cell.is-working.is-douhan{
  background:
    linear-gradient(180deg, rgba(245,158,11,.14), rgba(245,158,11,.06)),
    linear-gradient(180deg, rgba(37,99,235,.10), rgba(37,99,235,.04));
}
body[data-theme="dark"] .cell.is-douhan{
  border-color: rgba(251,191,36,.58);
  box-shadow:
    var(--shadow),
    0 0 0 2px rgba(251,191,36,.16) inset;
}
body[data-theme="dark"] .cell.is-working.is-douhan{
  background:
    linear-gradient(180deg, rgba(251,191,36,.18), rgba(251,191,36,.08)),
    linear-gradient(180deg, rgba(123,177,255,.20), rgba(123,177,255,.08));
}

/* =========================================================
   ボタン/トグル/セレクトの整形（Darkでも見える）
========================================================= */

.toggles{
  display:flex;
  gap:4px;
  align-items:center;
  flex-wrap:wrap;
  margin-bottom: 6px;
}

/* トグル（.tgl が付く前提） */
.tgl{
  height: 24px;
  font-size: 11px;
  padding: 0 8px;
  border-radius: 999px;
  border: 1px solid var(--line2);
  background: var(--chip);
  color: var(--txt);
  font-weight: 1000;
  cursor:pointer;
  user-select:none;
}
.tgl.on{
  border-color: var(--ok);
  background: var(--okBg);
}
.tgl.work{
  border-color: var(--pri);
  background: var(--priBg);
  color: var(--pri);
}
body[data-theme="dark"] .tgl.work{
  border-color: var(--pri);
  background: rgba(123,177,255,.22);
  color: #dbeafe;
}
.tgl.ng{
  border-color: var(--ng);
  background: var(--ngBg);
}
.tgl.douhan-on{
  border-color: #d97706;
  background: rgba(245,158,11,.20);
  color: #92400e;
}
body[data-theme="dark"] .tgl.douhan-on{
  border-color: #fbbf24;
  background: rgba(251,191,36,.24);
  color: #fde68a;
}
.tgl:disabled{ opacity:.45; cursor:not-allowed; }

/* 時刻selectを縦並び */
.times{
  display:grid;
  grid-template-columns: 1fr;
  gap: 4px;
}

/* select/input：Darkで沈む問題を避けるため “常に少し明るい面” */
select,
.times select,
input[type="date"],
select.sel, .sel, .sel.mini{
  width: 100%;
  min-width: 0;
  height: 34px;
  padding: 0 8px;
  border-radius: 10px;
  border: 1px solid var(--line2);
  background: var(--card);
  color: var(--txt);
  font-weight: 900;
  outline: none;
}
body[data-theme="dark"] select,
body[data-theme="dark"] input[type="date"]{
  background: #142349;          /* ← cardより少し明るくして文字が沈まない */
  border-color: var(--line2);
}
select:focus,
input[type="date"]:focus{
  border-color: var(--pri);
  box-shadow: 0 0 0 4px rgba(37,99,235,.18);
}
body[data-theme="dark"] select:focus,
body[data-theme="dark"] input[type="date"]:focus{
  box-shadow: 0 0 0 4px rgba(123,177,255,.22);
}

/* セル内のボタン（class無くても最低限読みやすく） */
.cell button{
  height: 30px;
  padding: 0 10px;
  border-radius: 999px;
  border: 1px solid var(--line2);
  background: var(--chip);
  color: var(--txt);
  font-weight: 1000;
  cursor:pointer;
}
.cell button:hover{ filter: brightness(1.06); }
.cell button:active{ transform: translateY(1px); }

/* muted系 */
.muted, .small{ color: var(--mut); }
body[data-theme="dark"] .muted,
body[data-theme="dark"] .small{ color: var(--mut); }

/* =========================================================
   レスポンシブ
========================================================= */
@media (min-width: 1200px){
  .dateHeader,
  .rowCard{
    grid-template-columns: 220px 1fr;
  }
  .dateHeaderGrid,
  .grid{
    grid-template-columns: repeat(var(--days, 7), minmax(118px, 1fr));
  }
}

@media (max-width: 1100px){
  .headRow{
    grid-template-columns: 1fr;
  }
  .headMeta{
    grid-template-columns: 1fr;
  }
  .dateHeader, .rowCard{ grid-template-columns: 1fr; }
  .left{ position: relative; left: auto; }
  .dateHeaderLeft{ display:none; }

  .dateHeaderGrid{ grid-template-columns: repeat(4, minmax(132px, 1fr)); }
  .grid{ grid-template-columns: repeat(4, minmax(132px, 1fr)); }
  .btnMini{ width: auto; }
  .castStats{ grid-template-columns: repeat(2, minmax(92px, 1fr)); }
  .quickStats{ grid-template-columns: 1fr; }
}

@media (max-width: 680px){
  .head{
    padding: 14px;
  }
  .ttl{
    font-size: 30px;
  }
  .rowCard{
    padding: 8px;
  }
  .left{
    padding: 10px;
  }
  .cell{
    min-height: 120px;
    padding: 8px;
  }
  .toggles{
    gap: 6px;
  }
  .tgl{
    height: 30px;
    font-size: 12px;
    padding: 0 10px;
  }
  select,
  .times select,
  input[type="date"],
  select.sel, .sel, .sel.mini{
    height: 40px;
    padding: 0 10px;
    font-size: 16px;
  }
  .foot{
    bottom: 8px;
    padding: 8px 10px calc(8px + env(safe-area-inset-bottom, 0px));
  }
  .dateHeaderGrid{ grid-template-columns: repeat(3, minmax(132px, 1fr)); }
  .grid{ grid-template-columns: repeat(3, minmax(132px, 1fr)); }
  .dhMeta strong{ font-size: 18px; }
  .quickModeCard{ padding: 14px; }
  .quickGrid{ grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .quickName{ font-size: 18px; }
  .quickMainBtn{ min-height: 86px; }
}
</style>

<script>
(() => {
  const csrfToken = <?= json_encode(function_exists('csrf_token') ? csrf_token() : '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const quickBusinessDateOpen = <?= $isQuickBusinessDateOpen ? 'true' : 'false' ?>;

  function isReadonly(cell){
    return (cell?.dataset?.readonly === '1');
  }

  function updateQuickCounts(){
    let workingCount = 0;
    let transportCount = 0;
    document.querySelectorAll('[data-quick-card]').forEach(card => {
      if (card.dataset.working === '1') workingCount += 1;
      if (card.dataset.transport === '1') transportCount += 1;
    });
    const workingNode = document.getElementById('quickWorkingCount');
    const transportNode = document.getElementById('quickTransportCount');
    if (workingNode) workingNode.textContent = String(workingCount);
    if (transportNode) transportNode.textContent = String(transportCount);
  }

  function syncQuickCard(card){
    const isWorking = card.dataset.working === '1';
    const needsTransport = card.dataset.transport === '1';
    const shiftLabel = card.querySelector('[data-quick-shift-label]');
    const transportLabel = card.querySelector('[data-quick-transport-label]');
    const transportBtn = card.querySelector('.quickTransportBtn');

    card.classList.toggle('is-working', isWorking);
    card.classList.toggle('is-off', !isWorking);
    card.classList.toggle('needs-transport', needsTransport);
    if (shiftLabel) shiftLabel.textContent = isWorking ? '出勤' : '休み';
    if (transportLabel) transportLabel.textContent = needsTransport ? 'あり' : 'なし';
    if (transportBtn) transportBtn.classList.toggle('is-on', needsTransport);
  }

  async function quickToggle(card, kind, button){
    if (!card || !kind || !quickBusinessDateOpen) return;
    const uid = card.dataset.uid || '';
    if (!uid) return;

    const body = new URLSearchParams();
    body.set('action', 'toggle_today_quick');
    body.set('target_user_id', uid);
    body.set('toggle_kind', kind);
    body.set('_csrf', csrfToken);

    const buttons = card.querySelectorAll('button');
    buttons.forEach(node => { node.disabled = true; });
    card.classList.add('is-loading');

    try {
      const res = await fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: body.toString(),
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.error || '保存に失敗しました');
      }

      if (kind === 'shift') {
        card.dataset.working = data.is_working ? '1' : '0';
      } else if (kind === 'transport') {
        card.dataset.transport = data.transport_needed ? '1' : '0';
      }
      syncQuickCard(card);
      updateQuickCounts();
    } catch (error) {
      window.alert(error instanceof Error ? error.message : '保存に失敗しました');
    } finally {
      card.classList.remove('is-loading');
      buttons.forEach(node => {
        if (!button?.closest('.is-readonly')) {
          node.disabled = false;
        }
      });
      if (card.classList.contains('is-readonly')) {
        buttons.forEach(node => { node.disabled = true; });
      }
    }
  }

  function setMode(mode){
    const quickSection = document.getElementById('quickModeSection');
    const weekSection = document.getElementById('weeklyModeSection');
    document.querySelectorAll('[data-mode-switch]').forEach(btn => {
      btn.classList.toggle('is-active', btn.dataset.modeSwitch === mode);
    });
    if (quickSection) quickSection.classList.toggle('is-active', mode === 'quick');
    if (weekSection) weekSection.classList.toggle('is-active', mode === 'week');
  }

  function updateDayTotals(){
    const totals = {};
    const douhanTotals = {};
    document.querySelectorAll('.cell').forEach(cell => {
      const date = cell.dataset.date;
      if (!date) return;
      const hidOff = cell.querySelector('.hidOff');
      const hidDou = cell.querySelector('.hidDou');
      const startSel = cell.querySelector('.startSel');
      const isOff = hidOff && hidOff.value === '1';
      const hasStart = !!(startSel && startSel.value && startSel.value !== '--');
      if (!totals[date]) totals[date] = 0;
      if (!douhanTotals[date]) douhanTotals[date] = 0;
      if (!isOff && hasStart) totals[date] += 1;
      if (!isOff && hasStart && hidDou && hidDou.value === '1') douhanTotals[date] += 1;
    });

    document.querySelectorAll('.js-day-total').forEach(node => {
      const date = node.dataset.date || '';
      node.textContent = String(totals[date] || 0);
    });
    document.querySelectorAll('.js-day-douhan-total').forEach(node => {
      const date = node.dataset.date || '';
      node.textContent = String(douhanTotals[date] || 0);
    });
  }

  function setOff(cell, off){
    const hidOff = cell.querySelector('.hidOff');
    const hidDou = cell.querySelector('.hidDou');
    const offBtn = cell.querySelector('.tglOff');
    const douBtn = cell.querySelector('.tglDou');
    const startSel = cell.querySelector('.startSel');
    const endSel = cell.querySelector('.endSel');

    if (!hidOff || !offBtn) return;

    hidOff.value = off ? '1' : '0';
    offBtn.classList.toggle('on', off);
    offBtn.classList.toggle('work', !off);
    offBtn.textContent = off ? '休み' : '出勤';
    cell.classList.toggle('is-off', off);
    cell.classList.toggle('is-working', !off);
    cell.classList.toggle('is-douhan', !off && hidDou && hidDou.value === '1');

    const ro = isReadonly(cell);

    if (startSel){
      if (off) startSel.value = '';
      startSel.disabled = off || ro;
    }
        // ✅ 出勤に切り替えた瞬間：開始が未選択ならデフォを入れる
    if (!off && startSel && !startSel.disabled) {
      if (!startSel.value || startSel.value === '--') {
        const def = (cell.dataset.defaultStart || '20:00').trim();
        startSel.value = def;
        startSel.dispatchEvent(new Event('change', { bubbles:true }));
      }
    }
    if (endSel) endSel.disabled = off || ro;

    // 同伴：disabledを「休み or readonly」だけで決める（押せない問題の原因を排除）
    if (douBtn){
      if (off) {
        if (hidDou) hidDou.value = '0';
        douBtn.classList.remove('on');
        douBtn.classList.remove('douhan-on');
      }
      douBtn.disabled = off || ro;
    }

    updateDayTotals();
  }

  function toggleDou(cell){
    const ro = isReadonly(cell);
    const hidOff = cell.querySelector('.hidOff');
    const hidDou = cell.querySelector('.hidDou');
    const douBtn = cell.querySelector('.tglDou');

    if (!hidDou || !douBtn) return;
    if (ro) return;
    if (hidOff && hidOff.value === '1') return; // 休み中は不可

    const on = (hidDou.value === '1');
    hidDou.value = on ? '0' : '1';
    douBtn.classList.toggle('on', !on);
    douBtn.classList.toggle('douhan-on', !on);
    cell.classList.toggle('is-douhan', !on);
    updateDayTotals();
  }

  document.querySelectorAll('.cell').forEach(cell => {
    const hidOff = cell.querySelector('.hidOff');
    const hidDou = cell.querySelector('.hidDou');
    const offBtn = cell.querySelector('.tglOff');
    const douBtn = cell.querySelector('.tglDou');

    // 初期反映
    if (hidOff) setOff(cell, hidOff.value === '1');
    if (douBtn && hidDou && hidDou.value === '1') {
      douBtn.classList.add('douhan-on');
      if (!(hidOff && hidOff.value === '1')) cell.classList.add('is-douhan');
    }

    if (offBtn && !offBtn.disabled){
      offBtn.addEventListener('click', () => {
        const cur = (cell.querySelector('.hidOff')?.value === '1');
        setOff(cell, !cur);
      });
    }
    if (douBtn){
      douBtn.addEventListener('click', () => toggleDou(cell));
    }
    const startSel = cell.querySelector('.startSel');
    if (startSel){
      startSel.addEventListener('change', () => updateDayTotals());
    }
  });
  updateDayTotals();

  document.querySelectorAll('[data-mode-switch]').forEach(btn => {
    btn.addEventListener('click', () => setMode(btn.dataset.modeSwitch || 'week'));
  });
  document.querySelectorAll('[data-quick-card]').forEach(card => {
    syncQuickCard(card);
    card.querySelectorAll('[data-quick-toggle]').forEach(button => {
      if (button.disabled) return;
      button.addEventListener('click', () => quickToggle(card, button.dataset.quickToggle || '', button));
    });
  });
  updateQuickCounts();
})();
function weekRegularOn(uid){
  const cells = document.querySelectorAll(`.cell[data-uid="${uid}"]`);
  cells.forEach(cell => {
    if (cell.dataset.readonly === '1') return;

    const hidOff = cell.querySelector('.hidOff');
    const isOff = hidOff && hidOff.value === '1';

    if (isOff) {
      const btn = cell.querySelector('.tglOff');
      if (btn && !btn.disabled) btn.click();
    }

    // クリック後に取り直す（disabledが外れた前提）
    const sel = cell.querySelector('select.startSel');
    if (!sel || sel.disabled) return;

    if (!sel.value || sel.value === '--') {
      const def = (cell.dataset.defaultStart || '20:00').trim();
      sel.value = def;
      sel.dispatchEvent(new Event('change', { bubbles:true }));
    }
  });
}
</script>

<?php render_page_end(); ?>
