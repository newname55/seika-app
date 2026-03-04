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

function has_role(string $role): bool {
  return isset($_SESSION['roles']) && is_array($_SESSION['roles']) && in_array($role, $_SESSION['roles'], true);
}
function can_staff_scope(): bool {
  return has_role('admin') || has_role('manager') || has_role('super_user');
}
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
$dateKind   = (string)($_POST['date_kind'] ?? 'other');
$theDate    = trim((string)($_POST['the_date'] ?? ''));
$label      = trim((string)($_POST['label'] ?? ''));
$visibility = (string)($_POST['visibility'] ?? 'staff');

if ($storeId <= 0 || $customerId <= 0) { http_response_code(400); echo "invalid params"; exit; }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $theDate)) { http_response_code(400); echo "invalid date"; exit; }

$allowedKind = ['anniversary','wedding','start','other'];
if (!in_array($dateKind, $allowedKind, true)) $dateKind = 'other';

$allowedVis = ['public','staff','private'];
if (!in_array($visibility, $allowedVis, true)) $visibility = 'staff';
if ($visibility === 'staff' && !can_staff_scope()) $visibility = 'public';

$me = current_user_id_safe();

/* merged */
$st = $pdo->prepare("SELECT id, merged_into_customer_id FROM customers WHERE store_id=:s AND id=:c LIMIT 1");
$st->execute([':s'=>$storeId, ':c'=>$customerId]);
$c = $st->fetch(PDO::FETCH_ASSOC);
if (!$c) { http_response_code(404); echo "customer not found"; exit; }
if (!empty($c['merged_into_customer_id'])) $customerId = (int)$c['merged_into_customer_id'];

$st = $pdo->prepare("
  INSERT INTO customer_dates
    (store_id, customer_id, date_kind, the_date, label, visibility, created_by_user_id, created_at)
  VALUES
    (:sid, :cid, :k, :d, :label, :vis, :me, NOW())
");
$st->execute([
  ':sid'=>$storeId,
  ':cid'=>$customerId,
  ':k'=>$dateKind,
  ':d'=>$theDate,
  ':label'=>($label===''?null:$label),
  ':vis'=>$visibility,
  ':me'=>$me,
]);

header("Location: /seika-app/public/customer/detail.php?store_id={$storeId}&id={$customerId}");
exit;