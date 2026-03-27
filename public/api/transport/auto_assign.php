<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/auth.php';
require_once __DIR__ . '/../../../app/db.php';
require_once __DIR__ . '/../../../app/transport_map.php';
require_once __DIR__ . '/../../../app/transport/assign_service.php';

require_login();
require_role(['manager', 'admin', 'super_user', ROLE_ALL_STORE_SHIFT_VIEW]);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    throw new RuntimeException('POSTのみ利用できます');
  }
  csrf_verify($_POST['csrf_token'] ?? null);
  $pdo = db();
  $filters = transport_map_filters_from_request($pdo, $_POST);
  $proposals = transport_assign_service_generate($pdo, $filters);
  echo json_encode([
    'ok' => true,
    'filters' => $filters,
    'items' => $proposals,
  ], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
  http_response_code(400);
  error_log('[transport_auto_assign_error] ' . $e->getMessage());
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  error_log('[transport_auto_assign_fatal] ' . $e->getMessage());
  echo json_encode(['ok' => false, 'error' => '自動提案の生成に失敗しました'], JSON_UNESCAPED_UNICODE);
}

