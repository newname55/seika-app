<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/store_access.php';

require_login();
require_role(['admin', 'manager', 'super_user']);

$pdo = db();
try {
  $storeId = store_access_resolve_manageable_store_id($pdo, (int)($_GET['store_id'] ?? 0));
  header('Location: /wbss/public/store_casts_invite_qr.php?store_id=' . $storeId, true, 302);
  exit;
} catch (Throwable $e) {
  http_response_code(400);
  echo h($e->getMessage());
}
