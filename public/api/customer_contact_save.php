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
$kind       = (string)($_POST['kind'] ?? 'phone');
$value      = trim((string)($_POST['value'] ?? ''));
$label      = trim((string)($_POST['label'] ?? ''));
$isPrimary  = (int)($_POST['is_primary'] ?? 0) ? 1 : 0;
$visibility = (string)($_POST['visibility'] ?? 'staff');

if ($storeId <= 0 || $customerId <= 0 || $value === '') { http_response_code(400); echo "invalid params"; exit; }

$allowedKind = ['phone','line','email','other'];
if (!in_array($kind, $allowedKind, true)) $kind = 'phone';

$allowedVis = ['public','staff','private'];
if (!in_array($visibility, $allowedVis, true)) $visibility = 'staff';
if ($visibility === 'staff' && !can_staff_scope()) $visibility = 'public'; // castはstaff固定不可にしたければここで調整
if ($visibility === 'private' && can_staff_scope() === false) {
  // castでもprivateはOK（自分だけの連絡先メモ運用があり得る）
}

$me = current_user_id_safe();

/* customer exists + merged */
$st = $pdo->prepare("SELECT id, merged_into_customer_id FROM customers WHERE store_id=:s AND id=:c LIMIT 1");
$st->execute([':s'=>$storeId, ':c'=>$customerId]);
$c = $st->fetch(PDO::FETCH_ASSOC);
if (!$c) { http_response_code(404); echo "customer not found"; exit; }
if (!empty($c['merged_into_customer_id'])) $customerId = (int)$c['merged_into_customer_id'];

/* primary handling: 同一kindでprimaryを1つに */
if ($isPrimary) {
  $st = $pdo->prepare("
    UPDATE customer_contacts
      SET is_primary=0
    WHERE store_id=:s AND customer_id=:cid AND kind=:k
  ");
  $st->execute([':s'=>$storeId, ':cid'=>$customerId, ':k'=>$kind]);
}

/* upsert by unique(store_id,kind,value) */
$st = $pdo->prepare("
  INSERT INTO customer_contacts
    (store_id, customer_id, kind, value, label, is_primary, visibility, created_by_user_id, created_at, updated_at)
  VALUES
    (:store_id, :customer_id, :kind, :value, :label, :is_primary, :visibility, :me, NOW(), NOW())
  ON DUPLICATE KEY UPDATE
    customer_id=VALUES(customer_id),
    label=VALUES(label),
    is_primary=VALUES(is_primary),
    visibility=VALUES(visibility),
    updated_at=NOW()
");
$st->execute([
  ':store_id'=>$storeId,
  ':customer_id'=>$customerId,
  ':kind'=>$kind,
  ':value'=>$value,
  ':label'=>($label===''?null:$label),
  ':is_primary'=>$isPrimary,
  ':visibility'=>$visibility,
  ':me'=>$me,
]);

header("Location: /seika-app/public/customer/detail.php?store_id={$storeId}&id={$customerId}");
exit;