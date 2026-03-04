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
$store_id = array_key_exists('store_id', $data) ? (is_null($data['store_id']) ? null : (int)$data['store_id']) : null;

$shift_date = (string)($data['shift_date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $shift_date)) out(['ok'=>false,'error'=>'invalid date'], 400);

$person_type = (string)($data['person_type'] ?? 'cast');
if (!in_array($person_type, ['cast','staff'], true)) out(['ok'=>false,'error'=>'invalid person_type'], 400);

$person_name = trim((string)($data['person_name'] ?? ''));
if ($person_name === '') out(['ok'=>false,'error'=>'name required'], 400);
if (mb_strlen($person_name) > 120) $person_name = mb_substr($person_name, 0, 120);

$start_time = $data['start_time'] ?? null;
$end_time   = $data['end_time'] ?? null;
if ($start_time !== null && $start_time !== '' && !preg_match('/^\d{2}:\d{2}$/', (string)$start_time)) out(['ok'=>false,'error'=>'invalid start_time'], 400);
if ($end_time !== null && $end_time !== '' && !preg_match('/^\d{2}:\d{2}$/', (string)$end_time)) out(['ok'=>false,'error'=>'invalid end_time'], 400);

$status = (string)($data['status'] ?? 'scheduled');
if (!in_array($status, ['scheduled','confirmed','cancelled'], true)) out(['ok'=>false,'error'=>'invalid status'], 400);

$note = $data['note'] ?? null;
$note = is_string($note) ? trim($note) : null;

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if ($shift_id > 0) {
  $sql = "UPDATE attendance_shifts
          SET store_id=:store_id, person_type=:ptype, person_name=:pname,
              shift_date=:sdate, start_time=:stime, end_time=:etime,
              status=:status, note=:note
          WHERE shift_id=:id";
  $st = $pdo->prepare($sql);
  $st->execute([
    'store_id'=>$store_id,
    'ptype'=>$person_type,
    'pname'=>$person_name,
    'sdate'=>$shift_date,
    'stime'=>$start_time ?: null,
    'etime'=>$end_time ?: null,
    'status'=>$status,
    'note'=>$note,
    'id'=>$shift_id,
  ]);
  out(['ok'=>true,'shift_id'=>$shift_id,'mode'=>'update']);
} else {
  $sql = "INSERT INTO attendance_shifts
          (store_id, person_type, person_name, shift_date, start_time, end_time, status, note)
          VALUES
          (:store_id,:ptype,:pname,:sdate,:stime,:etime,:status,:note)";
  $st = $pdo->prepare($sql);
  $st->execute([
    'store_id'=>$store_id,
    'ptype'=>$person_type,
    'pname'=>$person_name,
    'sdate'=>$shift_date,
    'stime'=>$start_time ?: null,
    'etime'=>$end_time ?: null,
    'status'=>$status,
    'note'=>$note,
  ]);
  out(['ok'=>true,'shift_id'=>(int)$pdo->lastInsertId(),'mode'=>'insert']);
}