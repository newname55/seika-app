<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';

require_login();
require_role(['manager','admin','super_user']);

ensure_session();

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

/** CSRF（プロジェクトの関数があれば使う。なければ簡易） */
function csrf_ok(): bool {
  if (function_exists('csrf_verify')) return (bool)csrf_verify();
  $t = (string)($_POST['csrf_token'] ?? '');
  $s = (string)($_SESSION['csrf_token'] ?? '');
  return ($t !== '' && $s !== '' && hash_equals($s, $t));
}
function csrf_token_local(): string {
  if (function_exists('csrf_token')) return (string)csrf_token();
  if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
  return (string)$_SESSION['csrf_token'];
}

$pdo = db();

$accessToken = conf('LINE_MSG_CHANNEL_ACCESS_TOKEN');
if ($accessToken === '') {
  http_response_code(500);
  exit('LINE_MSG_CHANNEL_ACCESS_TOKEN missing');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method not allowed');
}
if (!csrf_ok()) {
  http_response_code(403);
  exit('CSRF');
}

$storeId = (int)($_POST['store_id'] ?? 0);
$userId  = (int)($_POST['user_id'] ?? 0);
$kind    = (string)($_POST['kind'] ?? 'late'); // late | absent
$bizDate = (string)($_POST['business_date'] ?? '');

if ($storeId <= 0 || $userId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $bizDate)) {
  http_response_code(400);
  exit('Invalid params');
}
if (!in_array($kind, ['late','absent'], true)) $kind = 'late';

/** 権限制約：manager/admin は自分のstoreのみ、superは自由（既存方針に合わせる） */
function has_role(string $role): bool {
  return isset($_SESSION['roles']) && in_array($role, $_SESSION['roles'], true);
}
$isSuper = has_role('super_user');
if (!$isSuper) {
  $my = 0;
  $me = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
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
  $st->execute([$me]);
  $my = (int)($st->fetchColumn() ?: 0);
  if ($my <= 0 || $my !== $storeId) {
    http_response_code(403);
    exit('Forbidden(store)');
  }
}

/** 対象ユーザーがその店のcastか確認 */
$st = $pdo->prepare("
  SELECT u.display_name
  FROM user_roles ur
  JOIN roles r ON r.id=ur.role_id AND r.code='cast'
  JOIN users u ON u.id=ur.user_id
  WHERE ur.store_id=? AND ur.user_id=?
  LIMIT 1
");
$st->execute([$storeId, $userId]);
$castName = (string)($st->fetchColumn() ?: '');
if ($castName === '') {
  http_response_code(404);
  exit('cast not found');
}

/** LINE userId（Login/Hookで使ってる provider_user_id をそのままpushに使う） */
$st = $pdo->prepare("
  SELECT provider_user_id
  FROM user_identities
  WHERE user_id=? AND provider='line' AND is_active=1
  LIMIT 1
");
$st->execute([$userId]);
$lineUserId = (string)($st->fetchColumn() ?: '');
if ($lineUserId === '') {
  http_response_code(400);
  exit('LINE not linked');
}

/** メッセージ */
$prefix = ($kind === 'late') ? '【遅刻連絡】' : '【欠勤確認】';
$text = $prefix . "\n"
      . "営業日: {$bizDate}\n"
      . "対象: {$castName}\n\n"
      . (($kind === 'late')
          ? "出勤が未確認です。状況を返信してください。\n（到着予定時刻もお願いします）"
          : "本日の出勤が未確認です。出勤可否を返信してください。");

/** PUSH */
$body = json_encode([
  'to' => $lineUserId,
  'messages' => [[
    'type' => 'text',
    'text' => $text,
  ]],
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
$res = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code >= 300) {
  http_response_code(500);
  exit('LINE push failed: ' . substr((string)$res, 0, 300));
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
