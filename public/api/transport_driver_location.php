<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/transport_vehicle_location.php';

require_login();
require_role(['staff', 'manager', 'admin', 'super_user']);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function transport_driver_location_api_error(string $message, int $statusCode = 400): never {
  http_response_code($statusCode);
  echo json_encode([
    'ok' => false,
    'error' => $message,
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $pdo = db();
  $userId = (int)(current_user_id() ?? 0);
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $saved = transport_vehicle_save_position($pdo, $_POST, $userId);
    echo json_encode([
      'ok' => true,
      'saved' => $saved,
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $storeId = transport_vehicle_resolve_store_id($pdo, $userId, (int)($_GET['store_id'] ?? 0));
  $vehicles = transport_vehicle_fetch_latest($pdo, $storeId);
  $mine = null;
  foreach ($vehicles as $vehicle) {
    if ((int)($vehicle['driver_user_id'] ?? 0) === $userId) {
      $mine = $vehicle;
      break;
    }
  }

  echo json_encode([
    'ok' => true,
    'store_id' => $storeId,
    'vehicle' => $mine,
  ], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
  transport_driver_location_api_error($e->getMessage(), 400);
} catch (Throwable $e) {
  transport_driver_location_api_error('位置情報APIの処理に失敗しました', 500);
}
