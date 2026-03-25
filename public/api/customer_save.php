<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/store_decommission.php';

require_login();
if (function_exists('require_role')) {
  require_role(['cast', 'admin', 'manager', 'super_user']);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

function flash_set_customer_save(string $message): void
{
  $_SESSION['_flash'] = $message;
}

function redirect_customer_detail(int $storeId, int $customerId): never
{
  header("Location: /wbss/public/customer/detail.php?store_id={$storeId}&id={$customerId}");
  exit;
}

function csrf_verify_customer_save(?string $token): void
{
  if (function_exists('csrf_verify')) {
    csrf_verify($token);
    return;
  }

  $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
  $ok = is_string($token) && $token !== '' && $sessionToken !== '' && hash_equals($sessionToken, $token);
  if (!$ok) {
    http_response_code(403);
    exit('CSRF blocked');
  }
}

function normalize_customer_save_datetime(string $raw): ?string
{
  $value = trim($raw);
  if ($value === '') {
    return null;
  }

  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
    return $value . ' 00:00:00';
  }

  if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
    return $value . ':00';
  }

  if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
    return $value;
  }

  return null;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  http_response_code(405);
  exit('Method Not Allowed');
}

csrf_verify_customer_save((string)($_POST['csrf_token'] ?? ''));

$storeId = (int)($_POST['store_id'] ?? 0);
$customerId = (int)($_POST['customer_id'] ?? 0);

if ($storeId <= 0 || $customerId <= 0) {
  http_response_code(400);
  exit('invalid params');
}
$pdo = db();
store_decommission_assert_store_writable($pdo, $storeId, '停止中の店舗では顧客更新できません');

$displayName = trim((string)($_POST['display_name'] ?? ''));
$features = trim((string)($_POST['features'] ?? ''));
$notePublic = trim((string)($_POST['note_public'] ?? ''));
$status = trim((string)($_POST['status'] ?? 'active'));
$assignedUserRaw = trim((string)($_POST['assigned_user_id'] ?? ''));
$lastVisitAtRaw = trim((string)($_POST['last_visit_at'] ?? ''));

if ($displayName === '') {
  flash_set_customer_save('名前は必須です');
  redirect_customer_detail($storeId, $customerId);
}

$allowedStatuses = ['active', 'inactive'];
if (!in_array($status, $allowedStatuses, true)) {
  $status = 'active';
}

$assignedUserId = null;
if ($assignedUserRaw !== '') {
  if (!ctype_digit($assignedUserRaw) || (int)$assignedUserRaw <= 0) {
    flash_set_customer_save('担当 user_id は数値で入力してください');
    redirect_customer_detail($storeId, $customerId);
  }
  $assignedUserId = (int)$assignedUserRaw;
}

$lastVisitAt = normalize_customer_save_datetime($lastVisitAtRaw);
if ($lastVisitAtRaw !== '' && $lastVisitAt === null) {
  flash_set_customer_save('最終来店は YYYY-MM-DD か YYYY-MM-DD HH:MM[:SS] で入力してください');
  redirect_customer_detail($storeId, $customerId);
}

$st = $pdo->prepare("
  SELECT id, merged_into_customer_id
  FROM customers
  WHERE store_id = ? AND id = ?
  LIMIT 1
");
$st->execute([$storeId, $customerId]);
$customer = $st->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
  http_response_code(404);
  exit('customer not found');
}

if (!empty($customer['merged_into_customer_id'])) {
  $customerId = (int)$customer['merged_into_customer_id'];
}

$update = $pdo->prepare("
  UPDATE customers
  SET
    display_name = ?,
    features = ?,
    note_public = ?,
    status = ?,
    assigned_user_id = ?,
    last_visit_at = ?,
    updated_at = NOW()
  WHERE store_id = ?
    AND id = ?
  LIMIT 1
");
$update->execute([
  $displayName,
  $features,
  $notePublic,
  $status,
  $assignedUserId,
  $lastVisitAt,
  $storeId,
  $customerId,
]);

flash_set_customer_save('基本情報を保存しました');
redirect_customer_detail($storeId, $customerId);
