<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';

/**
 * Manager daily schedule dashboard.
 *
 * Canonical rules:
 * - plan source of truth: cast_shift_plans
 * - actual source of truth: attendances
 */

require_login();
require_role(['manager','admin','super_user']);

$pdo = db();

/* =========================
   Utils (safe)
========================= */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('csrf_token')) {
  function csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['_csrf'];
  }
}
if (!function_exists('csrf_verify')) {
  function csrf_verify(?string $token): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $ok = is_string($token) && $token !== '' && isset($_SESSION['_csrf']) && hash_equals((string)$_SESSION['_csrf'], $token);
    if (!$ok) {
      http_response_code(403);
      header('Content-Type: application/json; charset=UTF-8');
      echo json_encode(['ok'=>false,'error'=>'csrf'], JSON_UNESCAPED_UNICODE);
      exit;
    }
  }
}

if (!function_exists('has_role')) {
  function has_role(string $role): bool {
    return isset($_SESSION['roles']) && in_array($role, $_SESSION['roles'], true);
  }
}
if (!function_exists('current_user_id')) {
  function current_user_id(): int {
    return (int)($_SESSION['user_id'] ?? 0);
  }
}

function jst_now(): DateTime { return new DateTime('now', new DateTimeZone('Asia/Tokyo')); }
function dow0(DateTime $d): int { return (int)$d->format('w'); } // 0=Sun..6=Sat

function normalize_date(string $ymd, ?string $fallback=null): string {
  $ymd = trim($ymd);
  if ($ymd === '') $ymd = (string)$fallback;
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
    return (new DateTime('today', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
  }
  return $ymd;
}

function week_dates(string $weekStartYmd): array {
  $dt = new DateTime($weekStartYmd, new DateTimeZone('Asia/Tokyo'));
  $out = [];
  for ($i=0; $i<7; $i++) { $out[] = $dt->format('Y-m-d'); $dt->modify('+1 day'); }
  return $out;
}

function week_start_by_dow(string $baseYmd, int $weekStartDow0): string {
  $dt = new DateTime($baseYmd, new DateTimeZone('Asia/Tokyo'));
  $cur = (int)$dt->format('w');
  $diff = ($cur - $weekStartDow0 + 7) % 7;
  if ($diff > 0) $dt->modify("-{$diff} days");
  return $dt->format('Y-m-d');
}

if (!function_exists('business_date_for_store')) {
  function business_date_for_store(array $storeRow, ?DateTime $now=null): string {
    $now = $now ?: jst_now();
    $cut = (string)($storeRow['business_day_start'] ?? '06:00:00');
    $cutDT = new DateTime($now->format('Y-m-d') . ' ' . $cut, new DateTimeZone('Asia/Tokyo'));
    if ($now < $cutDT) $now->modify('-1 day');
    return $now->format('Y-m-d');
  }
}

function current_staff_store_id(PDO $pdo, int $userId): int {
  $st = $pdo->prepare("
    SELECT ur.store_id
    FROM user_roles ur
    JOIN roles r ON r.id = ur.role_id
    WHERE ur.user_id = ?
      AND r.code IN ('admin','manager','super_user')
      AND ur.store_id IS NOT NULL
    ORDER BY ur.store_id ASC
    LIMIT 1
  ");
  $st->execute([$userId]);
  return (int)($st->fetchColumn() ?: 0);
}

/* =========================
   Store resolve
========================= */
$isSuper   = has_role('super_user');
$isAdmin   = has_role('admin');
$isManager = has_role('manager');

$userId = (int)current_user_id();

if ($isSuper) {
  $storeId = (int)($_GET['store_id'] ?? ($_POST['store_id'] ?? 0));
  if ($storeId <= 0) {
    $storeId = (int)($pdo->query("SELECT id FROM stores WHERE is_active=1 ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
  }
} else {
  $storeId = current_staff_store_id($pdo, $userId);
}

if ($storeId <= 0) {
  http_response_code(400);
  exit('店舗が特定できません（user_roles の admin/manager に store_id を設定してください）');
}

$st = $pdo->prepare("
  SELECT id,name,business_day_start,weekly_holiday_dow,open_time
  FROM stores WHERE id=? LIMIT 1
");
$st->execute([$storeId]);
$store = $st->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$store) exit('店舗が見つかりません');

/* =========================
   Business date (GET優先)
========================= */
$bizDate = (string)($_GET['business_date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bizDate)) {
  $bizDate = business_date_for_store($store);
}
$now = jst_now();

/* =========================
   LINE helpers
========================= */
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
  $err  = curl_error($ch);
  curl_close($ch);

  return [
    'code' => $code,
    'body' => is_string($res) ? $res : '',
    'curl_error' => $err,
  ];
}

function resolve_line_user_id(PDO $pdo, int $castUserId): string {
  $st = $pdo->prepare("
    SELECT provider_user_id
    FROM user_identities
    WHERE user_id=? AND provider='line' AND is_active=1
    ORDER BY id DESC
    LIMIT 1
  ");
  $st->execute([$castUserId]);
  return (string)($st->fetchColumn() ?: '');
}

function table_has_column(PDO $pdo, string $table, string $column): bool {
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

function notice_kind_label(string $kind, ?string $replyChoice=null): string {
  return match ($kind) {
    'attendance_confirm' => match ($replyChoice) {
      'confirm' => '出勤確認: 出勤します',
      'late' => '出勤確認: 遅れます',
      'absent' => '出勤確認: 休みます',
      default => '出勤確認',
    },
    'absent' => '欠勤',
    default => '遅刻',
  };
}

function redirect_self(int $storeId, string $bizDate): void {
  $qs = http_build_query([
    'store_id' => $storeId,
    'business_date' => $bizDate,
  ]);
  header('Location: /wbss/public/manager_today_schedule.php?' . $qs);
  exit;
}

function shift_plan_log_label(array $state): string {
  if (empty($state['on'])) {
    return '休み';
  }

  $time = trim((string)($state['time'] ?? ''));
  $end = strtoupper(trim((string)($state['end'] ?? 'LAST')));
  if ($time === '') {
    return '出勤';
  }

  return $time . ' - ' . ($end !== '' ? $end : 'LAST');
}

function shift_plan_change_summary(array $payload): string {
  $before = is_array($payload['before'] ?? null) ? $payload['before'] : [];
  $after = is_array($payload['after'] ?? null) ? $payload['after'] : [];
  return shift_plan_log_label($before) . ' → ' . shift_plan_log_label($after);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'confirm_attendance_response') {
  csrf_verify($_POST['csrf_token'] ?? null);

  $postStoreId = (int)($_POST['store_id'] ?? 0);
  if ($postStoreId !== $storeId && !$isSuper) {
    http_response_code(400);
    exit('store');
  }

  $businessDate = normalize_date((string)($_POST['business_date'] ?? ''), $bizDate);
  $noticeActionId = (int)($_POST['notice_action_id'] ?? 0);
  if ($noticeActionId <= 0) {
    http_response_code(400);
    exit('notice');
  }

  $st = $pdo->prepare("
    UPDATE line_notice_actions
    SET manager_confirmed_at = NOW(),
        manager_confirmed_by_user_id = ?,
        updated_at = NOW()
    WHERE id = ?
      AND store_id = ?
      AND business_date = ?
      AND kind = 'attendance_confirm'
      AND reply_choice = 'confirm'
      AND responded_at IS NOT NULL
      AND manager_confirmed_at IS NULL
    LIMIT 1
  ");
  $st->execute([$userId, $noticeActionId, $storeId, $businessDate]);

  redirect_self($storeId, $businessDate);
}

/* =========================
   AJAX: send_notice
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'send_notice') {
  header('Content-Type: application/json; charset=UTF-8');

  csrf_verify($_POST['csrf_token'] ?? null);

  $postStoreId = (int)($_POST['store_id'] ?? 0);
  if ($postStoreId !== $storeId && !$isSuper) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'store'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $kind = (string)($_POST['kind'] ?? '');
  if (!in_array($kind, ['late','absent'], true)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'kind'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $castUserId = (int)($_POST['cast_user_id'] ?? 0);
  if ($castUserId <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'cast'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $businessDate = normalize_date((string)($_POST['business_date'] ?? ''), $bizDate);

  $text = trim((string)($_POST['text'] ?? ''));
  if ($text === '' || mb_strlen($text, 'UTF-8') > 1000) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'text'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // cast belongs to this store?
  $st = $pdo->prepare("
    SELECT COUNT(*)
    FROM user_roles ur
    JOIN roles r ON r.id=ur.role_id AND r.code='cast'
    WHERE ur.user_id=? AND ur.store_id=?
  ");
  $st->execute([$castUserId, $storeId]);
  if ((int)$st->fetchColumn() <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'cast_store'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $lineTo = resolve_line_user_id($pdo, $castUserId);
  if ($lineTo === '') {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'line_unlinked'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $accessToken = conf('LINE_MSG_CHANNEL_ACCESS_TOKEN');
  if ($accessToken === '') {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'line_token_missing'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $token = bin2hex(random_bytes(12)); // 24 chars
  $templateText = (string)($_POST['template_text'] ?? $text);

  $pdo->beginTransaction();
  try {
    $ins = $pdo->prepare("
      INSERT INTO line_notice_actions
        (store_id, business_date, cast_user_id, kind, token, template_text, sent_text,
         sent_by_user_id, sent_at, status, error_message, created_at, updated_at)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'sent', NULL, NOW(), NOW())
    ");
    $ins->execute([
      $storeId, $businessDate, $castUserId, $kind, $token,
      $templateText, $text, $userId
    ]);

    $api = line_api_post($accessToken, 'message/push', [
      'to' => $lineTo,
      'messages' => [
        ['type'=>'text', 'text'=>$text],
      ],
    ]);

    if ($api['code'] >= 300) {
      $errMsg = 'HTTP ' . $api['code'];
      if ($api['curl_error'] !== '') $errMsg .= ' curl=' . $api['curl_error'];

      $upd = $pdo->prepare("
        UPDATE line_notice_actions
        SET status='failed', error_message=?, updated_at=NOW()
        WHERE token=? LIMIT 1
      ");
      $upd->execute([$errMsg, $token]);

      $pdo->commit();
      http_response_code(500);
      echo json_encode(['ok'=>false,'error'=>'line_push_failed','detail'=>$errMsg], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $pdo->commit();
    echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
    exit;

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'exception','detail'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

/* =========================
   Week range (holiday翌日を週開始)
========================= */
$holidayDow = $store['weekly_holiday_dow'];
$holidayDow = ($holidayDow === null) ? null : (int)$holidayDow; // 0=Sun..6=Sat
$weekStartDow0 = ($holidayDow === null) ? 1 : (($holidayDow + 1) % 7);

$calDate  = (new DateTime('today', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
$weekStart = week_start_by_dow($calDate, $weekStartDow0);
$dates = week_dates($weekStart);
$displayDates = array_values(array_filter($dates, static function(string $d) use ($holidayDow): bool {
  if ($holidayDow === null) return true;
  $dt = new DateTime($d, new DateTimeZone('Asia/Tokyo'));
  return ((int)$dt->format('w') !== $holidayDow);
}));

/* =========================
   今日（canonical plan × actual） + 店番(shop_tag)
   - plan source: cast_shift_plans
   - actual source: attendances
   - 母集団は v_store_casts_active（store_users.status='active' + users.is_active）
========================= */
$st = $pdo->prepare("
  SELECT
    c.user_id,
    c.display_name,
    c.user_is_active AS is_active,
    c.employment_type,
    c.shop_tag,

    sp.start_time AS plan_start_time,
    sp.is_off     AS plan_is_off,
    (sp.user_id IS NOT NULL) AS has_plan,

    a.clock_in,
    a.clock_out,
    a.status AS attendance_status
  FROM v_store_casts_active c

  LEFT JOIN cast_shift_plans sp
    ON sp.store_id = c.store_id
   AND sp.user_id  = c.user_id
   AND sp.business_date = ?
   AND sp.status = 'planned'

  LEFT JOIN attendances a
    ON a.store_id = c.store_id
   AND a.user_id  = c.user_id
   AND a.business_date = ?

  WHERE c.store_id = ?
    AND COALESCE(c.shop_tag, '') <> ''

  ORDER BY
    CASE WHEN c.shop_tag='' THEN 999999 ELSE CAST(c.shop_tag AS UNSIGNED) END ASC,
    c.display_name ASC,
    c.user_id ASC
");
$st->execute([$bizDate, $bizDate, $storeId]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$statePriority = [
  'in' => 0,
  'late' => 1,
  'absent' => 2,
  'wait' => 3,
  'done' => 4,
  'off' => 5,
  'noplan' => 6,
];

usort($rows, static function(array $a, array $b) use ($bizDate, $now, $statePriority): int {
  $resolveState = static function(array $row) use ($bizDate, $now): string {
    $hasPlan   = ((int)($row['has_plan'] ?? 0) === 1);
    $planOff   = $hasPlan && ((int)($row['plan_is_off'] ?? 0) === 1);
    $planStart = $hasPlan ? (string)($row['plan_start_time'] ?? '') : '';
    $attendanceStatus = (string)($row['attendance_status'] ?? '');

    $isLate = false;
    if ($attendanceStatus !== 'absent' && $hasPlan && !$planOff && $planStart !== '' && empty($row['clock_in'])) {
      $planDT = new DateTime($bizDate . ' ' . substr($planStart, 0, 5) . ':00', new DateTimeZone('Asia/Tokyo'));
      if ($now > $planDT) $isLate = true;
    }

    if (!$hasPlan) return 'noplan';
    if ($planOff) return 'off';
    if ($attendanceStatus === 'absent') return 'absent';
    if (!empty($row['clock_out'])) return 'done';
    if (!empty($row['clock_in'])) return 'in';
    return $isLate ? 'late' : 'wait';
  };

  $stateA = $resolveState($a);
  $stateB = $resolveState($b);

  $prioA = $statePriority[$stateA] ?? 999;
  $prioB = $statePriority[$stateB] ?? 999;
  if ($prioA !== $prioB) return $prioA <=> $prioB;

  $tagA = trim((string)($a['shop_tag'] ?? ''));
  $tagB = trim((string)($b['shop_tag'] ?? ''));
  $tagNumA = ctype_digit($tagA) ? (int)$tagA : 999999;
  $tagNumB = ctype_digit($tagB) ? (int)$tagB : 999999;
  if ($tagNumA !== $tagNumB) return $tagNumA <=> $tagNumB;

  if ($tagA !== $tagB) return strcmp($tagA, $tagB);

  $nameCmp = strcmp((string)($a['display_name'] ?? ''), (string)($b['display_name'] ?? ''));
  if ($nameCmp !== 0) return $nameCmp;

  return ((int)($a['user_id'] ?? 0)) <=> ((int)($b['user_id'] ?? 0));
});

/* =========================
   送信/返信 履歴（営業日分）
========================= */
$noticeMap = []; // [user_id][kind] => latest row
$st = $pdo->prepare("
  SELECT a.*, su.login_id AS sender_login
  FROM line_notice_actions a
  LEFT JOIN users su ON su.id=a.sent_by_user_id
  WHERE a.store_id=? AND a.business_date=?
  ORDER BY a.sent_at DESC
");
$st->execute([$storeId, $bizDate]);
foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $a) {
  $uid = (int)$a['cast_user_id'];
  $k   = (string)$a['kind'];
  if (!isset($noticeMap[$uid][$k])) $noticeMap[$uid][$k] = $a;
}

/* =========================
   直近の予定変更
========================= */
$recentPlanChanges = [];
try {
  $st = $pdo->prepare("
    SELECT
      l.id,
      l.user_id,
      l.payload_json,
      l.created_at,
      cu.display_name AS cast_name,
      au.display_name AS actor_name
    FROM cast_shift_logs l
    LEFT JOIN users cu ON cu.id = l.user_id
    LEFT JOIN users au ON au.id = l.created_by_user_id
    WHERE l.store_id = ?
      AND l.action = 'cast.shift.plan_changed'
    ORDER BY l.created_at DESC, l.id DESC
    LIMIT 12
  ");
  $st->execute([$storeId]);
  $lastRecentPlanChangeUserId = null;
  foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
    $payload = json_decode((string)($row['payload_json'] ?? ''), true);
    if (!is_array($payload)) {
      continue;
    }
    $recentUserId = (int)($row['user_id'] ?? 0);
    if ($lastRecentPlanChangeUserId === $recentUserId) {
      continue;
    }
    $recentPlanChanges[] = [
      'cast_name' => (string)($row['cast_name'] ?? ('user#' . (int)($row['user_id'] ?? 0))),
      'actor_name' => trim((string)($row['actor_name'] ?? '')),
      'created_at' => (string)($row['created_at'] ?? ''),
      'business_date' => (string)($payload['business_date'] ?? ''),
      'summary' => shift_plan_change_summary($payload),
    ];
    $lastRecentPlanChangeUserId = $recentUserId;
    if (count($recentPlanChanges) >= 12) {
      break;
    }
  }
} catch (Throwable $e) {}

/* =========================
   今週予定（7日, canonical: cast_shift_plans）
========================= */
$weekPlans = []; // [user_id][ymd] => ['start_time'=>'21:00', 'is_off'=>1]
$st = $pdo->prepare("
  SELECT user_id, business_date, start_time, is_off
  FROM cast_shift_plans
  WHERE store_id=?
    AND business_date BETWEEN ? AND ?
    AND status='planned'
");
$st->execute([$storeId, $dates[0], $dates[6]]);
foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $p) {
  $uid = (int)$p['user_id'];
  $d   = (string)$p['business_date'];
  $weekPlans[$uid][$d] = [
    'start_time' => $p['start_time'] ? substr((string)$p['start_time'], 0, 5) : '',
    'is_off' => ((int)$p['is_off'] === 1),
  ];
}

$weekAtt = []; // [user_id][ymd] => ['in'=>'21:05','out'=>'02:10','status'=>'absent']
$st = $pdo->prepare("
  SELECT user_id, business_date, clock_in, clock_out, status
  FROM attendances
  WHERE store_id=?
    AND business_date BETWEEN ? AND ?
");
$st->execute([$storeId, $dates[0], $dates[6]]);
foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $a) {
  $uid = (int)$a['user_id'];
  $d   = (string)$a['business_date'];
  $weekAtt[$uid][$d] = [
    'in'  => $a['clock_in'] ? substr((string)$a['clock_in'], 11, 5) : '',
    'out' => $a['clock_out'] ? substr((string)$a['clock_out'], 11, 5) : '',
    'status' => (string)($a['status'] ?? ''),
  ];
}

/* =========================
   KPI（cast_shift_plans に planned 行がある人だけ）
========================= */
$cnt = [
  'planned'  => 0,
  'not_in'   => 0,
  'working'  => 0,
  'finished' => 0,
  'off'      => 0,
  'late'     => 0,
  'absent'   => 0,
];

foreach ($rows as $r) {
  $hasPlan = ((int)($r['has_plan'] ?? 0) === 1);
  if (!$hasPlan) continue; // 予定が無い人は計算対象外

  $isOff = ((int)($r['plan_is_off'] ?? 0) === 1);
  if ($isOff) { $cnt['off']++; continue; }

  $cnt['planned']++;

  $clockIn  = $r['clock_in'] ?? null;
  $clockOut = $r['clock_out'] ?? null;
  $attendanceStatus = (string)($r['attendance_status'] ?? '');

  if ($attendanceStatus === 'absent') { $cnt['absent']++; continue; }

  if ($clockOut) { $cnt['finished']++; continue; }
  if ($clockIn)  { $cnt['working']++;  continue; }

  $cnt['not_in']++;

  $pst = (string)($r['plan_start_time'] ?? '');
  if ($pst !== '') {
    $planDT = new DateTime($bizDate.' '.substr($pst,0,5).':00', new DateTimeZone('Asia/Tokyo'));
    if ($now > $planDT) $cnt['late']++;
  }
}

$attentionCount = 0;
$replyCount = 0;
$confirmPendingCount = 0;
foreach ($rows as $r) {
  $hasPlan = ((int)($r['has_plan'] ?? 0) === 1);
  $planOff = $hasPlan && ((int)($r['plan_is_off'] ?? 0) === 1);
  $planStart = $hasPlan ? (string)($r['plan_start_time'] ?? '') : '';
  $attendanceStatus = (string)($r['attendance_status'] ?? '');
  $isLate = false;
  if ($attendanceStatus !== 'absent' && $hasPlan && !$planOff && $planStart !== '' && empty($r['clock_in'])) {
    $planDT = new DateTime($bizDate . ' ' . substr($planStart, 0, 5) . ':00', new DateTimeZone('Asia/Tokyo'));
    if ($now > $planDT) {
      $isLate = true;
    }
  }

  $uid = (int)($r['user_id'] ?? 0);
  $attendanceConfirmNotice = $noticeMap[$uid]['attendance_confirm'] ?? null;
  $showConfirmButton = $attendanceConfirmNotice
    && (string)($attendanceConfirmNotice['reply_choice'] ?? '') === 'confirm'
    && !empty($attendanceConfirmNotice['responded_at'])
    && empty($attendanceConfirmNotice['manager_confirmed_at']);

  $hasReply = false;
  foreach (['late', 'absent', 'attendance_confirm'] as $kind) {
    if (!empty($noticeMap[$uid][$kind]['last_reply_text'])) {
      $hasReply = true;
      break;
    }
  }

  if ($hasReply) {
    $replyCount++;
  }
  if ($showConfirmButton) {
    $confirmPendingCount++;
  }
  if ($isLate || $attendanceStatus === 'absent' || $showConfirmButton) {
    $attentionCount++;
  }
}

/* =========================
   Render
========================= */
$dashboardUrl = '/wbss/public/dashboard.php';
$attendanceUrl = '/wbss/public/attendance/index.php?store_id=' . (int)$storeId . '&date=' . urlencode($bizDate);
$transportUrl = '/wbss/public/transport_routes.php?store_id=' . (int)$storeId . '&business_date=' . urlencode($bizDate);
$headerActions = '
  <a class="btn" href="' . h($transportUrl) . '">送迎ルートへ</a>
  <a class="btn" href="' . h($attendanceUrl) . '">出勤一覧へ</a>
  <a class="btn" href="' . h($dashboardUrl) . '">ダッシュボード</a>
';

render_page_start('本日の勤務予定');
render_header('本日の勤務予定', [
  'back_href' => $dashboardUrl,
  'back_label' => '← ダッシュボード',
  'right_html' => $headerActions,
  'show_store' => false,
  'show_user' => false,
]);
?>
<div class="page">
  <div class="admin-wrap">

    <div class="pageHero">
      <div class="rowTop">
        <div class="titleWrap">
          <div class="title">勤務予定 × 実績</div>
          <div class="heroMeta">
            <span class="heroChip">営業日 <b><?= h($bizDate) ?></b></span>
            <span class="heroChip">現在 <b><?= h($now->format('Y-m-d H:i')) ?></b></span>
          </div>
        </div>
      </div>

      <div class="subInfo">
        <div class="muted">
          予定の正本は <b>cast_shift_plans</b>、実績の正本は <b>attendances</b> です。
        </div>
      </div>
    </div>

    <?php if ($isSuper): ?>
      <?php $stores = $pdo->query("SELECT id,name,is_active FROM stores ORDER BY is_active DESC, id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: []; ?>
      <form method="get" class="searchRow">
        <label class="muted">店舗</label>
        <select name="store_id" class="sel">
          <?php foreach ($stores as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id']===$storeId)?'selected':'' ?>>
              <?= h((string)$s['name']) ?> (#<?= (int)$s['id'] ?>)
            </option>
          <?php endforeach; ?>
        </select>

        <label class="muted">営業日</label>
        <input class="sel" type="date" name="business_date" value="<?= h($bizDate) ?>">

        <button class="btn">表示</button>
      </form>
    <?php endif; ?>

    <!-- KPI（後でボタン化/フィルタ化する前提で class を付けておく） -->
    <div class="kpi kpiBtns" id="kpi">
      <div class="k" data-filter="planned"><span class="kLabel">勤務予定</span><b class="kValue"><?= (int)$cnt['planned'] ?></b></div>
      <div class="k" data-filter="wait"><span class="kLabel">未出勤</span><b class="kValue"><?= (int)$cnt['not_in'] ?></b></div>
      <div class="k" data-filter="in"><span class="kLabel">出勤中</span><b class="kValue"><?= (int)$cnt['working'] ?></b></div>
      <div class="k" data-filter="absent"><span class="kLabel">欠勤</span><b class="kValue"><?= (int)$cnt['absent'] ?></b></div>
      <div class="k" data-filter="done"><span class="kLabel">退勤済</span><b class="kValue"><?= (int)$cnt['finished'] ?></b></div>
      <div class="k" data-filter="off"><span class="kLabel">休み</span><b class="kValue"><?= (int)$cnt['off'] ?></b></div>
      <div class="k" data-filter="late"><span class="kLabel">遅刻</span><b class="kValue"><?= (int)$cnt['late'] ?></b></div>
    </div>

    <div class="boardToolbar card" aria-label="一覧の絞り込み">
      <div class="boardToolbar__top">
        <div class="boardSummary">
          <div class="boardSummary__item is-attention">要対応 <?= (int)$attentionCount ?>名</div>
          <div class="boardSummary__item">返信あり <?= (int)$replyCount ?>名</div>
          <div class="boardSummary__item">出勤確定待ち <?= (int)$confirmPendingCount ?>名</div>
        </div>
        <div class="boardToolbar__actions">
          <form class="boardSearch" id="castSearchForm">
            <span class="boardSearch__label">検索</span>
            <input type="search" id="castSearch" class="boardSearch__input" placeholder="店番・名前で絞り込み">
          </form>
          <button type="button" class="btn boardDensityBtn" id="densityToggle" aria-pressed="false">高密度表示</button>
        </div>
      </div>
      <div class="boardFilters" id="boardFilters">
        <button type="button" class="boardFilter is-active" data-toolbar-filter="all">全員</button>
        <button type="button" class="boardFilter is-attention" data-toolbar-filter="attention">要対応</button>
        <button type="button" class="boardFilter" data-toolbar-filter="confirm_pending">出勤確定待ち</button>
        <button type="button" class="boardFilter" data-toolbar-filter="replied">返信あり</button>
        <button type="button" class="boardFilter" data-toolbar-filter="in">出勤中</button>
        <button type="button" class="boardFilter" data-toolbar-filter="late">遅刻</button>
        <button type="button" class="boardFilter" data-toolbar-filter="wait">未出勤</button>
        <button type="button" class="boardFilter" data-toolbar-filter="done">退勤済</button>
        <button type="button" class="boardFilter" data-toolbar-filter="off">休み</button>
      </div>
    </div>

    <?php if ($recentPlanChanges !== []): ?>
      <div class="card subtleCard recentPlanCard">
        <div class="sectionHead recentPlanCard__head">
          <div>
            <div class="cardTitle">直近の予定変更</div>
            <div class="muted">変更内容は省略し、予定を変更したキャストだけを表示しています。</div>
          </div>
        </div>
        <div class="recentPlanList">
          <?php foreach ($recentPlanChanges as $log): ?>
            <div class="recentPlanItem">
              <div class="recentPlanItem__cast"><?= h($log['cast_name']) ?></div>
              <div class="recentPlanItem__time"><?= h(substr($log['created_at'], 5, 11)) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="card mobileSimpleBoard" aria-label="キャスト簡易一覧">
      <div class="sectionHead">
        <div>
          <div class="cardTitle">未出勤の簡易一覧</div>
          <div class="muted">未出勤中のキャストだけを並べています。タップで詳細へ移動します。</div>
        </div>
      </div>
      <div class="mobileSimpleGrid">
        <?php foreach ($rows as $r):
          $uid  = (int)$r['user_id'];
          $name = (string)$r['display_name'];
          $shopTag  = trim((string)($r['shop_tag'] ?? ''));
          $tagLabel = ($shopTag !== '') ? $shopTag : (string)$uid;
          $hasPlan   = ((int)($r['has_plan'] ?? 0) === 1);
          $planOff   = $hasPlan && ((int)($r['plan_is_off'] ?? 0) === 1);
          $planStart = $hasPlan ? (string)($r['plan_start_time'] ?? '') : '';
          $attendanceStatus = (string)($r['attendance_status'] ?? '');

          $statusLabel = '未出勤';
          if (!$hasPlan) $statusLabel = '予定なし';
          else if ($planOff) $statusLabel = '休み';
          else if ($attendanceStatus === 'absent') $statusLabel = '欠勤';
          else if ($r['clock_out']) $statusLabel = '退勤済';
          else if ($r['clock_in']) $statusLabel = '出勤中';

          $isLate = false;
          if ($attendanceStatus !== 'absent' && $hasPlan && !$planOff && $planStart !== '' && empty($r['clock_in'])) {
            $planDT = new DateTime($bizDate . ' ' . substr($planStart, 0, 5) . ':00', new DateTimeZone('Asia/Tokyo'));
            if ($now > $planDT) $isLate = true;
          }

          $state = 'wait';
          if (!$hasPlan) $state = 'noplan';
          else if ($planOff) $state = 'off';
          else if ($attendanceStatus === 'absent') $state = 'absent';
          else if ($r['clock_out']) $state = 'done';
          else if ($r['clock_in']) $state = 'in';
          else $state = ($isLate ? 'late' : 'wait');

          if (!in_array($state, ['wait', 'late'], true)) {
            continue;
          }
        ?>
          <button
            type="button"
            class="mobileSimpleCard row-state-<?= h($state) ?> js-simple-scroll"
            data-target="detail-<?= (int)$uid ?>"
            data-name="<?= h(mb_strtolower($name, 'UTF-8')) ?>"
            data-tag="<?= h(mb_strtolower($tagLabel, 'UTF-8')) ?>"
            data-state="<?= h($state) ?>"
          >
            <span class="badgeState s-<?= h($state) ?>"><?= h($statusLabel) ?></span>
            <span class="mobileSimpleCard__tag">店番 <?= h($tagLabel) ?></span>
            <span class="mobileSimpleCard__name"><?= h($name) ?></span>
          </button>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- 今日 -->
    <div class="card" style="margin-top:14px;">
      <div class="sectionHead">
        <div>
          <div class="cardTitle">今日（勤務予定と実績＋返信）</div>
          <div class="muted"><span id="visibleCount"><?= count($rows) ?></span> / <?= count($rows) ?> 名を表示中。状態と連絡履歴を同じ行で確認できます。</div>
        </div>
      </div>

      <div class="tblWrap" aria-label="今日の勤務予定と実績">
        <table class="tbl tblToday">
          <thead>
            <tr>
              <th class="col-status">状態</th>
              <th class="col-cast">キャスト</th>
              <th class="col-plan">予定</th>
              <th class="col-actual">実績</th>
              <th class="col-late">遅刻</th>
              <th class="col-reply">返信</th>
              <th class="col-action">連絡</th>
              <th class="col-sent">送信</th>
            </tr>
          </thead>

          <tbody>
          <?php foreach ($rows as $r):
            $uid  = (int)$r['user_id'];
            $name = (string)$r['display_name'];

            $shopTag  = trim((string)($r['shop_tag'] ?? ''));
            $tagLabel = ($shopTag !== '') ? $shopTag : (string)$uid; // 空の間の保険

            $hasPlan   = ((int)($r['has_plan'] ?? 0) === 1);
            $planOff   = $hasPlan && ((int)($r['plan_is_off'] ?? 0) === 1);
            $planStart = $hasPlan ? (string)($r['plan_start_time'] ?? '') : '';
            $attendanceStatus = (string)($r['attendance_status'] ?? '');

            $clockIn  = $r['clock_in'] ? substr((string)$r['clock_in'], 11, 5) : '';
            $clockOut = $r['clock_out'] ? substr((string)$r['clock_out'], 11, 5) : '';

            // 状態ラベル
            $statusLabel = '未出勤';
            if (!$hasPlan) $statusLabel = '予定なし';
            else if ($planOff) $statusLabel = '休み';
            else if ($attendanceStatus === 'absent') $statusLabel = '欠勤';
            else if ($r['clock_out']) $statusLabel = '退勤済';
            else if ($r['clock_in']) $statusLabel = '出勤中';

            // 遅刻判定
            $isLate = false;
            if ($attendanceStatus !== 'absent' && $hasPlan && !$planOff && $planStart !== '' && empty($r['clock_in'])) {
              $planDT = new DateTime($bizDate . ' ' . substr($planStart, 0, 5) . ':00', new DateTimeZone('Asia/Tokyo'));
              if ($now > $planDT) $isLate = true;
            }

            // 状態キー（CSS/JS用）
            $state = 'wait';
            if (!$hasPlan) $state = 'noplan';
            else if ($planOff) $state = 'off';
            else if ($attendanceStatus === 'absent') $state = 'absent';
            else if ($r['clock_out']) $state = 'done';
            else if ($r['clock_in']) $state = 'in';
            else $state = ($isLate ? 'late' : 'wait');

            $lateNotice = $noticeMap[$uid]['late'] ?? null;
            $absNotice  = $noticeMap[$uid]['absent'] ?? null;
            $attendanceConfirmNotice = $noticeMap[$uid]['attendance_confirm'] ?? null;

            $replyText = '';
            $replyWhen = '';
            $replyKindLabel = '';
            $cand = [];
            if ($lateNotice && !empty($lateNotice['last_reply_text'])) $cand[] = $lateNotice;
            if ($absNotice  && !empty($absNotice['last_reply_text']))  $cand[] = $absNotice;
            if ($attendanceConfirmNotice && !empty($attendanceConfirmNotice['last_reply_text'])) $cand[] = $attendanceConfirmNotice;
            if ($cand) {
              usort($cand, fn($a,$b)=>strcmp((string)$b['responded_at'], (string)$a['responded_at']));
              $replyText = (string)($cand[0]['last_reply_text'] ?? '');
              $replyWhen = (string)($cand[0]['responded_at'] ?? '');
              $replyKindLabel = notice_kind_label((string)($cand[0]['kind'] ?? ''), (string)($cand[0]['reply_choice'] ?? ''));
            }

            $tplLate = "{$name}さん\n遅刻の連絡をお願いします。\n到着予定時刻と理由を返信してください。";
            $tplAbs  = "{$name}さん\n本日欠勤の場合は理由を返信してください。";
            $employmentType = (string)($r['employment_type'] ?? 'part');
            if ($employmentType === 'regular') {
              $employmentType = 'レギュラー';
            } elseif ($employmentType === 'part') {
              $employmentType = 'バイト';
            }

            // 直近送信
            $lastSent = null;
            if ($lateNotice) $lastSent = $lateNotice;
            if ($absNotice && (!$lastSent || (string)$absNotice['sent_at'] > (string)$lastSent['sent_at'])) $lastSent = $absNotice;
            if ($attendanceConfirmNotice && (!$lastSent || (string)$attendanceConfirmNotice['sent_at'] > (string)$lastSent['sent_at'])) $lastSent = $attendanceConfirmNotice;

            $showConfirmButton = $attendanceConfirmNotice
              && (string)($attendanceConfirmNotice['reply_choice'] ?? '') === 'confirm'
              && !empty($attendanceConfirmNotice['responded_at'])
              && empty($attendanceConfirmNotice['manager_confirmed_at']);
            $confirmDone = $attendanceConfirmNotice && !empty($attendanceConfirmNotice['manager_confirmed_at']);

          ?>
            <?php
              $hasReply = ($replyText !== '');
              $needsAttention = ($isLate || $attendanceStatus === 'absent' || $showConfirmButton);
            ?>
            <tr
              id="detail-<?= (int)$uid ?>"
              class="row row-state-<?= h($state) ?>"
              data-state="<?= h($state) ?>"
              data-user-id="<?= (int)$uid ?>"
              data-name="<?= h(mb_strtolower($name, 'UTF-8')) ?>"
              data-tag="<?= h(mb_strtolower($tagLabel, 'UTF-8')) ?>"
              data-replied="<?= $hasReply ? '1' : '0' ?>"
              data-confirm-pending="<?= $showConfirmButton ? '1' : '0' ?>"
              data-attention="<?= $needsAttention ? '1' : '0' ?>"
            >
              <td class="col-status">
                <span class="badgeState s-<?= h($state) ?>"><?= h($statusLabel) ?></span>
              </td>

              <td class="col-cast">
                <div class="mobileCastHead" aria-hidden="true">
                  <span class="mobileCastHead__status badgeState s-<?= h($state) ?>"><?= h($statusLabel) ?></span>
                  <span class="mobileCastHead__tag">店番 <?= h($tagLabel) ?></span>
                  <span class="mobileCastHead__name"><?= h($name) ?></span>
                </div>
                <div class="castMain">
                  <span class="castTag">【<?= h($tagLabel) ?>】</span>
                  <b class="castName"><?= h($name) ?></b>
                </div>
                <div class="castSub muted"><?= h($employmentType) ?></div>
                <button type="button" class="weekToggleBtn js-week-toggle" aria-expanded="false" data-target="week-<?= (int)$uid ?>">
                  今週予定を開く
                </button>
              </td>

              <td class="col-plan">
                <?php if (!$hasPlan): ?>
                  <div class="cellStack">
                    <span class="muted">（予定なし）</span>
                  </div>
                <?php elseif ($planOff): ?>
                  <div class="cellStack">
                    <span class="timeOff">OFF</span>
                  </div>
                <?php else: ?>
                  <div class="cellStack">
                    <span class="timePlan"><b><?= h(substr($planStart,0,5)) ?></b></span>
                  </div>
                <?php endif; ?>
              </td>

              <td class="col-actual">
                <div class="cellStack">
                  <?php if ($attendanceStatus === 'absent'): ?>
                    <span class="timeAbsent">欠勤</span>
                  <?php else: ?>
                    <span class="timeActual">
                      <?= h($clockIn ?: '--:--') ?>
                      <span class="muted">→</span>
                      <?= h($clockOut ?: '--:--') ?>
                    </span>
                  <?php endif; ?>
                </div>
              </td>

              <td class="col-late">
                <?php if ($hasPlan && !$planOff && $isLate): ?>
                  <span class="badgeLate">遅刻</span>
                <?php else: ?>
                  <span class="muted">-</span>
                <?php endif; ?>
              </td>

              <td class="col-reply">
                <?php if ($replyText !== ''): ?>
                  <div class="replyBox">
                    <?php if ($replyKindLabel !== ''): ?>
                      <div class="replyKind"><?= h($replyKindLabel) ?></div>
                    <?php endif; ?>
                    <div class="replyText"><?= nl2br(h(mb_strimwidth($replyText, 0, 160, '…', 'UTF-8'))) ?></div>
                    <div class="muted">返信: <?= h(substr($replyWhen, 11, 5)) ?></div>
                    <?php if ($confirmDone): ?>
                      <div class="replyNote">管理者が出勤確定済み</div>
                    <?php endif; ?>
                  </div>
                <?php else: ?>
                  <span class="muted">（返信なし）</span>
                <?php endif; ?>
              </td>

              <td class="col-action">
                <?php if ($hasPlan && !$planOff): ?>
                  <div class="actionStack">
                    <?php if ($showConfirmButton): ?>
                      <form method="post" style="margin:0;">
                        <input type="hidden" name="action" value="confirm_attendance_response">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
                        <input type="hidden" name="business_date" value="<?= h($bizDate) ?>">
                        <input type="hidden" name="notice_action_id" value="<?= (int)($attendanceConfirmNotice['id'] ?? 0) ?>">
                        <button type="submit" class="btn ghost btn-confirm-attendance">出勤確定</button>
                      </form>
                    <?php endif; ?>
                    <details class="lineActionsDetails">
                      <summary>LINE連絡を開く</summary>
                      <div class="btnRow">
                        <button type="button" class="btn ghost line-late js-open-modal"
                          data-kind="late"
                          data-cast="<?= (int)$uid ?>"
                          data-name="<?= h($name) ?>"
                          data-text="<?= h($tplLate) ?>"
                        >遅刻LINE</button>

                        <button type="button" class="btn ghost line-abs js-open-modal"
                          data-kind="absent"
                          data-cast="<?= (int)$uid ?>"
                          data-name="<?= h($name) ?>"
                          data-text="<?= h($tplAbs) ?>"
                        >欠勤LINE</button>
                      </div>
                    </details>
                  </div>
                <?php else: ?>
                  <span class="muted">-</span>
                <?php endif; ?>
              </td>

              <td class="col-sent">
                <?php if ($lastSent): ?>
                  <div class="cellStack">
                    <div class="sentWhen"><?= h(substr((string)$lastSent['sent_at'], 11, 5)) ?></div>
                    <div class="muted">by <?= h((string)($lastSent['sender_login'] ?? '')) ?></div>
                  </div>
                  <?php if (($lastSent['status'] ?? '') === 'failed'): ?>
                    <div class="badgeErr">送信失敗</div>
                    <div class="muted"><?= h((string)($lastSent['error_message'] ?? '')) ?></div>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="muted">-</span>
                <?php endif; ?>
              </td>
            </tr>
            <tr class="weekRow" id="week-<?= (int)$uid ?>" hidden>
              <td colspan="8" class="weekCell">
                <div class="inlineWeek">
                  <div class="inlineWeekTitle">今週の出勤予定</div>
                  <div class="inlineWeekGrid">
                    <?php foreach ($displayDates as $d): ?>
                      <?php
                        $p = $weekPlans[$uid][$d] ?? null;
                        $a = $weekAtt[$uid][$d] ?? ['in'=>'','out'=>'','status'=>''];
                        $dt = new DateTime($d, new DateTimeZone('Asia/Tokyo'));
                        $w = ['日','月','火','水','木','金','土'][(int)$dt->format('w')];

                        $planLabel = '予定なし';
                        if ($p !== null) {
                          if (!empty($p['is_off'])) {
                            $planLabel = 'OFF';
                          } else {
                            $stt = (string)($p['start_time'] ?? '');
                            $planLabel = ($stt !== '' ? $stt : '--');
                          }
                        }

                        $actualLabel = '';
                        if (($a['status'] ?? '') === 'absent') {
                          $actualLabel = '欠勤';
                        } elseif (($a['in'] ?? '') !== '' || ($a['out'] ?? '') !== '') {
                          $actualLabel = ($a['in'] ?: '--:--') . '→' . ($a['out'] ?: '--:--');
                        }
                      ?>
                      <div class="inlineWeekItem">
                        <div class="inlineWeekDate"><?= h(substr($d,5)) ?><span><?= h($w) ?></span></div>
                        <div class="inlineWeekPlan"><?= h($planLabel) ?></div>
                        <div class="inlineWeekActual<?= $actualLabel === '' ? ' is-empty' : '' ?>">
                          <?= h($actualLabel !== '' ? $actualLabel : '実績なし') ?>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

<!-- Modal -->
<div class="modalBg" id="modalBg" hidden>
  <div class="modal">
    <div class="modalHead">
      <div class="modalTitle" id="modalTitle">LINE送信</div>
      <button type="button" class="btn ghost" id="modalClose">✕</button>
    </div>

    <div class="muted" id="modalSub" style="margin-top:4px;"></div>

    <form method="post" action="/wbss/public/manager_today_schedule.php" id="modalForm">
      <input type="hidden" name="action" value="send_notice">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
      <input type="hidden" name="business_date" value="<?= h($bizDate) ?>">
      <input type="hidden" name="cast_user_id" id="m_cast_user_id" value="">
      <input type="hidden" name="kind" id="m_kind" value="">
      <input type="hidden" name="template_text" id="m_template_text" value="">
      <textarea name="text" id="m_text" class="ta" rows="7" required></textarea>

      <div class="modalFoot">
        <button type="button" class="btn" id="modalCancel">キャンセル</button>
        <button type="submit" class="btn primary" id="modalSend">送信</button>
      </div>
      <div class="muted" id="modalMsg" style="margin-top:8px;"></div>
    </form>
  </div>
</div>

<style>
.pageHero{
  margin-bottom: 14px;
  padding: 18px;
  border: 1px solid rgba(15,23,42,.08);
  border-radius: 18px;
  background: linear-gradient(135deg, rgba(255,255,255,.98), rgba(241,245,249,.92));
}
.rowTop{ display:flex; align-items:flex-start; gap:16px; justify-content:space-between; }
.heroActions{ display:flex; gap:10px; flex-wrap:wrap; }
.titleWrap{ display:flex; flex-direction:column; gap:10px; align-items:flex-end; }
.title{ font-weight:1000; font-size:28px; line-height:1.1; letter-spacing:.01em; }
.heroMeta{ display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; }
.heroChip{
  display:inline-flex;
  align-items:center;
  gap:6px;
  padding:8px 12px;
  border-radius:999px;
  border:1px solid rgba(15,23,42,.10);
  background:#ffffff;
  color:#334155;
  font-size:13px;
}
.subInfo{ margin-top: 10px; }
.btn{ display:inline-flex; align-items:center; gap:6px; padding:10px 14px; border-radius:12px; border:1px solid var(--line); background:var(--cardA); color:inherit; text-decoration:none; cursor:pointer; }
.btn.primary{ background:rgba(59,130,246,.18); border-color:rgba(59,130,246,.35); }
.btn.ghost{ background:transparent; }
.searchRow{ margin-top:10px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.sel{ padding:10px 12px; border-radius:12px; border:1px solid var(--line); background:var(--cardA); color:inherit; }

.boardToolbar{
  margin-top:14px;
  display:flex;
  flex-direction:column;
  gap:12px;
}
.boardToolbar__top{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:12px;
  flex-wrap:wrap;
}
.boardSummary{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
}
.boardSummary__item{
  display:inline-flex;
  align-items:center;
  min-height:38px;
  padding:8px 12px;
  border-radius:999px;
  border:1px solid rgba(15,23,42,.10);
  background:#fff;
  color:#0f172a;
  font-size:13px;
  font-weight:900;
}
.boardSummary__item.is-attention{
  background:rgba(239,68,68,.08);
  border-color:rgba(239,68,68,.22);
  color:#991b1b;
}
.boardToolbar__actions{
  display:flex;
  align-items:center;
  gap:10px;
  flex-wrap:wrap;
}
.boardSearch{
  display:flex;
  align-items:center;
  gap:8px;
  min-height:40px;
  padding:0 12px;
  border:1px solid rgba(15,23,42,.12);
  border-radius:12px;
  background:#fff;
}
.boardSearch__label{
  font-size:12px;
  font-weight:900;
  color:#475569;
}
.boardSearch__input{
  width:min(280px, 48vw);
  border:none;
  outline:none;
  background:transparent;
  color:#0f172a;
  font-size:14px;
}
.boardDensityBtn[aria-pressed="true"]{
  background:rgba(59,130,246,.12);
  border-color:rgba(59,130,246,.30);
  color:#1d4ed8;
}
.boardFilters{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
}
.boardFilter{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-height:36px;
  padding:8px 12px;
  border-radius:999px;
  border:1px solid rgba(15,23,42,.12);
  background:#fff;
  color:#334155;
  font-size:12px;
  font-weight:900;
  cursor:pointer;
}
.boardFilter.is-active{
  background:#0f172a;
  border-color:#0f172a;
  color:#fff;
}
.boardFilter.is-attention{
  border-color:rgba(239,68,68,.24);
}

.kpi{ margin-top:10px; display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:10px; }
.k{
  padding:14px 12px;
  border:1px solid var(--line);
  border-radius:16px;
  background:var(--cardA);
  text-align:center;
  display:flex;
  flex-direction:column;
  gap:6px;
  min-height:88px;
  justify-content:center;
}
.kLabel{ font-size:13px; color:#475569; font-weight:700; }
.kValue{ font-size:28px; line-height:1; }

.card{ padding:14px; border:1px solid var(--line); border-radius:14px; background:var(--cardA); }
.cardTitle{ font-weight:900; margin-bottom:10px; }
.subtleCard{
  margin-top:14px;
  background:linear-gradient(180deg, rgba(255,255,255,.94), rgba(248,250,252,.90));
}
.recentPlanCard__head{
  margin-bottom:10px;
}
.recentPlanList{
  display:grid;
  grid-template-columns:repeat(auto-fit, minmax(160px, 1fr));
  gap:8px;
}
.recentPlanItem{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  padding:9px 12px;
  border-radius:12px;
  border:1px solid rgba(15,23,42,.08);
  background:rgba(255,255,255,.74);
}
.recentPlanItem__cast{
  font-size:13px;
  font-weight:900;
  color:#0f172a;
  line-height:1.35;
}
.recentPlanItem__time{
  font-size:11px;
  color:#64748b;
  white-space:nowrap;
}
.sectionHead{
  display:flex;
  align-items:flex-end;
  justify-content:space-between;
  gap:12px;
  margin-bottom:10px;
}

.tbl{ width:100%; border-collapse:collapse; }
.tbl th,.tbl td{ padding:10px; border-bottom:1px solid rgba(255,255,255,.08); vertical-align:top; }
.muted{ opacity:.75; font-size:12px; }
.badge-red{ display:inline-block; background:#ef4444; color:#fff; font-size:11px; padding:2px 8px; border-radius:999px; }
.btnRow{ display:flex; gap:6px; flex-wrap:wrap; }
.cellStack{ display:flex; flex-direction:column; gap:4px; }

.replyBox{ padding:8px 10px; border:1px solid rgba(255,255,255,.12); border-radius:12px; background:rgba(255,255,255,.04); }
.replyText{ font-size:13px; white-space:normal; line-height:1.35; }

.modalBg[hidden]{ display:none !important; }
.modalBg{ position:fixed; inset:0; background:rgba(0,0,0,.55); display:flex; align-items:center; justify-content:center; padding:16px; z-index:1000; }
.modal{ width:min(720px, 96vw); border:1px solid rgba(255,255,255,.14); border-radius:16px; background:#0f1730; padding:14px; }
.modalHead{ display:flex; justify-content:space-between; align-items:center; }
.modalTitle{ font-weight:1000; font-size:16px; }
.ta{ width:100%; margin-top:10px; padding:12px; border-radius:12px; border:1px solid rgba(255,255,255,.16); background:rgba(255,255,255,.04); color:#e8ecff; resize:vertical; }
.modalFoot{ display:flex; justify-content:flex-end; gap:10px; margin-top:10px; }

.weekWrap{ overflow:auto; }
.weekTbl th{ position:sticky; top:0; background:var(--cardA); }
/* =========================================================
   manager_today_schedule: Lightでも読める強制コントラスト
   - 既存CSSの上書き用（末尾に追加）
========================================================= */

/* 0) ベース：カード/テーブルの文字色を「確実に濃く」 */
.card, .tblWrap, .tbl, .tbl th, .tbl td,
.k, .btn, .sel, .replyBox{
  color: #0f172a; /* slate-900 */
}

/* muted は薄くしすぎない（Lightで消えるのを防ぐ） */
.muted{
  opacity: 0.82;
  color: #334155; /* slate-700 */
}

/* 1) KPI：押せる雰囲気（後でJSでフィルタにする前提） */
.kpiBtns .k{
  cursor: pointer;
  user-select: none;
  transition: transform .05s ease, background .15s ease, border-color .15s ease;
}
.kpiBtns .k:hover{ transform: translateY(-1px); border-color: rgba(59,130,246,.35); }
.kpiBtns .k.active{ outline: 2px solid rgba(59,130,246,.35); }

/* 2) テーブルコンテナ（白背景に寄せてLightで見えるように） */
.tblWrap{
  overflow:auto;
  border: 1px solid rgba(15,23,42,.14);
  border-radius: 14px;
  background: #ffffff;
}

/* 3) 今日テーブル：見出しを濃い背景＋白文字で固定 */
.tblToday{
  width:100%;
  border-collapse: separate;
  border-spacing: 0;
  min-width: 920px; /* PCでの無駄な横伸びを抑える */
  --status-col-w: 88px;
}

.tblToday thead th{
  position: sticky;
  top: 0;
  z-index: 5;
  background: #0f172a;  /* 濃紺 */
  color: #ffffff;
  font-weight: 900;
  border-bottom: 1px solid rgba(255,255,255,.18);
  white-space: nowrap;
}

/* 行：白ベース＋ホバー */
.tblToday tbody td{
  background:#ffffff;
  border-bottom: 1px solid rgba(15,23,42,.08);
  vertical-align: middle;
}
.tblToday tbody tr:last-child td{
  border-bottom: none;
}
.tblToday tbody tr:hover td{
  background: #f8fafc; /* very light */
}

/* 4) 列の横幅（見やすく） */
.tblToday .col-status{ width: var(--status-col-w); min-width: var(--status-col-w); max-width: var(--status-col-w); }
.tblToday .col-cast{ min-width: 220px; }
.tblToday .col-plan{ width: 82px; text-align:center; }
.tblToday .col-actual{ width: 132px; text-align:center; }
.tblToday .col-late{ width: 80px; text-align:center; }
.tblToday .col-reply{ width: 210px; min-width: 210px; }
.tblToday .col-action{ width: 132px; }
.tblToday .col-sent{ width: 88px; white-space: nowrap; }
.tblToday .col-status,
.tblToday .col-cast{
  position: sticky;
  left: 0;
  z-index: 2;
}
.tblToday .col-cast{
  left: var(--status-col-w);
  z-index: 2;
}
.tblToday thead .col-status,
.tblToday thead .col-cast{
  z-index: 6;
}
.tblToday tbody .col-status,
.tblToday tbody .col-cast{
  background: #ffffff;
}
.tblToday th.col-status,
.tblToday td.col-status{
  padding-left:6px;
  padding-right:6px;
}
.tblToday tbody tr:hover .col-status,
.tblToday tbody tr:hover .col-cast{
  background: #f8fafc;
}

/* 5) 状態バッジ（Lightで見える配色） */
.badgeState{
  display:inline-flex;
  align-items:center;
  padding: 6px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 900;
  border: 1px solid rgba(15,23,42,.18);
  background: #f1f5f9;
  color: #0f172a;
  white-space: nowrap;
}

/* 状態別 */
.badgeState.s-noplan{ background:#f1f5f9; color:#475569; }
.badgeState.s-off   { background:#eef2ff; color:#3730a3; border-color: rgba(99,102,241,.25); }
.badgeState.s-wait  { background: rgba(251,191,36,.18); color:#92400e; border-color: rgba(251,191,36,.35); }
.badgeState.s-late  { background: rgba(239,68,68,.14); color:#991b1b; border-color: rgba(239,68,68,.35); }
.badgeState.s-absent{ background: rgba(239,68,68,.12); color:#991b1b; border-color: rgba(239,68,68,.30); }
.badgeState.s-in    { background: rgba(34,197,94,.16); color:#166534; border-color: rgba(34,197,94,.35); }
.badgeState.s-done  { background: rgba(59,130,246,.14); color:#1d4ed8; border-color: rgba(59,130,246,.30); }

/* 6) 行の左ラインで “今見るべき” を強調 */
.row{ position: relative; }
.row-state-wait td{ box-shadow: inset 4px 0 0 rgba(251,191,36,.65); }
.row-state-late td{ box-shadow: inset 4px 0 0 rgba(239,68,68,.80); }
.row-state-absent td{ box-shadow: inset 4px 0 0 rgba(239,68,68,.65); }
.row-state-in   td{ box-shadow: inset 4px 0 0 rgba(34,197,94,.70); }
.row-state-done td{ box-shadow: inset 4px 0 0 rgba(59,130,246,.65); }

/* 7) 遅刻バッジ（既存 badge-red と分離） */
.badgeLate{
  display:inline-block;
  font-size: 11px;
  font-weight: 900;
  padding: 3px 10px;
  border-radius: 999px;
  background: rgba(239,68,68,.14);
  color: #991b1b;
  border: 1px solid rgba(239,68,68,.35);
}

/* 8) 返信ボックス：Lightでも見える */
.replyBox{
  border: 1px solid rgba(15,23,42,.14);
  background: #f8fafc;
  max-width: 210px;
}
.replyKind{
  display:inline-flex;
  align-items:center;
  margin-bottom:6px;
  padding:3px 8px;
  border-radius:999px;
  background:rgba(15,23,42,.08);
  color:#334155;
  font-size:10px;
  font-weight:900;
}
.replyText{
  color:#0f172a;
}
.replyNote{
  margin-top:6px;
  font-size:11px;
  font-weight:800;
  color:#166534;
}
.timeAbsent{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  padding:4px 10px;
  border-radius:999px;
  background:rgba(239,68,68,.12);
  color:#991b1b;
  font-size:12px;
  font-weight:900;
  border:1px solid rgba(239,68,68,.30);
}

.weekToggleBtn{
  margin-top:8px;
  display:inline-flex;
  align-items:center;
  gap:6px;
  padding:6px 10px;
  border:1px solid rgba(15,23,42,.14);
  border-radius:999px;
  background:#ffffff;
  color:#334155;
  font-size:12px;
  font-weight:800;
  cursor:pointer;
}
.weekToggleBtn::before{
  content:"+";
  font-size:13px;
  line-height:1;
}
.weekToggleBtn[aria-expanded="true"]::before{
  content:"-";
}
.mobileSimpleBoard{
  display:none;
  margin-top:14px;
}
.mobileSimpleGrid{
  display:grid;
  grid-template-columns:repeat(3, minmax(0, 1fr));
  gap:8px;
}
.mobileSimpleCard{
  display:grid;
  gap:6px;
  padding:10px;
  border:1px solid rgba(15,23,42,.10);
  border-radius:14px;
  background:#ffffff;
  box-shadow:0 8px 18px rgba(15,23,42,.05);
  min-width:0;
  width:100%;
  text-align:center;
  cursor:pointer;
}
.mobileSimpleCard:active{
  transform:translateY(1px);
}
.row-is-highlighted{
  outline:2px solid rgba(59,130,246,.42);
  outline-offset:4px;
}
.tblToday tbody tr.row{
  scroll-margin-top:96px;
}
.mobileSimpleCard .badgeState{
  min-width:0;
  width:100%;
  justify-content:center;
}
.mobileSimpleCard__tag{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-height:28px;
  padding:0 8px;
  border-radius:999px;
  border:1px solid rgba(15,23,42,.10);
  background:#f8fafc;
  color:#334155;
  font-size:11px;
  font-weight:900;
  white-space:nowrap;
}
.mobileSimpleCard__name{
  display:block;
  min-width:0;
  font-size:14px;
  font-weight:1000;
  line-height:1.3;
  color:#0f172a;
  text-align:center;
  word-break:break-word;
}
.mobileCastHead{
  display:none;
}
.castTag{
  color:#64748b;
  font-size:12px;
  font-weight:900;
}
.actionStack{
  display:grid;
  gap:8px;
}
.lineActionsDetails{
  border:1px solid rgba(15,23,42,.10);
  border-radius:14px;
  background:#f8fafc;
  padding:8px;
}
.lineActionsDetails summary{
  list-style:none;
  cursor:pointer;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:8px;
  font-size:12px;
  font-weight:900;
  color:#334155;
}
.lineActionsDetails summary::-webkit-details-marker{
  display:none;
}
.lineActionsDetails summary::after{
  content:"＋";
  font-size:15px;
  line-height:1;
}
.lineActionsDetails[open] summary::after{
  content:"－";
}
.lineActionsDetails .btnRow{
  margin-top:8px;
}
.boardSearch{
  display:grid;
  gap:6px;
}
.weekRow td{
  background:#f8fafc;
  border-bottom: 1px solid rgba(15,23,42,.08);
}
.weekRow[hidden]{
  display:none !important;
}
.weekCell{
  padding-top: 0;
}
.inlineWeek{
  padding: 4px 0 2px;
}
.inlineWeekTitle{
  font-size: 12px;
  font-weight: 900;
  color:#334155;
  margin-bottom: 8px;
}
.inlineWeekGrid{
  display:grid;
  grid-template-columns: repeat(auto-fit, minmax(92px, 1fr));
  gap:8px;
}
.inlineWeekItem{
  border:1px solid rgba(15,23,42,.10);
  border-radius:12px;
  background:#ffffff;
  padding:8px 7px;
  min-height:72px;
  min-width:0;
}
.inlineWeekDate{
  font-size:11px;
  font-weight:900;
  color:#0f172a;
  display:flex;
  align-items:center;
  justify-content:space-between;
  margin-bottom:6px;
}
.inlineWeekDate span{
  color:#64748b;
  font-weight:700;
}
.inlineWeekPlan{
  font-size:12px;
  font-weight:900;
  color:#0f172a;
  line-height:1.25;
  overflow-wrap:anywhere;
}
.inlineWeekActual{
  margin-top:4px;
  font-size:11px;
  color:#334155;
  line-height:1.25;
  overflow-wrap:anywhere;
}
.inlineWeekActual.is-empty{
  color:#64748b;
}

/* 9) 送信失敗の表示 */
.badgeErr{
  display:inline-block;
  margin-top:6px;
  font-size:11px;
  font-weight:900;
  padding:3px 10px;
  border-radius:999px;
  background: rgba(239,68,68,.14);
  color:#991b1b;
  border: 1px solid rgba(239,68,68,.35);
}
.sentWhen{ font-weight: 900; }

/* 10) ボタン：Lightで“薄すぎ”を防ぐ */
.btn{
  border-color: rgba(15,23,42,.14);
  background: #ffffff;
}
.btn.ghost{
  background: #ffffff;
}
.btn.primary{
  background: rgba(59,130,246,.12);
  border-color: rgba(59,130,246,.30);
}
.btn:disabled{
  opacity: .45;
  cursor: not-allowed;
}

/* 11) 週テーブル：見出しを固定しつつ見やすく */
.weekTbl th{
  background: #0f172a;
  color: #ffffff;
  border-bottom: 1px solid rgba(255,255,255,.18);
}
html[data-theme="dark"] .subtleCard,
body[data-theme="dark"] .subtleCard,
html[data-theme="dark"] .recentPlanItem,
body[data-theme="dark"] .recentPlanItem{
  background: rgba(255,255,255,.04);
  border-color: rgba(255,255,255,.08);
}
html[data-theme="dark"] .recentPlanItem__cast,
body[data-theme="dark"] .recentPlanItem__cast{
  color:#eef2ff;
}
html[data-theme="dark"] .recentPlanItem__time,
body[data-theme="dark"] .recentPlanItem__time,
body[data-theme="dark"] .recentPlanItem__time{
  color:#aab3d6;
}

/* 12) スマホ最適化：返信列を少し縮める */
@media (max-width: 900px){
  .tblToday{ min-width: 820px; }
  .tblToday .col-cast{ min-width: 190px; }
  .tblToday .col-reply{ width: 180px; min-width: 180px; }
  .inlineWeekGrid{ grid-template-columns: repeat(auto-fit, minmax(84px, 1fr)); }
}
/* 状態セルを強調 */
.col-status{
  font-weight:900;
}

/* 状態バッジ */
.badgeState{
  min-width:72px;
  justify-content:center;
}

/* 状態別アイコン風 */
.badgeState.s-wait::before{ content:"⏳ "; }
.badgeState.s-in::before{ content:"🟢 "; }
.badgeState.s-done::before{ content:"✔ "; }
.badgeState.s-off::before{ content:"🌙 "; }
.badgeState.s-noplan::before{ content:"— "; }
.badgeState.s-late::before{ content:"⚠ "; }

/* 要対応行 */
.row-state-wait td{
  background: linear-gradient(
    to right,
    rgba(251,191,36,.12),
    #ffffff 40%
  );
}

.row-state-late td{
  background: linear-gradient(
    to right,
    rgba(239,68,68,.12),
    #ffffff 40%
  );
}
/* 休み行は少し引く */
.row-state-off{
  opacity: .65;
}

.row-state-off td{
  background:#fafafa;
}
/* LINE系ボタン */
.btn.line-late{
  border-color: rgba(6,199,85,.38);
  background: linear-gradient(180deg, #06c755, #03a94a);
  color:#ffffff;
  font-weight:800;
  box-shadow: inset 0 1px 0 rgba(255,255,255,.18);
}

.btn.line-abs{
  border-color: rgba(6,199,85,.38);
  background: linear-gradient(180deg, #06c755, #03a94a);
  color:#ffffff;
  font-weight:800;
  box-shadow: inset 0 1px 0 rgba(255,255,255,.18);
}
.col-action .btnRow{
  display:flex;
  flex-direction:column;
  gap:6px;
  align-items:stretch;
}
.col-action .btnRow form{
  width:100%;
}
.tblToday thead th{
  text-align:center;
}

.tblToday thead th:first-child{
  text-align:left;
}
.castMain{ display:flex; align-items:center; gap:8px; }
.castName{ font-size:14px; }
.timePlan,
.timeActual,
.sentWhen{
  font-variant-numeric: tabular-nums;
}
.timePlan b,
.sentWhen{
  font-size: 20px;
}
.timeActual{
  font-size:15px;
  font-weight:800;
}
.timeOff{
  font-size:14px;
  font-weight:800;
  color:#6366f1;
}
.col-action .btn{
  min-width: 0;
  width: 100%;
  justify-content:center;
  padding:8px 8px;
  font-size:11px;
  line-height:1.15;
  border-radius:10px;
  white-space:nowrap;
  word-break:normal;
}
.btn-confirm-attendance{
  border-color: rgba(59,130,246,.28);
  background: linear-gradient(180deg, rgba(59,130,246,.16), rgba(37,99,235,.08));
  color:#1d4ed8;
  font-weight:900;
}

body.today-density-compact .tbl th,
body.today-density-compact .tbl td{
  padding:7px 8px;
}
body.today-density-compact .tblToday .col-cast{
  min-width:180px;
}
body.today-density-compact .castName{
  font-size:13px;
}
body.today-density-compact .castSub,
body.today-density-compact .muted{
  font-size:11px;
}
body.today-density-compact .badgeState{
  padding:4px 8px;
  font-size:11px;
}
body.today-density-compact .timePlan b,
body.today-density-compact .sentWhen{
  font-size:17px;
}
body.today-density-compact .timeActual{
  font-size:13px;
}
body.today-density-compact .replyBox{
  padding:6px 8px;
}
body.today-density-compact .replyText{
  font-size:12px;
  line-height:1.25;
}
body.today-density-compact .weekToggleBtn,
body.today-density-compact .col-action .btn{
  font-size:10px;
}

@media (max-width: 1100px){
  .rowTop{
    flex-direction: column;
    align-items: stretch;
  }
  .heroActions{
    justify-content: flex-start;
  }
  .titleWrap{
    align-items:flex-start;
  }
  .heroMeta{
    justify-content:flex-start;
  }
  .title{ font-size:24px; }
  .tblToday{
    min-width: 880px;
  }
  .boardSearch__input{
    width:min(220px, 40vw);
  }
  .tblToday .col-status,
  .tblToday .col-cast{
    position: static;
  }
}

@media (max-width: 640px){
  .page{
    padding:12px 10px 24px;
  }
  .pageHero{
    padding:14px;
    margin-bottom:12px;
  }
  .title{
    font-size:22px;
  }
  .heroChip{
    padding:7px 10px;
    font-size:12px;
  }
  .searchRow{
    display:grid;
    grid-template-columns:1fr;
    gap:8px;
    align-items:stretch;
  }
  .boardToolbar__top,
  .boardToolbar__actions{
    display:grid;
    grid-template-columns:1fr;
  }
  .boardSearch{
    width:100%;
  }
  .boardSearch__input{
    width:100%;
  }
  .boardFilters{
    display:grid;
    grid-template-columns:repeat(2, minmax(0, 1fr));
    gap:8px;
  }
  .boardFilter,
  .boardDensityBtn{
    width:100%;
  }
  .searchRow .btn,
  .searchRow .sel{
    width:100%;
  }
  .kpi{
    grid-template-columns:repeat(3, minmax(0, 1fr));
    gap:8px;
  }
  .k{
    min-height:74px;
    padding:10px 8px;
    border-radius:14px;
  }
  .kLabel{
    font-size:11px;
  }
  .kValue{
    font-size:22px;
  }
  .app-actions{
    width:100%;
    display:grid;
    grid-template-columns:repeat(3, minmax(0, 1fr));
    gap:8px;
  }
  .app-actions .btn{
    min-height:48px;
    padding:10px 8px;
    font-size:12px;
    line-height:1.25;
    white-space:normal;
    text-align:center;
  }
  .mobileSimpleBoard{
    display:block;
  }
  .mobileSimpleGrid{
    grid-template-columns:repeat(3, minmax(0, 1fr));
    gap:6px;
  }
  .mobileSimpleCard{
    padding:8px 6px;
    border-radius:12px;
  }
  .mobileSimpleCard .badgeState{
    padding:5px 6px;
    font-size:10px;
  }
  .mobileSimpleCard__tag{
    min-height:24px;
    font-size:10px;
    padding:0 6px;
  }
  .mobileSimpleCard__name{
    font-size:13px;
  }
  .tblWrap{
    overflow:visible;
    border:none;
    background:transparent;
  }
  .tblToday{
    min-width:0;
    border-spacing:0;
  }
  .tblToday thead{
    display:none;
  }
  .tblToday,
  .tblToday tbody,
  .tblToday tr,
  .tblToday td{
    display:block;
    width:100%;
  }
  .tblToday tbody tr.row{
    margin-bottom:10px;
    padding:12px;
    border:1px solid rgba(15,23,42,.10);
    border-radius:16px;
    background:#ffffff;
    box-shadow:0 10px 20px rgba(15,23,42,.06);
  }
  .tblToday tbody tr.row td{
    padding:6px 0;
    border-bottom:1px solid rgba(15,23,42,.08);
    background:transparent !important;
    box-shadow:none !important;
  }
  .tblToday tbody tr.row td.col-status{
    display:none;
  }
  .tblToday tbody tr.row td:last-child{
    border-bottom:none;
  }
  .tblToday .col-status,
  .tblToday .col-cast,
  .tblToday .col-plan,
  .tblToday .col-actual,
  .tblToday .col-late,
  .tblToday .col-reply,
  .tblToday .col-action,
  .tblToday .col-sent{
    width:auto;
    min-width:0;
    max-width:none;
    position:static;
  }
  .badgeState{
    min-width:0;
    padding:5px 10px;
    font-size:11px;
  }
  .mobileCastHead{
    display:grid;
    grid-template-columns:minmax(0, auto) minmax(0, auto) minmax(0, 1fr);
    align-items:center;
    gap:8px;
    margin-bottom:8px;
  }
  .mobileCastHead__status{
    justify-content:center;
    min-width:0;
  }
  .mobileCastHead__tag{
    display:inline-flex;
    align-items:center;
    min-height:30px;
    padding:0 10px;
    border-radius:999px;
    border:1px solid rgba(15,23,42,.10);
    background:#f8fafc;
    color:#334155;
    font-size:12px;
    font-weight:900;
    white-space:nowrap;
  }
  .mobileCastHead__name{
    min-width:0;
    font-size:18px;
    font-weight:1000;
    color:#0f172a;
    text-align:left;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
  }
  .castMain{
    display:flex;
    align-items:center;
    gap:6px;
  }
  .castMain .castTag{
    display:none;
  }
  .castMain .castName,
  .castSub{
    display:none;
  }
  .castName{
    font-size:16px;
    line-height:1.25;
  }
  .weekToggleBtn{
    margin-top:2px;
    padding:5px 9px;
    font-size:11px;
  }
  .cellStack{
    gap:2px;
  }
  .timePlan b,
  .sentWhen{
    font-size:18px;
  }
  .timeActual{
    font-size:14px;
  }
  .replyBox{
    max-width:none;
    padding:8px 9px;
  }
  .replyText{
    font-size:12px;
  }
  .lineActionsDetails{
    padding:10px;
  }
  .lineActionsDetails summary{
    font-size:13px;
  }
  .col-action .btnRow{
    display:grid;
    grid-template-columns:repeat(2, minmax(0, 1fr));
    gap:8px;
  }
  .col-action .btnRow form{
    width:auto;
  }
  .col-action .btn{
    min-height:42px;
    padding:10px 8px;
    font-size:12px;
    border-radius:12px;
    white-space:normal;
  }
  .weekRow td{
    padding:10px 0 0;
    background:transparent;
    border:none;
  }
  .inlineWeek{
    padding:2px 0 0;
  }
  .inlineWeekGrid{
    grid-template-columns:repeat(2, minmax(0, 1fr));
    gap:6px;
  }
  .inlineWeekItem{
    min-height:64px;
    padding:7px 6px;
  }
  .subInfo{
    display:none;
  }
}

</style>

<script>
(() => {
  const bg = document.getElementById('modalBg');
  const closeBtn = document.getElementById('modalClose');
  const cancelBtn = document.getElementById('modalCancel');
  const title = document.getElementById('modalTitle');
  const sub = document.getElementById('modalSub');
  const msg = document.getElementById('modalMsg');

  const inCast = document.getElementById('m_cast_user_id');
  const inKind = document.getElementById('m_kind');
  const inTpl  = document.getElementById('m_template_text');
  const ta = document.getElementById('m_text');
  const form = document.getElementById('modalForm');
  const sendBtn = document.getElementById('modalSend');
  const searchInput = document.getElementById('castSearch');
  const searchForm = document.getElementById('castSearchForm');
  const filterButtons = Array.from(document.querySelectorAll('[data-toolbar-filter]'));
  const kpiButtons = Array.from(document.querySelectorAll('.kpiBtns .k[data-filter]'));
  const densityToggle = document.getElementById('densityToggle');
  const visibleCount = document.getElementById('visibleCount');
  const rows = Array.from(document.querySelectorAll('.tblToday tbody tr.row'));
  const simpleCards = Array.from(document.querySelectorAll('.js-simple-scroll'));
  const densityKey = 'managerTodayDensityCompact';
  let activeFilter = 'all';

  function applyDensity(compact){
    document.body.classList.toggle('today-density-compact', compact);
    if (densityToggle) {
      densityToggle.setAttribute('aria-pressed', compact ? 'true' : 'false');
      densityToggle.textContent = compact ? '標準表示' : '高密度表示';
    }
  }

  function syncActiveButtons(){
    filterButtons.forEach((btn) => {
      btn.classList.toggle('is-active', btn.dataset.toolbarFilter === activeFilter);
    });
    kpiButtons.forEach((btn) => {
      btn.classList.toggle('active', btn.dataset.filter === activeFilter);
    });
  }

  function rowMatchesFilter(row){
    if (activeFilter === 'all') return true;
    if (activeFilter === 'attention') return row.dataset.attention === '1';
    if (activeFilter === 'replied') return row.dataset.replied === '1';
    if (activeFilter === 'confirm_pending') return row.dataset.confirmPending === '1';
    if (activeFilter === 'planned') return row.dataset.state !== 'noplan' && row.dataset.state !== 'off';
    return row.dataset.state === activeFilter;
  }

  function applyBoardFilters(){
    const q = ((searchInput && searchInput.value) || '').trim().toLowerCase();
    let shown = 0;

    rows.forEach((row) => {
      const rowText = `${row.dataset.tag || ''} ${row.dataset.name || ''}`;
      const matchesSearch = q === '' || rowText.includes(q);
      const matchesFilter = rowMatchesFilter(row);
      const visible = matchesSearch && matchesFilter;

      row.hidden = !visible;
      if (visible) {
        shown++;
      }

      const next = row.nextElementSibling;
      if (next && next.classList.contains('weekRow') && !visible) {
        next.hidden = true;
        const toggle = row.querySelector('.js-week-toggle');
        if (toggle) {
          toggle.setAttribute('aria-expanded', 'false');
          toggle.textContent = '今週予定を開く';
        }
      }
    });

    if (visibleCount) {
      visibleCount.textContent = String(shown);
    }

    simpleCards.forEach((card) => {
      const targetRow = card.dataset.target ? document.getElementById(card.dataset.target) : null;
      const rowVisible = !!(targetRow && !targetRow.hidden);
      const cardText = `${card.dataset.tag || ''} ${card.dataset.name || ''}`;
      const matchesSearch = q === '' || cardText.includes(q);
      const matchesFilter = activeFilter === 'all'
        ? true
        : activeFilter === 'wait'
          ? (card.dataset.state === 'wait' || card.dataset.state === 'late')
          : activeFilter === 'late'
            ? card.dataset.state === 'late'
            : false;
      card.hidden = !(rowVisible && matchesSearch && matchesFilter);
    });

    syncActiveButtons();
  }

  function scrollToDetail(targetId){
    const row = targetId ? document.getElementById(targetId) : null;
    if (!row) return;

    const header = document.querySelector('.app-header');
    const headerOffset = header ? header.getBoundingClientRect().height : 0;
    const top = window.scrollY + row.getBoundingClientRect().top - headerOffset - 10;
    window.scrollTo({ top: Math.max(0, top), behavior:'smooth' });
    row.classList.add('row-is-highlighted');
    window.setTimeout(() => row.classList.remove('row-is-highlighted'), 1600);
  }

  function openModal(kind, castId, name, text){
    msg.textContent = '';
    inCast.value = castId;
    inKind.value = kind;
    inTpl.value  = text;
    title.textContent = (kind === 'late') ? '遅刻LINE 送信' : '欠勤LINE 送信';
    sub.textContent = `宛先：${name}（user_id=${castId}）`;
    ta.value = text;
    bg.hidden = false;
    setTimeout(() => ta.focus(), 50);
  }
  function closeModal(){ bg.hidden = true; }

  document.querySelectorAll('.js-open-modal').forEach(btn => {
    btn.addEventListener('click', () => {
      openModal(btn.dataset.kind, btn.dataset.cast, btn.dataset.name, btn.dataset.text);
    });
  });

  closeBtn.addEventListener('click', closeModal);
  cancelBtn.addEventListener('click', closeModal);
  bg.addEventListener('click', (e) => { if (e.target === bg) closeModal(); });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    msg.textContent = '送信中…';
    sendBtn.disabled = true;

    try {
      const fd = new FormData(form);
      const res = await fetch('/wbss/public/manager_today_schedule.php', { method:'POST', body: fd });
      const json = await res.json().catch(() => null);

      if (!res.ok || !json || !json.ok) {
        msg.textContent = '送信失敗：' + (json && json.error ? json.error : ('HTTP ' + res.status));
        sendBtn.disabled = false;
        return;
      }

      msg.textContent = '✅ 送信しました（返信はこの画面に自動反映）';
      setTimeout(() => location.reload(), 600);

    } catch (err) {
      msg.textContent = '送信失敗：通信エラー';
      sendBtn.disabled = false;
    }
  });

  document.querySelectorAll('.js-week-toggle').forEach((btn) => {
    btn.addEventListener('click', () => {
      const targetId = btn.dataset.target;
      const row = targetId ? document.getElementById(targetId) : null;
      if (!row) return;

      const expanded = btn.getAttribute('aria-expanded') === 'true';
      row.hidden = expanded;
      btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
      btn.textContent = expanded ? '今週予定を開く' : '今週予定を閉じる';
    });
  });

  simpleCards.forEach((card) => {
    card.addEventListener('click', () => {
      scrollToDetail(card.dataset.target || '');
    });
  });

  filterButtons.forEach((btn) => {
    btn.addEventListener('click', () => {
      activeFilter = btn.dataset.toolbarFilter || 'all';
      applyBoardFilters();
    });
  });

  kpiButtons.forEach((btn) => {
    btn.addEventListener('click', () => {
      activeFilter = btn.dataset.filter || 'all';
      applyBoardFilters();
    });
  });

  if (searchInput) {
    searchInput.addEventListener('input', applyBoardFilters);
    searchInput.addEventListener('search', applyBoardFilters);
    searchInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        applyBoardFilters();
        const firstVisible = rows.find((row) => !row.hidden);
        if (firstVisible && searchInput.value.trim() !== '') {
          scrollToDetail(firstVisible.id);
        }
      }
    });
  }

  if (searchForm) {
    searchForm.addEventListener('submit', (e) => {
      if (document.activeElement === searchInput) {
        e.preventDefault();
        applyBoardFilters();
        const firstVisible = rows.find((row) => !row.hidden);
        if (firstVisible && searchInput && searchInput.value.trim() !== '') {
          scrollToDetail(firstVisible.id);
        }
      }
    });
  }

  if (densityToggle) {
    const savedCompact = localStorage.getItem(densityKey) === '1';
    applyDensity(savedCompact);
    densityToggle.addEventListener('click', () => {
      const next = !document.body.classList.contains('today-density-compact');
      localStorage.setItem(densityKey, next ? '1' : '0');
      applyDensity(next);
    });
  }

  applyBoardFilters();
})();
</script>

<?php render_page_end(); ?>
