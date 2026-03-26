<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/transport_map.php';

require_login();
require_role(['manager', 'admin', 'super_user']);

header('Content-Type: application/json; charset=UTF-8');

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
  $filters = transport_map_filters_from_request($pdo, $_GET);
  $data = transport_map_fetch_data($pdo, $filters);

  echo json_encode([
    'ok' => true,
    'filters' => $data['filters'],
    'base' => $data['base'],
    'summary' => $data['summary'],
    'items' => $data['items'],
  ], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
  transport_map_api_error($e->getMessage(), 400);
} catch (Throwable $e) {
  transport_map_api_error('送迎マップデータの取得に失敗しました', 500);
}
