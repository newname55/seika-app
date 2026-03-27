<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/auth.php';
require_once __DIR__ . '/../../../app/db.php';
require_once __DIR__ . '/../../../app/transport_map.php';
require_once __DIR__ . '/../../../app/transport/route_optimizer.php';

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
  $storeId = transport_resolve_store_id($pdo, (int)($_POST['store_id'] ?? 0));
  $requestIds = array_values(array_filter(array_map('intval', (array)($_POST['request_ids'] ?? [])), static fn(int $id): bool => $id > 0));
  if ($requestIds === []) {
    throw new RuntimeException('対象リクエストがありません');
  }
  $ph = implode(',', array_fill(0, count($requestIds), '?'));
  $st = $pdo->prepare("
    SELECT id, pickup_lat, pickup_lng
    FROM transport_assignments
    WHERE store_id = ?
      AND id IN ({$ph})
      AND pickup_lat IS NOT NULL
      AND pickup_lng IS NOT NULL
  ");
  $st->execute(array_merge([$storeId], $requestIds));
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $base = transport_map_fetch_base_context($pdo, $storeId);
  $optimized = transport_route_optimizer_nearest_neighbor($base, $rows);
  echo json_encode([
    'ok' => true,
    'items' => $optimized,
  ], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
  http_response_code(400);
  error_log('[transport_optimize_route_error] ' . $e->getMessage());
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  error_log('[transport_optimize_route_fatal] ' . $e->getMessage());
  echo json_encode(['ok' => false, 'error' => 'ルート最適化に失敗しました'], JSON_UNESCAPED_UNICODE);
}

