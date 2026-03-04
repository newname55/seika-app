<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Tokyo');

$root = dirname(__DIR__, 2);
$auth_candidates = [$root.'/app/auth.php', $root.'/auth.php'];
$db_candidates   = [$root.'/app/db.php', $root.'/app/_db.php', $root.'/db.php', $root.'/_db.php'];

foreach ($auth_candidates as $f) { if (is_file($f)) { require_once $f; break; } }
foreach ($db_candidates as $f)   { if (is_file($f)) { require_once $f; break; } }

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('db')) { echo json_encode(['ok'=>false,'error'=>'db() not found'], JSON_UNESCAPED_UNICODE); exit; }
if (function_exists('require_login')) { require_login(); }

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!function_exists('csrf_token')) {
  function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['csrf_token'];
  }
}

function out(array $a, int $code=200): never {
  http_response_code($code);
  echo json_encode($a, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) out(['ok'=>false,'error'=>'invalid json'], 400);

$token = (string)($data['csrf_token'] ?? '');
if ($token === '' || !hash_equals(csrf_token(), $token)) out(['ok'=>false,'error'=>'csrf'], 403);

$shift_id = (int)($data['shift_id'] ?? 0);
if ($shift_id <= 0) out(['ok'=>false,'error'=>'invalid shift_id'], 400);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$st = $pdo->prepare("DELETE FROM attendance_shifts WHERE shift_id = :id");
$st->execute(['id'=>$shift_id]);

out(['ok'=>true]);