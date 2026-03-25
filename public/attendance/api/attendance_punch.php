<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/auth.php';
require_once __DIR__ . '/../../../app/db.php';
require_once __DIR__ . '/../../../app/store_decommission.php';

/**
 * Legacy direct notify endpoint.
 *
 * Canonical rules:
 * - actual source of truth: attendances
 * - this endpoint only notifies managers with browser-sent location
 * - canonical LINE geo punch flow is attendance_geo_request.php + line_webhook.php
 */

require_login();

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$pdo = db();

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
function has_role(string $role): bool {
  return isset($_SESSION['roles']) && in_array($role, $_SESSION['roles'], true);
}

/** CSRF（既存があればそれを使う / 無ければ簡易） */
function csrf_ok(): bool {
  if (function_exists('csrf_verify')) {
    // プロジェクト側の csrf_verify() が「引数なし」想定のことが多いので
    // 返り値が bool の場合に備える（例外でも落ちないように）
    try {
      $r = csrf_verify($_POST['csrf_token'] ?? null);
      return ($r === null) ? true : (bool)$r;
    } catch (Throwable $e) {
      // fallthrough
    }
  }
  $t = (string)($_POST['csrf_token'] ?? '');
  $s = (string)($_SESSION['csrf_token'] ?? '');
  return ($t !== '' && $s !== '' && hash_equals($s, $t));
}

/** store_id を安全に決める（store.php の require_store_selected() が引数必須になってても影響しない） */
function resolve_store_id(): int {
  $sid = 0;
  if (function_exists('current_store_id')) {
    try { $sid = (int)current_store_id(); } catch (Throwable $e) { /* ignore */ }
  }
  if ($sid <= 0) $sid = (int)($_POST['store_id'] ?? 0);
  if ($sid <= 0) $sid = (int)($_SESSION['store_id'] ?? 0);
  return $sid;
}

/** LINE push（複数宛先に順番にpush） */
function line_push(string $accessToken, string $to, array $messages): array {
  $body = json_encode([
    'to' => $to,
    'messages' => $messages,
  ], JSON_UNESCAPED_UNICODE);

  $ch = curl_init('https://api.line.me/v2/bot/message/push');
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'Authorization: Bearer ' . $accessToken,
    ],
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
  ]);
  $res = (string)curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  return [$code, $res];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method not allowed');
}
if (!csrf_ok()) {
  http_response_code(403);
  exit('CSRF');
}

$action = (string)($_POST['action'] ?? ''); // in | out | clock_in | clock_out
$lat = (float)($_POST['lat'] ?? 0);
$lng = (float)($_POST['lng'] ?? 0);
$accuracy = (int)($_POST['accuracy'] ?? 0);

$action = match ($action) {
  'in', 'clock_in' => 'clock_in',
  'out', 'clock_out' => 'clock_out',
  default => '',
};

if ($action === '') {
  http_response_code(400);
  exit('Invalid action');
}
if ($lat === 0.0 || $lng === 0.0) {
  http_response_code(400);
  exit('Location missing');
}

$storeId = resolve_store_id();
if ($storeId <= 0) {
  http_response_code(400);
  exit('store_id missing');
}
store_decommission_assert_store_writable($pdo, $storeId, '停止中の店舗では打刻通知できません');
$_SESSION['store_id'] = $storeId;

$me = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
if ($me <= 0) {
  http_response_code(401);
  exit('No user');
}

/** 自分がそのstoreに紐づくユーザーか（cast/manager/admin/super_user どれか） */
$st = $pdo->prepare("
  SELECT COUNT(*)
  FROM user_roles ur
  JOIN roles r ON r.id=ur.role_id
  WHERE ur.user_id=? AND ur.store_id=? AND r.code IN ('cast','manager','admin','super_user')
");
$st->execute([$me, $storeId]);
if ((int)$st->fetchColumn() <= 0) {
  // super_user だけは store_id NULL 運用があるなら許可
  if (!has_role('super_user')) {
    http_response_code(403);
    exit('Forbidden(store)');
  }
}

/** 店名 */
$st = $pdo->prepare("SELECT name FROM stores WHERE id=? LIMIT 1");
$st->execute([$storeId]);
$storeName = (string)($st->fetchColumn() ?: ('#'.$storeId));

/** 自分の表示名 */
$st = $pdo->prepare("SELECT display_name FROM users WHERE id=? LIMIT 1");
$st->execute([$me]);
$myName = (string)($st->fetchColumn() ?: ('user#'.$me));

/** 管理者（admin/manager/super_user）のLINE userId 一覧 */
$st = $pdo->prepare("
  SELECT DISTINCT ui.provider_user_id
  FROM user_roles ur
  JOIN roles r ON r.id=ur.role_id AND r.code IN ('admin','manager','super_user')
  JOIN user_identities ui ON ui.user_id=ur.user_id
  WHERE ur.store_id=?
    AND ui.provider='line'
    AND ui.is_active=1
    AND ui.provider_user_id <> ''
");
$st->execute([$storeId]);
$toList = array_values(array_filter(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: [])));

/** LINE Token */
$accessToken = conf('LINE_MSG_CHANNEL_ACCESS_TOKEN');
if ($accessToken === '') {
  http_response_code(500);
  exit('LINE_MSG_CHANNEL_ACCESS_TOKEN missing');
}

$now = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
$label = ($action === 'clock_in') ? '出勤' : '退勤';
$gmap = "https://maps.google.com/?q={$lat},{$lng}";
$accTxt = $accuracy > 0 ? "精度:約{$accuracy}m" : "精度:不明";

$text = "\n"
      . "店舗: {$storeName}\n"
      . "名前: {$myName}\n"
      . "時刻: {$now}\n"
      . "{$accTxt}\n"
      . "地図: {$gmap}";

$messages = [
  [
    'type' => 'location',
    'title' => "{$myName}（{$storeName}）",
    'address' => "{$accTxt}\n{$now}",
    'latitude' => $lat,
    'longitude' => $lng,
  ],
  [
    'type' => 'text',
    'text' => $text,
  ],
];

$sent = 0;
$errs = [];

foreach ($toList as $to) {
  [$code, $res] = line_push($accessToken, $to, $messages);
  if ($code >= 200 && $code < 300) {
    $sent++;
  } else {
    $errs[] = ['to'=>$to, 'code'=>$code, 'res'=>mb_substr($res, 0, 200)];
  }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
  'ok' => true,
  'store_id' => $storeId,
  'sent' => $sent,
  'recipients' => count($toList),
  'action' => $action,
  'warning' => 'legacy_notify_only_no_attendance_update',
  'errors' => $errs,
], JSON_UNESCAPED_UNICODE);
