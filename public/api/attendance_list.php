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

function out(array $a, int $code=200): never {
  http_response_code($code);
  echo json_encode($a, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

$ym = (string)($_GET['ym'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) out(['ok'=>false,'error'=>'invalid ym'], 400);

$store_id = isset($_GET['store_id']) && $_GET['store_id'] !== '' ? (int)$_GET['store_id'] : null;
$person_type = (string)($_GET['person_type'] ?? '');
$q = trim((string)($_GET['q'] ?? ''));

$from = $ym . '-01';
$to = (new DateTimeImmutable($from))->modify('+1 month')->format('Y-m-d');

$where = [];
$params = [];

$where[] = "shift_date >= :from_d AND shift_date < :to_d";
$params['from_d'] = $from;
$params['to_d'] = $to;

if ($store_id !== null) {
  $where[] = "store_id = :store_id";
  $params['store_id'] = $store_id;
}
if ($person_type === 'cast' || $person_type === 'staff') {
  $where[] = "person_type = :ptype";
  $params['ptype'] = $person_type;
}
if ($q !== '') {
  // PDOは同名placeholder複数使用で落ちやすいので分ける
  $where[] = "(person_name LIKE :q1 OR note LIKE :q2)";
  $params['q1'] = '%'.$q.'%';
  $params['q2'] = '%'.$q.'%';
}

$sql = "SELECT shift_id, store_id, person_type, person_id, person_name, shift_date, start_time, end_time, status, note
        FROM attendance_shifts
        WHERE ".implode(' AND ', $where)."
        ORDER BY shift_date ASC, person_type ASC, start_time ASC, person_name ASC, shift_id ASC";

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

out(['ok'=>true,'rows'=>$rows]);