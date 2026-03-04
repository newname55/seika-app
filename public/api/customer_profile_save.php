<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';

require_login();
if (function_exists('require_role')) {
  require_role(['cast','admin','manager','super_user']);
}

$pdo = db();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function current_user_id_safe(): int {
  return function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
}
function csrf_verify_local(?string $token): bool {
  if (function_exists('csrf_verify')) {
    try { $r = csrf_verify($token); return ($r === null) ? true : (bool)$r; } catch (Throwable $e) {}
  }
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  return isset($_SESSION['csrf_token']) && is_string($token) && hash_equals((string)$_SESSION['csrf_token'], $token);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo "Method Not Allowed"; exit; }
if (!csrf_verify_local((string)($_POST['csrf_token'] ?? ''))) { http_response_code(400); echo "CSRF token invalid"; exit; }

$storeId    = (int)($_POST['store_id'] ?? 0);
$customerId = (int)($_POST['customer_id'] ?? 0);

$birthday   = trim((string)($_POST['birthday'] ?? ''));        // YYYY-MM-DD
$ageNote    = trim((string)($_POST['age_note'] ?? ''));
$area       = trim((string)($_POST['residence_area'] ?? ''));
$job        = trim((string)($_POST['job_title'] ?? ''));
$alcohol    = trim((string)($_POST['alcohol_pref'] ?? ''));
$tobacco    = (string)($_POST['tobacco'] ?? 'unknown');
$lineOptIn  = (int)($_POST['line_opt_in'] ?? 0) ? 1 : 0;

if ($storeId <= 0 || $customerId <= 0) { http_response_code(400); echo "invalid params"; exit; }

$allowedTobacco = ['unknown','no','yes','iqos'];
if (!in_array($tobacco, $allowedTobacco, true)) $tobacco = 'unknown';

$birthdayVal = null;
if ($birthday !== '') {
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday)) $birthdayVal = $birthday;
}

$me = current_user_id_safe();

/* merged */
$st = $pdo->prepare("SELECT id, merged_into_customer_id FROM customers WHERE store_id=:s AND id=:c LIMIT 1");
$st->execute([':s'=>$storeId, ':c'=>$customerId]);
$c = $st->fetch(PDO::FETCH_ASSOC);
if (!$c) { http_response_code(404); echo "customer not found"; exit; }
if (!empty($c['merged_into_customer_id'])) $customerId = (int)$c['merged_into_customer_id'];

$st = $pdo->prepare("
  INSERT INTO customer_profiles
    (customer_id, store_id, birthday, age_note, residence_area, job_title, alcohol_pref, tobacco, line_opt_in, updated_by_user_id, updated_at)
  VALUES
    (:cid, :sid, :birthday, :age_note, :area, :job, :alcohol, :tobacco, :optin, :me, NOW())
  ON DUPLICATE KEY UPDATE
    birthday=VALUES(birthday),
    age_note=VALUES(age_note),
    residence_area=VALUES(residence_area),
    job_title=VALUES(job_title),
    alcohol_pref=VALUES(alcohol_pref),
    tobacco=VALUES(tobacco),
    line_opt_in=VALUES(line_opt_in),
    updated_by_user_id=VALUES(updated_by_user_id),
    updated_at=NOW()
");
$st->execute([
  ':cid'=>$customerId,
  ':sid'=>$storeId,
  ':birthday'=>$birthdayVal,
  ':age_note'=>($ageNote===''?null:$ageNote),
  ':area'=>($area===''?null:$area),
  ':job'=>($job===''?null:$job),
  ':alcohol'=>($alcohol===''?null:$alcohol),
  ':tobacco'=>$tobacco,
  ':optin'=>$lineOptIn,
  ':me'=>$me,
]);

header("Location: /seika-app/public/customer/detail.php?store_id={$storeId}&id={$customerId}");
exit;