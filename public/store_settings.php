<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';

require_login();
require_role(['admin','super_user']);

$pdo = db();

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

function store_has_column(PDO $pdo, string $table, string $column): bool {
  try {
    $st = $pdo->prepare("
      SELECT COUNT(*)
      FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
    ");
    $st->execute([$table, $column]);
    return ((int)$st->fetchColumn() > 0);
  } catch (Throwable $e) {
    return false;
  }
}

/* -------------------------
   role helper
------------------------- */
function has_role(string $role): bool {
  return isset($_SESSION['roles']) && in_array($role, $_SESSION['roles'], true);
}
$isSuper = has_role('super_user');

/* -------------------------
   CSRF (最小)
------------------------- */
function csrf_token(): string {
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
  }
  return (string)$_SESSION['csrf_token'];
}
function csrf_verify(): void {
  $t = (string)($_POST['csrf_token'] ?? '');
  $e = (string)($_SESSION['csrf_token'] ?? '');
  if ($t === '' || $e === '' || !hash_equals($e, $t)) {
    throw new RuntimeException('CSRF token mismatch');
  }
}

/* -------------------------
   admin の自店舗固定
------------------------- */
function current_admin_store_id(PDO $pdo, int $userId): int {
  $st = $pdo->prepare("
    SELECT ur.store_id
    FROM user_roles ur
    JOIN roles r ON r.id = ur.role_id
    WHERE ur.user_id = ?
      AND r.code = 'admin'
      AND ur.store_id IS NOT NULL
    ORDER BY ur.store_id ASC
    LIMIT 1
  ");
  $st->execute([$userId]);
  $sid = $st->fetchColumn();
  if (!$sid) throw new RuntimeException('この管理者は店舗に紐付いていません');
  return (int)$sid;
}

/* -------------------------
   stores 一覧（superのみ）
------------------------- */
$stores = [];
if ($isSuper) {
  $stores = $pdo->query("SELECT id,name,is_active FROM stores ORDER BY is_active DESC, id ASC")
    ->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* -------------------------
   対象 store_id 決定
------------------------- */
$storeId = 0;

if ($isSuper) {
  $storeId = (int)($_GET['store_id'] ?? 0);
  if ($storeId <= 0) {
    $st = $pdo->query("SELECT id FROM stores WHERE is_active=1 ORDER BY id ASC LIMIT 1");
    $storeId = (int)$st->fetchColumn();
  }
  if ($storeId <= 0) throw new RuntimeException('有効な店舗が存在しません');
} else {
  $storeId = current_admin_store_id($pdo, (int)current_user_id());
}

/* -------------------------
   GET: 現在値
------------------------- */
function fetch_store(PDO $pdo, int $storeId): array {
  $hasLateNoticeDelay = store_has_column($pdo, 'stores', 'late_notice_delay_minutes');
  $hasLateNoticeEnabled = store_has_column($pdo, 'stores', 'late_notice_auto_enabled');
  $hasAttendanceConfirmEnabled = store_has_column($pdo, 'stores', 'attendance_confirm_auto_enabled');
  $hasAttendanceConfirmLeadHours = store_has_column($pdo, 'stores', 'attendance_confirm_lead_hours');
  $hasWeeklyHolidayDow = store_has_column($pdo, 'stores', 'weekly_holiday_dow');
  $lateNoticeDelayCol = $hasLateNoticeDelay
    ? 'late_notice_delay_minutes'
    : '10 AS late_notice_delay_minutes';
  $lateNoticeEnabledCol = $hasLateNoticeEnabled
    ? 'late_notice_auto_enabled'
    : '1 AS late_notice_auto_enabled';
  $attendanceConfirmEnabledCol = $hasAttendanceConfirmEnabled
    ? 'attendance_confirm_auto_enabled'
    : '1 AS attendance_confirm_auto_enabled';
  $attendanceConfirmLeadHoursCol = $hasAttendanceConfirmLeadHours
    ? 'attendance_confirm_lead_hours'
    : '3 AS attendance_confirm_lead_hours';
  $weeklyHolidayDowCol = $hasWeeklyHolidayDow
    ? 'weekly_holiday_dow'
    : 'NULL AS weekly_holiday_dow';

  $st = $pdo->prepare("
    SELECT
      id, code, name, is_active,
      business_day_start,
      open_time, close_time_weekday, close_time_weekend,
      close_is_next_day_weekday, close_is_next_day_weekend,
      weekend_dow_mask,
      {$weeklyHolidayDowCol},
      lat, lon, radius_m,
      {$lateNoticeDelayCol},
      {$lateNoticeEnabledCol},
      {$attendanceConfirmEnabledCol},
      {$attendanceConfirmLeadHoursCol}
    FROM stores
    WHERE id=?
    LIMIT 1
  ");
  $st->execute([$storeId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return is_array($row) ? $row : [];
}

function format_time_hm(?string $value, string $fallback): string {
  $value = trim((string)$value);
  if ($value === '') {
    $value = $fallback;
  }
  if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)) {
    return substr($value, 0, 5);
  }
  if (preg_match('/^\d{2}:\d{2}$/', $value)) {
    return $value;
  }
  return $fallback;
}

function normalize_time_seconds(string $value, string $fallback): string {
  $value = trim($value);
  if ($value === '') {
    $value = $fallback;
  }
  if (preg_match('/^\d{2}:\d{2}$/', $value)) {
    return $value . ':00';
  }
  if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)) {
    return $value;
  }
  throw new RuntimeException('時刻は HH:MM 形式で入力してください');
}

function jst_now(): DateTime {
  return new DateTime('now', new DateTimeZone('Asia/Tokyo'));
}

function business_date_for_store_settings(array $storeRow, ?DateTime $now = null): string {
  $now = $now ?: jst_now();
  $cut = (string)($storeRow['business_day_start'] ?? '06:00:00');
  $cutDT = new DateTime($now->format('Y-m-d') . ' ' . $cut, new DateTimeZone('Asia/Tokyo'));
  if ($now < $cutDT) {
    $now->modify('-1 day');
  }
  return $now->format('Y-m-d');
}

function resolve_line_user_id(PDO $pdo, int $userId): string {
  $st = $pdo->prepare("
    SELECT provider_user_id
    FROM user_identities
    WHERE user_id = ?
      AND provider = 'line'
      AND is_active = 1
    ORDER BY id DESC
    LIMIT 1
  ");
  $st->execute([$userId]);
  return (string)($st->fetchColumn() ?: '');
}

function line_api_post(string $accessToken, string $path, array $body): array {
  $url = 'https://api.line.me/v2/bot/' . ltrim($path, '/');
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'Authorization: Bearer ' . $accessToken,
    ],
    CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 12,
  ]);
  $res = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);

  return [
    'code' => $code,
    'body' => is_string($res) ? $res : '',
    'curl_error' => (string)$err,
  ];
}

function weekend_dow_options(): array {
  return [
    32 => '金',
    64 => '土',
  ];
}

function weekly_holiday_options(): array {
  return [
    '' => 'なし',
    '0' => '日',
    '1' => '月',
    '2' => '火',
    '3' => '水',
    '4' => '木',
    '5' => '金',
    '6' => '土',
  ];
}

$err = '';
$msg = '';

/* -------------------------
   POST: 更新
------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_verify();

    // super_user だけ store_id をPOSTから受けてもOK（adminは固定）
    $storeIdP = $storeId;
    if ($isSuper) {
      $storeIdP = (int)($_POST['store_id'] ?? $storeId);
      if ($storeIdP <= 0) throw new RuntimeException('store_id invalid');
    }

    $action = (string)($_POST['action'] ?? 'save_settings');
    if ($action === 'send_attendance_confirm_test') {
      $targetUserId = (int)($_POST['attendance_confirm_test_user_id'] ?? 0);
      if ($targetUserId <= 0) {
        throw new RuntimeException('テスト送信先を選択してください');
      }

      $st = $pdo->prepare("
        SELECT
          c.user_id,
          c.display_name,
          COALESCE(NULLIF(c.shop_tag, ''), '') AS shop_tag
        FROM v_store_casts_active c
        WHERE c.store_id = ?
          AND c.user_id = ?
          AND COALESCE(NULLIF(c.shop_tag, ''), '') <> ''
        LIMIT 1
      ");
      $st->execute([$storeIdP, $targetUserId]);
      $targetUser = $st->fetch(PDO::FETCH_ASSOC) ?: null;
      if (!$targetUser) {
        throw new RuntimeException('選択したユーザーはこの店舗の有効キャストではありません');
      }

      $lineTo = resolve_line_user_id($pdo, $targetUserId);
      if ($lineTo === '') {
        throw new RuntimeException('このユーザーはLINE未連携です');
      }

      $accessToken = conf('LINE_MSG_CHANNEL_ACCESS_TOKEN');
      if ($accessToken === '') {
        throw new RuntimeException('LINE送信用トークンが未設定です');
      }

      $businessDate = business_date_for_store_settings(fetch_store($pdo, $storeIdP));
      $displayName = trim((string)($targetUser['display_name'] ?? ''));
      $shopTag = trim((string)($targetUser['shop_tag'] ?? ''));
      $nameLabel = $displayName !== '' ? $displayName : ('user#' . $targetUserId);
      $tagLabel = $shopTag !== '' ? " 店番{$shopTag}" : '';
      $text = "【テスト送信】出勤確認LINEです。\n{$nameLabel}{$tagLabel} さん、本日の出勤予定を確認してください。\n「出勤します / 遅れます / 休みます / その他」から返信できます。";
      $token = bin2hex(random_bytes(12));

      $pdo->beginTransaction();
      try {
        $ins = $pdo->prepare("
          INSERT INTO line_notice_actions
            (store_id, business_date, cast_user_id, kind, token, template_text, sent_text,
             sent_by_user_id, sent_at, status, error_message, created_at, updated_at)
          VALUES
            (?, ?, ?, 'attendance_confirm', ?, ?, ?, ?, NOW(), 'sent', NULL, NOW(), NOW())
        ");
        $ins->execute([
          $storeIdP,
          $businessDate,
          $targetUserId,
          $token,
          $text,
          $text,
          (int)current_user_id(),
        ]);

        $api = line_api_post($accessToken, 'message/push', [
          'to' => $lineTo,
          'messages' => [[
            'type' => 'text',
            'text' => $text,
            'quickReply' => [
              'items' => [
                ['type' => 'action', 'action' => ['type' => 'message', 'label' => '出勤します', 'text' => '出勤します']],
                ['type' => 'action', 'action' => ['type' => 'message', 'label' => '遅れます', 'text' => '遅れます']],
                ['type' => 'action', 'action' => ['type' => 'message', 'label' => '休みます', 'text' => '休みます']],
                ['type' => 'action', 'action' => ['type' => 'message', 'label' => 'その他', 'text' => 'その他']],
              ],
            ],
          ]],
        ]);

        if ($api['code'] >= 300) {
          $errMsg = 'HTTP ' . $api['code'] . ($api['curl_error'] !== '' ? (' curl=' . $api['curl_error']) : '');
          $upd = $pdo->prepare("
            UPDATE line_notice_actions
            SET status = 'failed',
                error_message = ?,
                updated_at = NOW()
            WHERE token = ?
            LIMIT 1
          ");
          $upd->execute([$errMsg, $token]);
          $pdo->commit();
          throw new RuntimeException('テスト送信に失敗しました: ' . $errMsg);
        }

        $pdo->commit();
        $msg = $nameLabel . ' へ出勤確認LINEのテスト送信を行いました';
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }
        throw $e;
      }

      if ($isSuper) {
        $storeId = $storeIdP;
      }
    } else {

      // 入力
      $businessDayStart = normalize_time_seconds((string)($_POST['business_day_start'] ?? '06:00'), '06:00');
      $openTime         = normalize_time_seconds((string)($_POST['open_time'] ?? '20:00'), '20:00');
      $closeWk          = normalize_time_seconds((string)($_POST['close_time_weekday'] ?? '02:30'), '02:30');
      $closeWe          = normalize_time_seconds((string)($_POST['close_time_weekend'] ?? '05:00'), '05:00');

      $nextWk = (int)($_POST['close_is_next_day_weekday'] ?? 1);
      $nextWe = (int)($_POST['close_is_next_day_weekend'] ?? 1);

    $selectedWeekendDows = $_POST['weekend_dows'] ?? null;
    if (is_array($selectedWeekendDows)) {
      $mask = 0;
      $allowedWeekendDows = array_keys(weekend_dow_options());
      foreach ($selectedWeekendDows as $dowBit) {
        $dowBit = (int)$dowBit;
        if (in_array($dowBit, $allowedWeekendDows, true)) {
          $mask |= $dowBit;
        }
      }
    } else {
      $mask = (int)($_POST['weekend_dow_mask'] ?? 96);
    }
    $weeklyHolidayDow = null;
    if (store_has_column($pdo, 'stores', 'weekly_holiday_dow')) {
      $holidayRaw = trim((string)($_POST['weekly_holiday_dow'] ?? ''));
      if ($holidayRaw !== '') {
        $holidayDow = (int)$holidayRaw;
        if ($holidayDow < 0 || $holidayDow > 6) {
          throw new RuntimeException('店休日は 0-6 の曜日で指定してください');
        }
        $weeklyHolidayDow = $holidayDow;
      }
    }

    $lat = trim((string)($_POST['lat'] ?? ''));
    $lon = trim((string)($_POST['lon'] ?? ''));
    $radius = (int)($_POST['radius_m'] ?? 150);
    if ($radius <= 0) $radius = 150;
    $lateNoticeDelay = (int)($_POST['late_notice_delay_minutes'] ?? 10);
    if ($lateNoticeDelay < 0) $lateNoticeDelay = 0;
    if ($lateNoticeDelay > 180) $lateNoticeDelay = 180;
    $lateNoticeEnabled = (int)($_POST['late_notice_auto_enabled'] ?? 0) === 1 ? 1 : 0;
    $attendanceConfirmEnabled = (int)($_POST['attendance_confirm_auto_enabled'] ?? 0) === 1 ? 1 : 0;
    $attendanceConfirmLeadHours = (int)($_POST['attendance_confirm_lead_hours'] ?? 3);
    if ($attendanceConfirmLeadHours < 0) $attendanceConfirmLeadHours = 0;
    if ($attendanceConfirmLeadHours > 24) $attendanceConfirmLeadHours = 24;

    $latVal = ($lat === '') ? null : (float)$lat;
    $lonVal = ($lon === '') ? null : (float)$lon;

    // 簡易バリデーション（形式チェックはゆるめ）
    if ($businessDayStart === '' || $openTime === '' || $closeWk === '' || $closeWe === '') {
      throw new RuntimeException('時刻が未入力です');
    }

    $updateFields = [
      'business_day_start=?',
      'open_time=?',
      'close_time_weekday=?',
      'close_time_weekend=?',
      'close_is_next_day_weekday=?',
      'close_is_next_day_weekend=?',
      'weekend_dow_mask=?',
      'lat=?',
      'lon=?',
      'radius_m=?',
    ];
    $updateValues = [
      $businessDayStart,
      $openTime,
      $closeWk,
      $closeWe,
      $nextWk,
      $nextWe,
      $mask,
      $latVal,
      $lonVal,
      $radius,
    ];

    if (store_has_column($pdo, 'stores', 'late_notice_delay_minutes')) {
      $updateFields[] = 'late_notice_delay_minutes=?';
      $updateValues[] = $lateNoticeDelay;
    }
    if (store_has_column($pdo, 'stores', 'late_notice_auto_enabled')) {
      $updateFields[] = 'late_notice_auto_enabled=?';
      $updateValues[] = $lateNoticeEnabled;
    }
    if (store_has_column($pdo, 'stores', 'attendance_confirm_auto_enabled')) {
      $updateFields[] = 'attendance_confirm_auto_enabled=?';
      $updateValues[] = $attendanceConfirmEnabled;
    }
    if (store_has_column($pdo, 'stores', 'attendance_confirm_lead_hours')) {
      $updateFields[] = 'attendance_confirm_lead_hours=?';
      $updateValues[] = $attendanceConfirmLeadHours;
    }
    if (store_has_column($pdo, 'stores', 'weekly_holiday_dow')) {
      $updateFields[] = 'weekly_holiday_dow=?';
      $updateValues[] = $weeklyHolidayDow;
    }

    $updateValues[] = $storeIdP;

    $st = $pdo->prepare("
      UPDATE stores
      SET " . implode(",\n        ", $updateFields) . "
      WHERE id=?
      LIMIT 1
    ");
    $st->execute($updateValues);

      $msg = '更新しました';
      // 表示中のstoreIdも更新
      if ($isSuper) $storeId = $storeIdP;
    }

  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$store = fetch_store($pdo, $storeId);
if (!$store) {
  throw new RuntimeException('店舗が見つかりません');
}
$attendanceConfirmTestCandidates = [];
$selectedAttendanceConfirmTestUserId = (int)($_POST['attendance_confirm_test_user_id'] ?? 0);
try {
  $st = $pdo->prepare("
    SELECT
      c.user_id,
      c.display_name,
      COALESCE(NULLIF(c.shop_tag, ''), '') AS shop_tag
    FROM v_store_casts_active c
    JOIN user_identities ui
      ON ui.user_id = c.user_id
     AND ui.provider = 'line'
     AND ui.is_active = 1
    WHERE c.store_id = ?
      AND COALESCE(NULLIF(c.shop_tag, ''), '') <> ''
    GROUP BY c.user_id, c.display_name, c.shop_tag
    ORDER BY
      CASE
        WHEN COALESCE(NULLIF(c.shop_tag, ''), '') REGEXP '^[0-9]+$' THEN CAST(c.shop_tag AS UNSIGNED)
        ELSE 999999
      END ASC,
      COALESCE(NULLIF(c.shop_tag, ''), '') ASC,
      c.display_name ASC
  ");
  $st->execute([$storeId]);
  $attendanceConfirmTestCandidates = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $attendanceConfirmTestCandidates = [];
}
$weekendDowMask = (int)($store['weekend_dow_mask'] ?? 96);
$weekendDowOptions = weekend_dow_options();
$hasWeeklyHolidayDow = store_has_column($pdo, 'stores', 'weekly_holiday_dow');
$weeklyHolidayDowValue = array_key_exists('weekly_holiday_dow', $store) && $store['weekly_holiday_dow'] !== null
  ? (string)(int)$store['weekly_holiday_dow']
  : '';
$weeklyHolidayOptions = weekly_holiday_options();

/* -------------------------
   UI
------------------------- */
render_page_start('店舗設定');
render_header('店舗設定', [
  'back_href'  => '/wbss/public/dashboard.php',
  'back_label' => '← ダッシュボード',
]);
?>
<style>
  .settings-shell{
    max-width:1440px;
    padding-bottom:28px;
  }
  .settings-topbar{
    display:grid;
    grid-template-columns:minmax(0, 2.35fr) minmax(260px, .82fr);
    gap:12px;
    margin-top:14px;
    margin-bottom:14px;
    align-items:start;
  }
  .settings-hero-main,
  .settings-topbar-side,
  .settings-section,
  .settings-side-card,
  .settings-flash{
    border:1px solid var(--line);
    border-radius:22px;
    background:
      linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.02)),
      var(--cardA);
    box-shadow:0 16px 40px rgba(0,0,0,.14);
  }
  .settings-hero-main{
    padding:22px 24px;
    min-height:188px;
    display:flex;
    flex-direction:column;
    justify-content:center;
  }
  .hero-badge{
    display:inline-flex;
    align-items:center;
    padding:6px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:800;
    color:#0f172a;
    background:linear-gradient(135deg, #facc15, #fb923c);
  }
  .hero-store-name{
    font-size:14px;
    font-weight:900;
    color:var(--muted);
    margin-bottom:8px;
  }
  .settings-hero-main h1{
    margin:10px 0 8px;
    font-size:25px;
    line-height:1.2;
  }
  .settings-hero-main p{
    margin:0;
    color:var(--muted);
    font-size:13px;
    line-height:1.55;
  }
  .settings-pills{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    margin-top:14px;
  }
  .settings-pill{
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:6px 10px;
    border:1px solid var(--line);
    border-radius:999px;
    background:rgba(255,255,255,.05);
    font-size:12px;
    color:var(--muted);
    font-weight:700;
  }
  .settings-topbar-side{
    padding:16px 18px;
    min-height:188px;
    display:flex;
    flex-direction:column;
    gap:12px;
    width:100%;
    max-width:320px;
    justify-self:end;
  }
  .store-switch{
    display:flex;
    flex-direction:column;
    gap:6px;
  }
  .store-switch-label{
    font-size:13px;
    font-weight:900;
  }
  .store-switch-row{
    display:flex;
    gap:8px;
    align-items:center;
    flex-wrap:wrap;
  }
  .store-switch-select{
    width:80%;
    min-width:0;
  }
  .store-switch-help{
    margin:0;
    font-size:11px;
    color:var(--muted);
    line-height:1.5;
  }
  .settings-quicklist{
    display:grid;
    gap:8px;
    margin-top:auto;
  }
  .settings-quickitem{
    display:flex;
    align-items:flex-start;
    gap:10px;
    padding:10px 12px;
    border-radius:14px;
    border:1px solid rgba(255,255,255,.08);
    background:rgba(255,255,255,.04);
  }
  .settings-quickicon{
    font-size:18px;
    line-height:1;
  }
  .settings-quicktext{
    min-width:0;
  }
  .settings-quicktitle{
    font-size:12px;
    font-weight:900;
    line-height:1.3;
  }
  .settings-quickdesc{
    margin-top:3px;
    font-size:11px;
    line-height:1.45;
    color:var(--muted);
  }
  .settings-flash{
    padding:12px 14px;
    margin-bottom:12px;
    font-size:14px;
    line-height:1.5;
  }
  .settings-flash.is-error{
    border-color:rgba(251,113,133,.45);
  }
  .settings-flash.is-success{
    border-color:rgba(52,211,153,.35);
  }
  .settings-form{
    margin:0;
  }
  .settings-layout{
    display:grid;
    gap:14px;
    align-items:start;
    grid-template-columns:minmax(0, 1fr);
  }
  .settings-main{
    display:grid;
    gap:14px;
    min-width:0;
  }
  .settings-side{
    display:grid;
    gap:14px;
    min-width:0;
  }
  .settings-section,
  .settings-side-card{
    padding:16px 18px 18px;
  }
  .settings-section-head{
    margin-bottom:12px;
  }
  .settings-section-head h2{
    margin:0;
    font-size:18px;
  }
  .settings-section-head p{
    margin:4px 0 0;
    font-size:12px;
    color:var(--muted);
    line-height:1.55;
  }
  .settings-grid{
    display:grid;
    gap:12px;
    grid-template-columns:repeat(1, minmax(0, 1fr));
  }
  .settings-field{
    min-width:0;
  }
  .settings-field--full{
    grid-column:1 / -1;
  }
  .settings-field--wide{
    grid-column:span 2;
  }
  .settings-label{
    display:block;
    margin-bottom:6px;
    font-size:12px;
    color:var(--muted);
    font-weight:800;
  }
  .settings-control{
    width:100%;
    min-height:46px;
    padding:11px 12px;
    border-radius:14px;
    border:1px solid rgba(255,255,255,.10);
    background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.02));
    color:var(--txt);
    font-size:14px;
    box-sizing:border-box;
  }
  .settings-control:focus{
    outline:none;
    border-color:color-mix(in srgb, var(--accent) 50%, var(--line));
    box-shadow:0 0 0 3px color-mix(in srgb, var(--accent) 16%, transparent);
  }
  .settings-hint{
    margin-top:6px;
    font-size:11px;
    line-height:1.5;
    color:var(--muted);
  }
  .settings-checkboxes{
    display:grid;
    gap:10px;
    grid-template-columns:repeat(2, minmax(0, 1fr));
  }
  .settings-check{
    display:flex;
    align-items:center;
    gap:10px;
    min-height:52px;
    padding:12px 14px;
    border-radius:14px;
    border:1px solid rgba(255,255,255,.10);
    background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.02));
    cursor:pointer;
    font-weight:800;
  }
  .settings-check input{
    width:18px;
    height:18px;
    margin:0;
    accent-color:var(--accent);
  }
  .settings-side-card h3{
    margin:0;
    font-size:16px;
  }
  .settings-side-card p{
    margin:6px 0 0;
    font-size:12px;
    line-height:1.55;
    color:var(--muted);
  }
  .settings-kpis{
    display:grid;
    gap:10px;
    margin-top:14px;
  }
  .settings-kpi{
    padding:12px 13px;
    border-radius:16px;
    border:1px solid rgba(255,255,255,.10);
    background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.02));
  }
  .settings-kpi-label{
    font-size:11px;
    font-weight:800;
    color:var(--muted);
  }
  .settings-kpi-value{
    margin-top:5px;
    font-size:17px;
    font-weight:900;
    line-height:1.2;
  }
  .settings-kpi-note{
    margin-top:4px;
    font-size:11px;
    color:var(--muted);
    line-height:1.45;
  }
  .settings-save-box{
    margin-top:14px;
    display:grid;
    gap:10px;
  }
  .settings-save-btn{
    width:100%;
    min-height:48px;
    font-size:14px;
    font-weight:900;
  }
  .settings-note-list{
    display:grid;
    gap:8px;
    margin-top:14px;
  }
  .settings-note{
    padding:11px 12px;
    border-radius:14px;
    border:1px solid rgba(255,255,255,.08);
    background:rgba(255,255,255,.03);
    font-size:12px;
    line-height:1.55;
    color:var(--muted);
  }
  .settings-inline-row{
    display:grid;
    gap:10px;
    grid-template-columns:minmax(0, 1fr) auto;
    align-items:end;
    margin-top:10px;
  }
  .settings-inline-row .btn{
    min-height:46px;
    white-space:nowrap;
  }
  @media (min-width: 760px){
    .settings-grid--two{
      grid-template-columns:repeat(2, minmax(0, 1fr));
    }
    .settings-grid--three{
      grid-template-columns:repeat(3, minmax(0, 1fr));
    }
  }
  @media (min-width: 1100px){
    .settings-layout{
      grid-template-columns:minmax(0, 2.25fr) minmax(280px, .82fr);
    }
    .settings-side{
      position:sticky;
      top:18px;
    }
    .settings-grid--hours{
      grid-template-columns:repeat(3, minmax(0, 1fr));
    }
  }
  @media (max-width: 820px){
    .settings-topbar{
      grid-template-columns:1fr;
    }
    .settings-hero-main h1{
      font-size:26px;
    }
  }
  @media (max-width: 640px){
    .settings-shell{
      padding-bottom:28px;
    }
    .settings-hero-main,
    .settings-topbar-side,
    .settings-section,
    .settings-side-card,
    .settings-flash{
      border-radius:18px;
    }
    .settings-hero-main{
      padding:20px;
    }
    .store-switch-row{
      flex-direction:column;
      align-items:stretch;
    }
    .settings-checkboxes{
      grid-template-columns:1fr;
    }
    .settings-grid--hours,
    .settings-grid--two,
    .settings-grid--three{
      grid-template-columns:1fr;
    }
    .settings-inline-row{
      grid-template-columns:1fr;
      align-items:stretch;
    }
  }
  body[data-theme="light"] .settings-control,
  body[data-theme="light"] .settings-check,
  body[data-theme="light"] .settings-kpi,
  body[data-theme="light"] .settings-note,
  body[data-theme="light"] .settings-quickitem{
    background:#fff;
    border:1px solid var(--line);
    box-shadow:0 10px 18px rgba(0,0,0,.06);
  }
</style>
<div class="page">
  <div class="admin-wrap settings-shell">

    <?php if ($err): ?>
      <div class="settings-flash is-error"><?= h($err) ?></div>
    <?php endif; ?>

    <?php if ($msg): ?>
      <div class="settings-flash is-success">✅ <?= h($msg) ?></div>
    <?php endif; ?>

    <section class="settings-topbar">
      <div class="settings-hero-main">
        <div class="hero-store-name"><?= h((string)($store['name'] ?? ('#' . $storeId))) ?></div>
        <span class="hero-badge">管理者向け設定</span>
        <h1>店舗ルールをまとめて整える画面</h1>
        <p>営業日の切替時刻、週末判定、遅刻LINE、位置情報までをひとつの流れで見直せるように再構成しています。</p>
        <div class="settings-pills">
          <span class="settings-pill">店舗ID #<?= (int)$storeId ?></span>
          <span class="settings-pill"><?= ((int)($store['is_active'] ?? 0) === 1) ? '有効店舗' : '無効店舗' ?></span>
          <span class="settings-pill">コード <?= h((string)($store['code'] ?? '-')) ?></span>
        </div>
      </div>

      <div class="settings-topbar-side">
        <?php if ($isSuper): ?>
          <form method="get" class="store-switch" action="/wbss/public/store_settings.php">
            <div class="store-switch-label">表示店舗を切り替え</div>
            <div class="store-switch-row">
              <select class="sel store-switch-select" name="store_id" onchange="this.form.submit()">
                <?php foreach ($stores as $s): ?>
                  <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id'] === (int)$storeId) ? 'selected' : '' ?>>
                    <?= h((string)$s['name']) ?><?= ((int)$s['is_active'] === 1) ? '' : '（無効）' ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="store-switch-help">選んだ瞬間にこの画面の対象店舗が切り替わります。</div>

          </form>
        <?php else: ?>
          <div class="store-switch">
            <div class="store-switch-label">現在の対象店舗</div>
            <div class="store-switch-help">管理者は自分に紐づく店舗のみ編集できます。</div>
          </div>
        <?php endif; ?>

        <div class="settings-quicklist">
          <div class="settings-quickitem">
            <div class="settings-quickicon">🕒</div>
            <div class="settings-quicktext">
              <div class="settings-quicktitle">営業ルール</div>
              <div class="settings-quickdesc">営業日切替 <?= h(format_time_hm((string)($store['business_day_start'] ?? ''), '06:00')) ?> / 開店 <?= h(format_time_hm((string)($store['open_time'] ?? ''), '20:00')) ?></div>
            </div>
          </div>
          <div class="settings-quickitem">
            <div class="settings-quickicon">📡</div>
            <div class="settings-quicktext">
              <div class="settings-quicktitle">遅刻LINE</div>
              <div class="settings-quickdesc"><?= ((int)($store['late_notice_auto_enabled'] ?? 1) === 1) ? '自動送信ON' : '自動送信OFF' ?> / <?= (int)($store['late_notice_delay_minutes'] ?? 10) ?>分後</div>
            </div>
          </div>
          <div class="settings-quickitem">
            <div class="settings-quickicon">💬</div>
            <div class="settings-quicktext">
              <div class="settings-quicktitle">出勤確認LINE</div>
              <div class="settings-quickdesc"><?= ((int)($store['attendance_confirm_auto_enabled'] ?? 1) === 1) ? '自動送信ON' : '自動送信OFF' ?> / <?= (int)($store['attendance_confirm_lead_hours'] ?? 3) ?>時間前</div>
            </div>
          </div>
          <div class="settings-quickitem">
            <div class="settings-quickicon">📍</div>
            <div class="settings-quicktext">
              <div class="settings-quicktitle">位置判定</div>
              <div class="settings-quickdesc"><?= ((string)($store['lat'] ?? '') !== '' && (string)($store['lon'] ?? '') !== '') ? '座標設定済み' : '未設定' ?> / 半径 <?= (int)($store['radius_m'] ?? 150) ?>m</div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <form method="post" class="settings-form">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <?php if ($isSuper): ?>
        <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
      <?php endif; ?>

      <div class="settings-layout">
        <div class="settings-main">
          <section class="settings-section">
            <div class="settings-section-head">
              <h2>営業時間ルール</h2>
              <p>営業日の切替、開閉店時刻、週末扱いの曜日、遅刻LINEの基準をまとめて管理します。</p>
            </div>

            <div class="settings-grid settings-grid--hours">
              <div class="settings-field">
                <label class="settings-label">営業日切替</label>
                <input class="settings-control" type="time" step="60" name="business_day_start" value="<?= h(format_time_hm((string)($store['business_day_start'] ?? ''), '06:00')) ?>">
                <div class="settings-hint">日付をいつ切り替えるかの基準時刻です。</div>
              </div>

              <div class="settings-field">
                <label class="settings-label">開店</label>
                <input class="settings-control" type="time" step="60" name="open_time" value="<?= h(format_time_hm((string)($store['open_time'] ?? ''), '20:00')) ?>">
              </div>

              <div class="settings-field">
                <label class="settings-label">閉店（平日）</label>
                <input class="settings-control" type="time" step="60" name="close_time_weekday" value="<?= h(format_time_hm((string)($store['close_time_weekday'] ?? ''), '02:30')) ?>">
              </div>

              <div class="settings-field">
                <label class="settings-label">閉店日付（平日）</label>
                <select class="settings-control" name="close_is_next_day_weekday">
                  <option value="1" <?= ((int)($store['close_is_next_day_weekday'] ?? 1)===1)?'selected':'' ?>>翌日</option>
                  <option value="0" <?= ((int)($store['close_is_next_day_weekday'] ?? 1)===0)?'selected':'' ?>>当日</option>
                </select>
              </div>

              <div class="settings-field">
                <label class="settings-label">閉店（週末）</label>
                <input class="settings-control" type="time" step="60" name="close_time_weekend" value="<?= h(format_time_hm((string)($store['close_time_weekend'] ?? ''), '05:00')) ?>">
              </div>

              <div class="settings-field">
                <label class="settings-label">閉店日付（週末）</label>
                <select class="settings-control" name="close_is_next_day_weekend">
                  <option value="1" <?= ((int)($store['close_is_next_day_weekend'] ?? 1)===1)?'selected':'' ?>>翌日</option>
                  <option value="0" <?= ((int)($store['close_is_next_day_weekend'] ?? 1)===0)?'selected':'' ?>>当日</option>
                </select>
              </div>

              <div class="settings-field settings-field--wide">
                <label class="settings-label">週末扱いの曜日</label>
                <div class="settings-checkboxes">
                  <?php foreach ($weekendDowOptions as $dowBit => $dowLabel): ?>
                    <label class="settings-check">
                      <input
                        type="checkbox"
                        name="weekend_dows[]"
                        value="<?= (int)$dowBit ?>"
                        <?= (($weekendDowMask & $dowBit) === $dowBit) ? 'checked' : '' ?>
                      >
                      <span><?= h($dowLabel) ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>

              <?php if ($hasWeeklyHolidayDow): ?>
                <div class="settings-field">
                  <label class="settings-label">店休日</label>
                  <select class="settings-control" name="weekly_holiday_dow">
                    <?php foreach ($weeklyHolidayOptions as $holidayValue => $holidayLabel): ?>
                      <option value="<?= h((string)$holidayValue) ?>" <?= ($weeklyHolidayDowValue === (string)$holidayValue) ? 'selected' : '' ?>>
                        <?= h((string)$holidayLabel) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
              <?php endif; ?>

              <div class="settings-field">
                <label class="settings-label">遅刻 LINE 自動送信</label>
                <select class="settings-control" name="late_notice_auto_enabled">
                  <option value="1" <?= ((int)($store['late_notice_auto_enabled'] ?? 1)===1)?'selected':'' ?>>ON</option>
                  <option value="0" <?= ((int)($store['late_notice_auto_enabled'] ?? 1)===0)?'selected':'' ?>>OFF</option>
                </select>
              </div>

              <div class="settings-field settings-field--full">
                <label class="settings-label">遅刻 LINE 自動送信までの分数</label>
                <input class="settings-control" name="late_notice_delay_minutes" inputmode="numeric" value="<?= h((string)($store['late_notice_delay_minutes'] ?? 10)) ?>">
                <div class="settings-hint">出勤予定時刻からこの分数を過ぎたら自動通知します。</div>
              </div>

              <div class="settings-field">
                <label class="settings-label">出勤確認 LINE 自動送信</label>
                <select class="settings-control" name="attendance_confirm_auto_enabled">
                  <option value="1" <?= ((int)($store['attendance_confirm_auto_enabled'] ?? 1)===1)?'selected':'' ?>>ON</option>
                  <option value="0" <?= ((int)($store['attendance_confirm_auto_enabled'] ?? 1)===0)?'selected':'' ?>>OFF</option>
                </select>
              </div>

              <div class="settings-field settings-field--full">
                <label class="settings-label">出勤確認LINEを営業時間の何時間前に送るか</label>
                <input class="settings-control" name="attendance_confirm_lead_hours" inputmode="numeric" value="<?= h((string)($store['attendance_confirm_lead_hours'] ?? 3)) ?>">時間前
                <div class="settings-hint">当日の出勤予定時刻の何時間前に出勤確認LINEを送るか設定します。</div>
                <div class="settings-inline-row">
                  <div>
                    <label class="settings-label" for="attendance_confirm_test_user_id">テスト送信先を選ぶ</label>
                    <select class="settings-control" name="attendance_confirm_test_user_id" id="attendance_confirm_test_user_id">
                      <option value="">送信先を選択</option>
                      <?php foreach ($attendanceConfirmTestCandidates as $candidate): ?>
                        <?php
                          $candidateName = trim((string)($candidate['display_name'] ?? ''));
                          $candidateTag = trim((string)($candidate['shop_tag'] ?? ''));
                          $candidateLabel = $candidateName !== '' ? $candidateName : ('user#' . (int)($candidate['user_id'] ?? 0));
                          if ($candidateTag !== '') {
                            $candidateLabel = '店番' . $candidateTag . ' / ' . $candidateLabel;
                          }
                        ?>
                        <option value="<?= (int)($candidate['user_id'] ?? 0) ?>" <?= ((int)($candidate['user_id'] ?? 0) === $selectedAttendanceConfirmTestUserId) ? 'selected' : '' ?>>
                          <?= h($candidateLabel) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <button class="btn" type="submit" name="action" value="send_attendance_confirm_test" <?= $attendanceConfirmTestCandidates === [] ? 'disabled' : '' ?>>出勤確認LINEをテスト送信</button>
                </div>
                <div class="settings-hint">本番の自動送信とは別です。LINE連携済みの在籍キャストにだけ送れます。</div>
              </div>
            </div>

            <div class="settings-note-list">
              <div class="settings-note">チェックした曜日にだけ「閉店（週末）」設定が使われます。</div>
              <div class="settings-note">`stores.weekly_holiday_dow` がある環境では、この画面から店休日も一緒に管理できます。</div>
              <div class="settings-note">出勤確認LINEは「出勤します / 遅れます / 休みます / その他」のクイックリプライを送る前提です。</div>
            </div>
          </section>

          <section class="settings-section">
            <div class="settings-section-head">
              <h2>位置情報とLINE出勤</h2>
              <p>LINE出勤の距離判定で使う座標と許可半径です。未設定のまま運用する場合の挙動もここで確認しやすくしています。</p>
            </div>

            <div class="settings-grid settings-grid--two">
              <div class="settings-field">
                <label class="settings-label">緯度 lat</label>
                <input class="settings-control" name="lat" value="<?= h((string)($store['lat'] ?? '')) ?>" placeholder="例: 35.681236">
                <div class="settings-hint">Googleマップ等で確認した店舗の緯度を入れます。</div>
              </div>

              <div class="settings-field">
                <label class="settings-label">経度 lon</label>
                <input class="settings-control" name="lon" value="<?= h((string)($store['lon'] ?? '')) ?>" placeholder="例: 139.767125">
                <div class="settings-hint">緯度と経度はセットで登録してください。</div>
              </div>

              <div class="settings-field settings-field--full">
                <label class="settings-label">許可半径 (m)</label>
                <input class="settings-control" name="radius_m" inputmode="numeric" value="<?= h((string)($store['radius_m'] ?? 150)) ?>">
                <div class="settings-hint">店舗の入口やビルの位置に合わせて、出勤判定の許容範囲を調整します。</div>
              </div>
            </div>

            <div class="settings-note-list">
              <div class="settings-note">緯度 / 経度 が未設定だと、LINE出勤時に「店舗位置未設定」として扱う設計にしておくと安全です。</div>
              <div class="settings-note">半径を広げすぎると近隣からの誤判定が増えるので、まずは 100m から 200m の範囲で調整するのが無難です。</div>
            </div>
          </section>
        </div>

        <aside class="settings-side">
          <section class="settings-side-card">
            <h3>今の設定サマリー</h3>
            <p>保存前に今の店舗ルールをざっと確認できます。</p>
            <div class="settings-kpis">
              <div class="settings-kpi">
                <div class="settings-kpi-label">営業日切替</div>
                <div class="settings-kpi-value"><?= h(format_time_hm((string)($store['business_day_start'] ?? ''), '06:00')) ?></div>
                <div class="settings-kpi-note">営業日の区切りに使う時刻</div>
              </div>
              <div class="settings-kpi">
                <div class="settings-kpi-label">平日 / 週末の閉店</div>
                <div class="settings-kpi-value"><?= h(format_time_hm((string)($store['close_time_weekday'] ?? ''), '02:30')) ?> / <?= h(format_time_hm((string)($store['close_time_weekend'] ?? ''), '05:00')) ?></div>
                <div class="settings-kpi-note">週末判定は選択した曜日だけ反映されます。</div>
              </div>
              <div class="settings-kpi">
                <div class="settings-kpi-label">遅刻LINE</div>
                <div class="settings-kpi-value"><?= ((int)($store['late_notice_auto_enabled'] ?? 1) === 1) ? 'ON' : 'OFF' ?></div>
                <div class="settings-kpi-note"><?= (int)($store['late_notice_delay_minutes'] ?? 10) ?>分後に自動送信</div>
              </div>
              <div class="settings-kpi">
                <div class="settings-kpi-label">位置判定</div>
                <div class="settings-kpi-value"><?= (int)($store['radius_m'] ?? 150) ?>m</div>
                <div class="settings-kpi-note"><?= ((string)($store['lat'] ?? '') !== '' && (string)($store['lon'] ?? '') !== '') ? '座標登録済み' : '座標未設定' ?></div>
              </div>
            </div>
          </section>

          <section class="settings-side-card" id="settings-save">
            <h3>保存</h3>
            <p>この画面の内容をまとめて更新します。右側のサマリーを見比べながらそのまま保存できます。</p>
            <div class="settings-save-box">
              <div class="settings-note">対象店舗: <?= h((string)($store['name'] ?? '')) ?></div>
              <button class="btn btn-primary settings-save-btn" type="submit">設定を更新</button>
            </div>
          </section>
        </aside>
      </div>
    </form>

  </div>
</div>

<?php render_page_end(); ?>
