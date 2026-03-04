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

/* =========================
  helpers
========================= */
function has_role(string $role): bool {
  return isset($_SESSION['roles'])
    && is_array($_SESSION['roles'])
    && in_array($role, $_SESSION['roles'], true);
}

function current_user_id_safe(): int {
  return function_exists('current_user_id')
    ? (int)current_user_id()
    : (int)($_SESSION['user_id'] ?? 0);
}

function csrf_verify_local(?string $token): bool {
  if (function_exists('csrf_verify')) {
    try {
      $r = csrf_verify($token);
      return ($r === null) ? true : (bool)$r;
    } catch (Throwable $e) {}
  }

  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  return isset($_SESSION['csrf_token'])
    && is_string($token)
    && hash_equals((string)$_SESSION['csrf_token'], $token);
}

function can_use_staff_visibility(): bool {
  return has_role('admin')
      || has_role('manager')
      || has_role('super_user');
}

/* =========================
  guard
========================= */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo "Method Not Allowed";
  exit;
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!csrf_verify_local($token)) {
  http_response_code(400);
  echo "CSRF token invalid";
  exit;
}

/* =========================
  input
========================= */
$storeId     = (int)($_POST['store_id'] ?? 0);
$customerId  = (int)($_POST['customer_id'] ?? 0);
$noteText    = trim((string)($_POST['note_text'] ?? ''));
$noteType    = (string)($_POST['note_type'] ?? 'memo');
$visibility  = (string)($_POST['visibility'] ?? 'public');

if ($storeId <= 0 || $customerId <= 0 || $noteText === '') {
  http_response_code(400);
  echo "invalid params";
  exit;
}

$allowedType = ['memo','preference','ng','followup','incident','summary'];
if (!in_array($noteType, $allowedType, true)) {
  $noteType = 'memo';
}

$allowedVis = ['public','private','staff'];
if (!in_array($visibility, $allowedVis, true)) {
  $visibility = 'public';
}

if ($visibility === 'staff' && !can_use_staff_visibility()) {
  $visibility = 'public'; // castはstaff書けない
}

$me = current_user_id_safe();

/* =========================
  customer check
========================= */
$st = $pdo->prepare("
  SELECT id, merged_into_customer_id
  FROM customers
  WHERE store_id = :s
    AND id = :c
  LIMIT 1
");
$st->execute([
  ':s' => $storeId,
  ':c' => $customerId,
]);

$c = $st->fetch(PDO::FETCH_ASSOC);

if (!$c) {
  http_response_code(404);
  echo "customer not found";
  exit;
}

if (!empty($c['merged_into_customer_id'])) {
  $customerId = (int)$c['merged_into_customer_id'];
}

/* =========================
  insert
========================= */
$st = $pdo->prepare("
  INSERT INTO customer_notes
    (store_id, customer_id, author_user_id,
     visibility, note_type, note_text,
     created_at, updated_at)
  VALUES
    (:store_id, :customer_id, :author_user_id,
     :visibility, :note_type, :note_text,
     NOW(), NOW())
");

$st->execute([
  ':store_id'       => $storeId,
  ':customer_id'    => $customerId,
  ':author_user_id' => $me,
  ':visibility'     => $visibility,
  ':note_type'      => $noteType,
  ':note_text'      => $noteText,
]);

/* =========================
  redirect
========================= */
header("Location: /seika-app/public/customer/detail.php?store_id={$storeId}&id={$customerId}");
exit;