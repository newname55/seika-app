<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';

require_login();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$pdo = db();

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('conf')) {
  function conf(string $key): string {
    if (defined($key)) return (string)constant($key);
    $v = getenv($key);
    return is_string($v) ? $v : '';
  }
}

/** CSRF（プロジェクト側があればそれを優先。なければ簡易） */
function csrf_ok(): bool {
  if (function_exists('csrf_verify')) {
    try {
      $r = csrf_verify($_POST['csrf_token'] ?? null);
      return ($r === null) ? true : (bool)$r;
    } catch (Throwable $e) { /* fallback */ }
  }
  $t = (string)($_POST['csrf_token'] ?? '');
  $s = (string)($_SESSION['csrf_token'] ?? '');
  return ($t !== '' && $s !== '' && hash_equals($s, $t));
}

function json_out(int $code, array $payload): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

/** LINE push */
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
  $res  = (string)curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  return [$code, $res];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_out(405, ['ok'=>false, 'error'=>'Method not allowed']);
}
if (!csrf_ok()) {
  json_out(403, ['ok'=>false, 'error'=>'CSRF']);
}

$storeId = (int)($_POST['store_id'] ?? 0);
$action  = (string)($_POST['action'] ?? ''); // clock_in | clock_out

if ($storeId <= 0) json_out(400, ['ok'=>false, 'error'=>'store_id missing']);
if (!in_array($action, ['clock_in','clock_out'], true)) {
  json_out(400, ['ok'=>false, 'error'=>'invalid action']);
}

$me = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
if ($me <= 0) json_out(401, ['ok'=>false, 'error'=>'No user']);

/** 自分がそのstoreに属しているか（cast/manager/admin/super_user） */
$st = $pdo->prepare("
  SELECT COUNT(*)
  FROM user_roles ur
  JOIN roles r ON r.id=ur.role_id
  WHERE ur.user_id=? AND ur.store_id=? AND r.code IN ('cast','manager','admin','super_user')
");
$st->execute([$me, $storeId]);
if ((int)$st->fetchColumn() <= 0) {
  json_out(403, ['ok'=>false, 'error'=>'Forbidden(store)']);
}

/** 本人のLINE userId 取得 */
$st = $pdo->prepare("
  SELECT provider_user_id
  FROM user_identities
  WHERE user_id=? AND provider='line' AND is_active=1
  ORDER BY id DESC
  LIMIT 1
");
$st->execute([$me]);
$lineUserId = (string)($st->fetchColumn() ?: '');
if ($lineUserId === '') {
  json_out(400, [
    'ok'=>false,
    'error'=>'LINE未連携です（user_identities に line がありません）',
    'hint'=>'先にLINEログイン/連携を完了してください',
  ]);
}

/** Bot token */
$accessToken = conf('LINE_MSG_CHANNEL_ACCESS_TOKEN');
if ($accessToken === '') {
  json_out(500, ['ok'=>false, 'error'=>'LINE_MSG_CHANNEL_ACCESS_TOKEN missing']);
}

/** 店名/表示名 */
$st = $pdo->prepare("SELECT name FROM stores WHERE id=? LIMIT 1");
$st->execute([$storeId]);
$storeName = (string)($st->fetchColumn() ?: ('#'.$storeId));

$st = $pdo->prepare("SELECT display_name FROM users WHERE id=? LIMIT 1");
$st->execute([$me]);
$myName = (string)($st->fetchColumn() ?: ('user#'.$me));

/** pending作成（既存があれば上書き的に追加でOK） */
$now = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
$expires = (clone $now)->modify('+5 minutes');

$st = $pdo->prepare("
  INSERT INTO line_geo_pending (line_user_id, action, store_id, created_at, expires_at)
  VALUES (?, ?, ?, ?, ?)
");
$st->execute([
  $lineUserId,
  $action,
  $storeId,
  $now->format('Y-m-d H:i:s'),
  $expires->format('Y-m-d H:i:s'),
]);

$label = ($action === 'clock_in') ? '出勤' : '退勤';
$text  = "{$myName} さん：{$label}の位置情報を送ってください（店舗：{$storeName}）\n"
       . "下の「位置情報を送る」を押してください。";

/** quickReply location */
$messages = [[
  'type' => 'text',
  'text' => $text,
  'quickReply' => [
    'items' => [[
      'type' => 'action',
      'action' => [
        'type'  => 'location',
        'label' => '位置情報を送る',
      ],
    ]],
  ],
]];

[$code, $res] = line_push($accessToken, $lineUserId, $messages);
if ($code < 200 || $code >= 300) {
  json_out(500, [
    'ok'=>false,
    'error'=>'LINE push failed',
    'http_code'=>$code,
    'res'=>mb_substr($res, 0, 500),
  ]);
}

json_out(200, [
  'ok' => true,
  'store_id' => $storeId,
  'user_id' => $me,
  'line_user_id' => $lineUserId,
  'action' => $action,
  'expires_at' => $expires->format('Y-m-d H:i:s'),
]);