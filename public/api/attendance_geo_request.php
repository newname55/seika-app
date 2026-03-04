<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';

require_login();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$pdo = db();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function jexit(int $code, string $msg, array $extra=[]): void {
  http_response_code($code);
  echo json_encode(array_merge(['ok'=>false,'error'=>$msg], $extra), JSON_UNESCAPED_UNICODE);
  exit;
}

function conf(string $key): string {
  if (defined($key)) return (string)constant($key);
  $v = getenv($key);
  return is_string($v) ? $v : '';
}
if (!function_exists('normalize_whitespace')) {
  function normalize_whitespace(string $s): string {
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim((string)$s);
  }
}
/**
 * CSRF:
 * - プロジェクト側 csrf_verify($token) があればそれを使う（例外でも落ちない）
 * - 無ければセッションの csrf_token / _csrf を見る
 */
function csrf_ok(): bool {
  $t = (string)($_POST['csrf_token'] ?? $_POST['_csrf'] ?? $_POST['csrf'] ?? '');
  if ($t === '') return false;

  if (function_exists('csrf_verify')) {
    try {
      $r = csrf_verify($t);
      return ($r === null) ? true : (bool)$r; // 返り値型ゆらぎ吸収
    } catch (Throwable $e) {
      // fallthrough
    }
  }
  $s = (string)($_SESSION['csrf_token'] ?? $_SESSION['_csrf'] ?? '');
  return ($s !== '' && hash_equals($s, $t));
}

function resolve_store_id(): int {
  $sid = (int)($_POST['store_id'] ?? 0);
  if ($sid <= 0) $sid = (int)($_SESSION['store_id'] ?? 0);
  return $sid;
}

function current_uid(): int {
  if (function_exists('current_user_id')) return (int)current_user_id();
  return (int)($_SESSION['user_id'] ?? 0);
}

function line_push(string $accessToken, string $to, array $messages): array {
  $body = json_encode(['to'=>$to,'messages'=>$messages], JSON_UNESCAPED_UNICODE);

  $ch = curl_init('https://api.line.me/v2/bot/message/push');
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'Authorization: Bearer ' . $accessToken,
    ],
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
  ]);
  $res  = (string)curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = (string)curl_error($ch);
  curl_close($ch);

  return [$code, $res, $err];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  jexit(405, 'Method not allowed');
}
if (!csrf_ok()) {
  jexit(403, 'CSRF');
}

$action = (string)($_POST['action'] ?? ''); // clock_in | clock_out
if (!in_array($action, ['clock_in','clock_out'], true)) {
  jexit(400, 'Invalid action');
}

$storeId = resolve_store_id();
if ($storeId <= 0) {
  jexit(400, 'store_id missing');
}
$_SESSION['store_id'] = $storeId;

$uid = current_uid();
if ($uid <= 0) {
  jexit(401, 'No user');
}

/**
 * ✅ 重要：castでも通す（ここが admin 限定だと 403 になる）
 * storeに所属してるかだけチェック
 */
$st = $pdo->prepare("
  SELECT COUNT(*)
  FROM user_roles ur
  JOIN roles r ON r.id=ur.role_id
  WHERE ur.user_id=? AND ur.store_id=? AND r.code IN ('cast','manager','admin','super_user')
");
$st->execute([$uid, $storeId]);
if ((int)$st->fetchColumn() <= 0) {
  jexit(403, 'Forbidden(store)');
}

/** 本人の LINE userId */
$st = $pdo->prepare("
  SELECT provider_user_id
  FROM user_identities
  WHERE user_id=? AND provider='line' AND is_active=1 AND provider_user_id<>''
  LIMIT 1
");
$st->execute([$uid]);
$lineUserId = (string)($st->fetchColumn() ?: '');
if ($lineUserId === '') {
  jexit(400, 'LINE未連携（user_identities.provider_user_id が無い）');
}

/** line_geo_pending に入れる（webhook側が拾う） */
$pdo->prepare("DELETE FROM line_geo_pending WHERE provider_user_id=?")->execute([$lineUserId]);

$st = $pdo->prepare("
  INSERT INTO line_geo_pending
    (provider_user_id, action, store_id, created_at, expires_at)
  VALUES
    (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 3 MINUTE))
");
$st->execute([$lineUserId, $action, $storeId]);

/** 店名と表示名 */
$st = $pdo->prepare("SELECT name FROM stores WHERE id=? LIMIT 1");
$st->execute([$storeId]);
$storeName = (string)($st->fetchColumn() ?: ('#'.$storeId));

$st = $pdo->prepare("SELECT display_name FROM users WHERE id=? LIMIT 1");
$st->execute([$uid]);
$myName = (string)($st->fetchColumn() ?: ('user#'.$uid));

/** LINE push（本人に「位置情報を送る」） */
$accessToken = conf('LINE_MSG_CHANNEL_ACCESS_TOKEN');
if ($accessToken === '') {
  jexit(500, 'LINE_MSG_CHANNEL_ACCESS_TOKEN missing');
}

$label = ($action === 'clock_in') ? '出勤' : '退勤';
$text  = "{$label}を受付します。\n"
       . "店舗: {$storeName}\n"
       . "名前: {$myName}\n"
       . "下の「位置情報を送る」を押してください（3分以内）";

$messages = [[
  'type' => 'text',
  'text' => $text,
  'quickReply' => [
    'items' => [[
      'type' => 'action',
      'action' => [
        'type' => 'location',
        'label' => '位置情報を送る',
      ],
    ]],
  ],
]];

[$code, $res, $err] = line_push($accessToken, $lineUserId, $messages);
if ($code < 200 || $code >= 300) {
  jexit(500, 'LINE push failed', [
    'http_code' => $code,
    'err' => $err,
    'res' => mb_substr($res, 0, 200),
  ]);
}

echo json_encode([
  'ok' => true,
  'store_id' => $storeId,
  'user_id' => $uid,
  'provider_user_id' => $lineUserId,
  'action' => $action,
], JSON_UNESCAPED_UNICODE);