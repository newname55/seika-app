<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/transport_map.php';

require_login();
require_role(['manager', 'admin', 'super_user', ROLE_ALL_STORE_SHIFT_VIEW]);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function transport_map_api_error(string $message, int $statusCode = 400, ?array $details = null): never {
  http_response_code($statusCode);
  $payload = [
    'ok' => false,
    'error' => $message,
  ];
  if ($details !== null) {
    $payload['details'] = $details;
  }
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $pdo = db();
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $action = trim((string)($_POST['action'] ?? ''));
    if (!in_array($action, ['save_assignment', 'bulk_unassign'], true)) {
      transport_map_api_error('不正な操作です', 400);
    }

    if ($action === 'save_assignment') {
      error_log('[transport_map_api_save_assignment] raw=' . json_encode([
        'store_id' => $_POST['store_id'] ?? null,
        'business_date' => $_POST['business_date'] ?? null,
        'cast_id' => $_POST['cast_id'] ?? null,
        'driver_user_id' => $_POST['driver_user_id'] ?? null,
        'status' => $_POST['status'] ?? null,
        'sort_order' => $_POST['sort_order'] ?? null,
      ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

      $item = transport_map_save_assignment($pdo, $_POST, (int)(current_user_id() ?? 0));
      echo json_encode([
        'ok' => true,
        'message' => '送迎割当を保存しました',
        'item' => $item,
      ], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $result = transport_map_bulk_unassign($pdo, $_POST, (int)(current_user_id() ?? 0));
    echo json_encode([
      'ok' => true,
      'message' => '送迎割当を未割当に戻しました',
      'updated' => (int)($result['updated'] ?? 0),
      'store_id' => (int)($result['store_id'] ?? 0),
      'business_date' => (string)($result['business_date'] ?? ''),
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $filters = transport_map_filters_from_request($pdo, $_GET);
  $data = transport_map_fetch_data($pdo, $filters);

  echo json_encode([
    'ok' => true,
    'filters' => $data['filters'],
    'base' => $data['base'],
    'bases' => $data['bases'],
    'summary' => $data['summary'],
    'items' => $data['items'],
    'vehicles' => $data['vehicles'],
  ], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
  transport_map_api_error($e->getMessage(), 400);
} catch (Throwable $e) {
  transport_map_api_error('送迎マップデータの取得に失敗しました', 500);
}
