<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';

require_login();
require_role(['admin','super_user']);

$pdo = db();

$user_id = (int)($_POST['user_id'] ?? 0);
$active  = (int)($_POST['is_active'] ?? -1);

if ($user_id <= 0 || !in_array($active, [0,1], true)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'invalid']);
  exit;
}

$st = $pdo->prepare("
  UPDATE users
  SET is_active = ?, updated_at = NOW()
  WHERE id = ? AND role = 'cast'
");
$st->execute([$active, $user_id]);

echo json_encode(['ok'=>true]);