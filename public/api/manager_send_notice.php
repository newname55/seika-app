<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';

require_login();
require_role(['admin','manager','super_user']);

if (!function_exists('conf')) {
  function conf(string $key): string {
    if (defined($key)) return (string)constant($key);
    $v = getenv($key);
    return is_string($v) ? $v : '';
  }
}
if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

header('Content-Type: application/json; charset=utf-8');

$csrf = (string)($_POST['csrf_token'] ?? '');
if (function_exists('csrf_token') && $csrf !== (string)csrf_token()) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'CSRF'], JSON_UNESCAPED_UNICODE);
  exit;
}

$pdo = db();

$storeId = (int)($_POST['store_id'] ?? 0);
$targetUserId = (int)($_POST['target_user_id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
$text = trim((string)($_POST['message_text'] ?? ''));

if ($storeId <= 0 || $targetUserId <= 0 || !in_array($action, ['late','absent'], true)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'bad request'], JSON_UNESCAPED_UNICODE);
  exit;
}
if ($text === '') {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'message empty'], JSON_UNESCAPED_UNICODE);
  exit;
}
if (mb_strlen($text) > 500) $text = mb_substr($text, 0, 500);

// 対象キャストの LINE userId を取得
$st = $pdo->prepare("
  SELECT provider_user_id
  FROM user_identities
  WHERE user_id=? AND provider='line' AND is_active=1
  LIMIT 1
");
$st->execute([$targetUserId]);
$toLineUserId = (string)($st->fetchColumn() ?: '');

if ($toLineUserId === '') {
  echo json_encode(['ok'=>false,'error'=>'LINE未連携（本人）'], JSON_UNESCAPED_UNICODE);
  exit;
}

$accessToken = conf('LINE_MSG_CHANNEL_ACCESS_TOKEN');
if ($accessToken === '') {
  echo json_encode(['ok'=>false,'error'=>'LINE access token missing'], JSON_UNESCAPED_UNICODE);
  exit;
}

// push message
$body = [
  'to' => $toLineUserId,
  'messages' => [
    ['type'=>'text', 'text'=>$text]
  ]
];

$url = 'https://api.line.me/v2/bot/message/push';
$opts = [
  'http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$accessToken}\r\n",
    'content' => json_encode($body, JSON_UNESCAPED_UNICODE),
    'timeout' => 15,
    'ignore_errors' => true,
  ]
];
$res = @file_get_contents($url, false, stream_context_create($opts));
$code = 0;
if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
  $code = (int)$m[1];
}

$sentBy = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);

// 履歴保存
$lineResult = 'HTTP ' . $code;
$st = $pdo->prepare("
  INSERT INTO line_notice_logs
    (store_id, target_user_id, action, message_text, sent_by_user_id, sent_at, line_to_provider_user_id, line_result)
  VALUES
    (?, ?, ?, ?, ?, NOW(), ?, ?)
");
$st->execute([$storeId, $targetUserId, $action, $text, $sentBy, $toLineUserId, $lineResult]);

if ($code >= 200 && $code < 300) {
  echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
  exit;
}

echo json_encode(['ok'=>false,'error'=>'LINE push failed','detail'=>substr((string)$res,0,300)], JSON_UNESCAPED_UNICODE);