<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/store_decommission.php';

store_decommission_require_manage_role();

$pdo = db();

function store_decommission_api_out(array $data, int $code = 200): void
{
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function store_decommission_api_input(): array
{
  $raw = file_get_contents('php://input');
  if (!is_string($raw) || trim($raw) === '') {
    return $_POST;
  }

  $decoded = json_decode($raw, true);
  if (is_array($decoded)) {
    return $decoded;
  }

  return $_POST;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
$input = ($method === 'POST') ? store_decommission_api_input() : $_GET;
$actorUserId = (int)(current_user_id() ?? 0);

try {
  if ($method === 'POST') {
    csrf_verify((string)($input['csrf_token'] ?? $_POST['csrf_token'] ?? ''));
  }

  if ($action === 'status' && $method === 'GET') {
    $storeId = store_decommission_resolve_store_id($pdo, (int)($input['store_id'] ?? 0));
    $payload = store_decommission_status($pdo, $storeId);
    store_decommission_api_out(['ok' => true] + $payload);
  }

  if ($action === 'preview' && $method === 'GET') {
    $storeId = store_decommission_resolve_store_id($pdo, (int)($input['store_id'] ?? 0));
    store_decommission_api_out([
      'ok' => true,
      'store_id' => $storeId,
      'summary' => store_decommission_preview($pdo, $storeId),
    ]);
  }

  if ($action === 'logs' && $method === 'GET') {
    $jobId = (int)($input['job_id'] ?? 0);
    $job = store_decommission_fetch_job($pdo, $jobId);
    if (!$job) {
      throw new RuntimeException('ジョブが見つかりません');
    }
    store_decommission_resolve_store_id($pdo, (int)$job['store_id']);
    store_decommission_api_out([
      'ok' => true,
      'job_id' => $jobId,
      'logs' => store_decommission_fetch_logs($pdo, $jobId),
    ]);
  }

  if ($action === 'suspend' && $method === 'POST') {
    $storeId = store_decommission_resolve_store_id($pdo, (int)($input['store_id'] ?? 0));
    $result = store_decommission_suspend(
      $pdo,
      $storeId,
      $actorUserId,
      (string)($input['password'] ?? ''),
      (string)($input['confirm_text'] ?? ''),
      trim((string)($input['reason'] ?? ''))
    );
    store_decommission_api_out(['ok' => true, 'message' => '店舗を停止しました'] + $result);
  }

  if ($action === 'unsuspend' && $method === 'POST') {
    $storeId = store_decommission_resolve_store_id($pdo, (int)($input['store_id'] ?? 0));
    $result = store_decommission_unsuspend($pdo, $storeId, $actorUserId, (string)($input['password'] ?? ''));
    store_decommission_api_out(['ok' => true, 'message' => '停止を解除しました'] + $result);
  }

  if ($action === 'request' && $method === 'POST') {
    $storeId = store_decommission_resolve_store_id($pdo, (int)($input['store_id'] ?? 0));
    $result = store_decommission_request(
      $pdo,
      $storeId,
      $actorUserId,
      (string)($input['password'] ?? ''),
      (string)($input['confirm_text'] ?? ''),
      trim((string)($input['reason'] ?? '')),
      ($input['requested_schedule_at'] ?? '') !== '' ? (string)$input['requested_schedule_at'] : null
    );
    store_decommission_api_out(['ok' => true, 'message' => '廃棄申請を受け付けました'] + $result);
  }

  if ($action === 'approve' && $method === 'POST') {
    $result = store_decommission_approve(
      $pdo,
      (int)($input['job_id'] ?? 0),
      $actorUserId,
      (string)($input['password'] ?? ''),
      (bool)($input['approve'] ?? false),
      trim((string)($input['comment'] ?? ''))
    );
    store_decommission_api_out(['ok' => true, 'message' => '廃棄申請を承認しました'] + $result);
  }

  if ($action === 'schedule' && $method === 'POST') {
    $result = store_decommission_schedule(
      $pdo,
      (int)($input['job_id'] ?? 0),
      (string)($input['scheduled_at'] ?? ''),
      $actorUserId
    );
    store_decommission_api_out(['ok' => true] + $result);
  }

  if ($action === 'cancel' && $method === 'POST') {
    $result = store_decommission_cancel(
      $pdo,
      (int)($input['job_id'] ?? 0),
      $actorUserId,
      (string)($input['password'] ?? ''),
      (string)($input['confirm_text'] ?? ''),
      trim((string)($input['reason'] ?? ''))
    );
    store_decommission_api_out(['ok' => true, 'message' => '廃棄ジョブをキャンセルしました'] + $result);
  }

  if ($action === 'export' && $method === 'POST') {
    $result = store_decommission_export($pdo, (int)($input['job_id'] ?? 0), $actorUserId);
    store_decommission_api_out(['ok' => true, 'message' => 'エクスポートを作成しました'] + $result);
  }

  store_decommission_api_out(['ok' => false, 'reason_code' => 'not_found', 'message' => 'unknown action'], 404);
} catch (Throwable $e) {
  store_decommission_api_out([
    'ok' => false,
    'reason_code' => 'runtime_error',
    'message' => $e->getMessage(),
  ], 400);
}
