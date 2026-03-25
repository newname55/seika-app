<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Tokyo');

/**
 * Legacy attendance save endpoint.
 *
 * Canonical rules:
 * - actual source of truth for cast attendance: attendances
 * - attendance_shifts is legacy and only kept for non-cast compatibility
 */

$root = dirname(__DIR__, 3);
$auth_candidates = [$root.'/app/auth.php', $root.'/auth.php'];
$db_candidates   = [$root.'/app/db.php', $root.'/db.php'];

foreach ($auth_candidates as $f) { if (is_file($f)) { require_once $f; break; } }
foreach ($db_candidates as $f)   { if (is_file($f)) { require_once $f; break; } }
require_once $root . '/app/store_decommission.php';

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

function normalize_hm_or_null(mixed $value): ?string {
  if ($value === null) return null;
  $s = trim((string)$value);
  if ($s === '') return null;
  if (!preg_match('/^\d{2}:\d{2}$/', $s)) return null;
  return $s;
}

function to_datetime_or_null(string $ymd, ?string $hm): ?string {
  if ($hm === null) return null;
  return $ymd . ' ' . $hm . ':00';
}

function canonical_attendance_status(?string $clockIn, ?string $clockOut, string $requestedStatus): string {
  if ($requestedStatus === 'cancelled') return 'canceled';
  if ($clockIn !== null && $clockOut !== null) return 'finished';
  if ($clockIn !== null) return 'working';
  return 'scheduled';
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) out(['ok'=>false,'error'=>'invalid json'], 400);

$token = (string)($data['csrf_token'] ?? '');
if ($token === '' || !hash_equals(csrf_token(), $token)) out(['ok'=>false,'error'=>'csrf'], 403);

$shift_id = (int)($data['shift_id'] ?? 0);
$store_id = array_key_exists('store_id', $data) ? (is_null($data['store_id']) ? null : (int)$data['store_id']) : null;
if ($store_id === null || $store_id <= 0) out(['ok'=>false,'error'=>'invalid store_id'], 400);

$shift_date = (string)($data['shift_date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $shift_date)) out(['ok'=>false,'error'=>'invalid date'], 400);

$person_type = (string)($data['person_type'] ?? 'cast');
if (!in_array($person_type, ['cast','staff'], true)) out(['ok'=>false,'error'=>'invalid person_type'], 400);

$person_name = trim((string)($data['person_name'] ?? ''));
if ($person_name === '') out(['ok'=>false,'error'=>'name required'], 400);
if (mb_strlen($person_name) > 120) $person_name = mb_substr($person_name, 0, 120);

$start_time = normalize_hm_or_null($data['start_time'] ?? null);
$end_time   = normalize_hm_or_null($data['end_time'] ?? null);
if (($data['start_time'] ?? null) !== null && trim((string)($data['start_time'] ?? '')) !== '' && $start_time === null) {
  out(['ok'=>false,'error'=>'invalid start_time'], 400);
}
if (($data['end_time'] ?? null) !== null && trim((string)($data['end_time'] ?? '')) !== '' && $end_time === null) {
  out(['ok'=>false,'error'=>'invalid end_time'], 400);
}

$status = (string)($data['status'] ?? 'scheduled');
if (!in_array($status, ['scheduled','confirmed','cancelled'], true)) out(['ok'=>false,'error'=>'invalid status'], 400);

$note = $data['note'] ?? null;
$note = is_string($note) ? trim($note) : null;

$cast_user_id = (int)($data['cast_id'] ?? $data['user_id'] ?? $data['person_id'] ?? 0);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
store_decommission_assert_store_writable($pdo, (int)$store_id, '停止中の店舗では出勤保存できません');

if ($person_type === 'cast') {
  if ($cast_user_id <= 0) {
    out([
      'ok' => false,
      'error' => 'cast_id required',
      'detail' => 'Cast attendance must be saved to attendances with cast_id/user_id/person_id.',
    ], 400);
  }

  $clockIn = ($status === 'cancelled') ? null : to_datetime_or_null($shift_date, $start_time);
  $clockOut = ($status === 'cancelled') ? null : to_datetime_or_null($shift_date, $end_time);
  $attendanceStatus = canonical_attendance_status($clockIn, $clockOut, $status);

  if ($shift_id > 0) {
    $sql = "UPDATE attendances
            SET store_id=:store_id,
                user_id=:user_id,
                business_date=:bdate,
                clock_in=:clock_in,
                clock_out=:clock_out,
                status=:status,
                note=:note,
                updated_at=NOW()
            WHERE id=:id";
    $st = $pdo->prepare($sql);
    $st->execute([
      'store_id' => $store_id,
      'user_id' => $cast_user_id,
      'bdate' => $shift_date,
      'clock_in' => $clockIn,
      'clock_out' => $clockOut,
      'status' => $attendanceStatus,
      'note' => $note,
      'id' => $shift_id,
    ]);

    out([
      'ok' => true,
      'shift_id' => $shift_id,
      'attendance_id' => $shift_id,
      'mode' => 'update',
      'table' => 'attendances',
    ]);
  }

  $find = $pdo->prepare("
    SELECT id
    FROM attendances
    WHERE store_id=:store_id AND user_id=:user_id AND business_date=:bdate
    LIMIT 1
  ");
  $find->execute([
    'store_id' => $store_id,
    'user_id' => $cast_user_id,
    'bdate' => $shift_date,
  ]);
  $existingId = (int)($find->fetchColumn() ?: 0);

  if ($existingId > 0) {
    $sql = "UPDATE attendances
            SET clock_in=:clock_in,
                clock_out=:clock_out,
                status=:status,
                note=:note,
                updated_at=NOW()
            WHERE id=:id";
    $st = $pdo->prepare($sql);
    $st->execute([
      'clock_in' => $clockIn,
      'clock_out' => $clockOut,
      'status' => $attendanceStatus,
      'note' => $note,
      'id' => $existingId,
    ]);

    out([
      'ok' => true,
      'shift_id' => $existingId,
      'attendance_id' => $existingId,
      'mode' => 'update',
      'table' => 'attendances',
    ]);
  }

  $sql = "INSERT INTO attendances
          (user_id, store_id, business_date, clock_in, clock_out, status, note, created_at, updated_at)
          VALUES
          (:user_id, :store_id, :bdate, :clock_in, :clock_out, :status, :note, NOW(), NOW())";
  $st = $pdo->prepare($sql);
  $st->execute([
    'user_id' => $cast_user_id,
    'store_id' => $store_id,
    'bdate' => $shift_date,
    'clock_in' => $clockIn,
    'clock_out' => $clockOut,
    'status' => $attendanceStatus,
    'note' => $note,
  ]);

  $newId = (int)$pdo->lastInsertId();
  out([
    'ok' => true,
    'shift_id' => $newId,
    'attendance_id' => $newId,
    'mode' => 'insert',
    'table' => 'attendances',
  ]);
}

// Legacy path for non-cast scheduling compatibility.
if ($shift_id > 0) {
  $sql = "UPDATE attendance_shifts
          SET store_id=:store_id, person_type=:ptype, person_name=:pname,
              shift_date=:sdate, start_time=:stime, end_time=:etime,
              status=:status, note=:note
          WHERE shift_id=:id";
  $st = $pdo->prepare($sql);
  $st->execute([
    'store_id' => $store_id,
    'ptype' => $person_type,
    'pname' => $person_name,
    'sdate' => $shift_date,
    'stime' => $start_time,
    'etime' => $end_time,
    'status' => $status,
    'note' => $note,
    'id' => $shift_id,
  ]);
  out(['ok'=>true,'shift_id'=>$shift_id,'mode'=>'update','table'=>'attendance_shifts','warning'=>'legacy_non_canonical_table']);
}

$sql = "INSERT INTO attendance_shifts
        (store_id, person_type, person_name, shift_date, start_time, end_time, status, note)
        VALUES
        (:store_id,:ptype,:pname,:sdate,:stime,:etime,:status,:note)";
$st = $pdo->prepare($sql);
$st->execute([
  'store_id' => $store_id,
  'ptype' => $person_type,
  'pname' => $person_name,
  'sdate' => $shift_date,
  'stime' => $start_time,
  'etime' => $end_time,
  'status' => $status,
  'note' => $note,
]);
out([
  'ok'=>true,
  'shift_id'=>(int)$pdo->lastInsertId(),
  'mode'=>'insert',
  'table'=>'attendance_shifts',
  'warning'=>'legacy_non_canonical_table'
]);
