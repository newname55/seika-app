<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';

require_login();
require_role(['manager','admin','super_user']);

/* =========================
   session / CSRF（auth.php に無い環境対策）
========================= */
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

if (!function_exists('csrf_token')) {
  function csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['_csrf'])) {
      $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['_csrf'];
  }
}
if (!function_exists('csrf_verify')) {
  function csrf_verify(?string $token): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $ok = is_string($token) && $token !== '' && isset($_SESSION['_csrf']) && hash_equals((string)$_SESSION['_csrf'], $token);
    if (!$ok) {
      http_response_code(403);
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok'=>false,'error'=>'csrf'], JSON_UNESCAPED_UNICODE);
      exit;
    }
  }
}

function conf(string $key): string {
  if (defined($key)) return (string)constant($key);
  $v = getenv($key);
  return is_string($v) ? $v : '';
}

function json_out(array $a, int $code=200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($a, JSON_UNESCAPED_UNICODE);
  exit;
}

function current_user_id_safe(): int {
  return function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
}

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_out(['ok'=>false,'error'=>'method'], 405);
}

/** CSRF */
csrf_verify((string)($_POST['csrf_token'] ?? ''));

$storeId    = (int)($_POST['store_id'] ?? 0);
$bizDate    = (string)($_POST['business_date'] ?? '');
$castUserId = (int)($_POST['cast_user_id'] ?? 0);
$kind       = (string)($_POST['kind'] ?? '');
$text       = trim((string)($_POST['text'] ?? ''));

if ($storeId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $bizDate) || $castUserId <= 0) {
  json_out(['ok'=>false,'error'=>'bad params'], 400);
}
if (!in_array($kind, ['late','absent'], true)) {
  json_out(['ok'=>false,'error'=>'bad kind'], 400);
}
if ($text === '') {
  json_out(['ok'=>false,'error'=>'empty text'], 400);
}

$senderUserId = current_user_id_safe();
if ($senderUserId <= 0) json_out(['ok'=>false,'error'=>'no sender'], 403);

/**
 * cast の LINE userId を解決（user_identities）
 */
$st = $pdo->prepare("
  SELECT provider_user_id
  FROM user_identities
  WHERE user_id=? AND provider='line' AND is_active=1
  ORDER BY id DESC
  LIMIT 1
");
$st->execute([$castUserId]);
$lineUserId = (string)($st->fetchColumn() ?: '');

if ($lineUserId === '') {
  json_out(['ok'=>false,'error'=>'cast line not linked'], 400);
}

/** Messaging API env */
$accessToken = conf('LINE_MSG_CHANNEL_ACCESS_TOKEN');
if ($accessToken === '') {
  json_out(['ok'=>false,'error'=>'LINE token missing'], 500);
}

/** push 送信 */
function line_push_text(string $accessToken, string $toUserId, string $text): array {
  $url = 'https://api.line.me/v2/bot/message/push';
  $body = json_encode([
    'to' => $toUserId,
    'messages' => [
      ['type' => 'text', 'text' => $text]
    ],
  ], JSON_UNESCAPED_UNICODE);

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'Authorization: Bearer ' . $accessToken,
    ],
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 12,
  ]);
  $res = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);

  return ['code'=>$code, 'res'=>(string)$res, 'err'=>(string)$err];
}

$pdo->beginTransaction();
try {
  $sentAt = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');

  /** 先に履歴作成（送信失敗でも残す） */
  $ins = $pdo->prepare("
    INSERT INTO line_notice_actions
      (store_id, business_date, cast_user_id, kind, sent_by_user_id, sent_at, sent_text, status, created_at, updated_at)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, 'sent', NOW(), NOW())
  ");
  $ins->execute([$storeId, $bizDate, $castUserId, $kind, $senderUserId, $sentAt, $text]);
  $actionId = (int)$pdo->lastInsertId();

  $ret = line_push_text($accessToken, $lineUserId, $text);

  if ($ret['code'] < 200 || $ret['code'] >= 300) {
    $up = $pdo->prepare("
      UPDATE line_notice_actions
      SET status='failed', error_message=?, updated_at=NOW()
      WHERE id=?
      LIMIT 1
    ");
    $errMsg = 'HTTP ' . $ret['code'] . ' ' . substr(($ret['res'] ?: $ret['err']), 0, 180);
    $up->execute([$errMsg, $actionId]);

    $pdo->commit();
    json_out(['ok'=>false,'error'=>$errMsg], 502);
  }

  $pdo->commit();
  json_out(['ok'=>true]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_out(['ok'=>false,'error'=>$e->getMessage()], 500);
}