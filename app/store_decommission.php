<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/store_access.php';

function store_decommission_json_encode(array $value): string
{
  return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function store_decommission_table_exists(PDO $pdo, string $table): bool
{
  $st = $pdo->prepare("
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
  ");
  $st->execute([$table]);
  return ((int)$st->fetchColumn() > 0);
}

function store_decommission_column_exists(PDO $pdo, string $table, string $column): bool
{
  $st = $pdo->prepare("
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
      AND COLUMN_NAME = ?
  ");
  $st->execute([$table, $column]);
  return ((int)$st->fetchColumn() > 0);
}

function store_decommission_now(): string
{
  return (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
}

function store_decommission_allowed_role_codes(): array
{
  return ['super_user', 'admin', 'manager', 'owner', 'system_admin', 'hq_admin'];
}

function store_decommission_user_can_manage(): bool
{
  foreach (store_decommission_allowed_role_codes() as $roleCode) {
    if (is_role($roleCode)) {
      return true;
    }
  }
  return false;
}

function store_decommission_require_manage_role(): void
{
  require_login();
  if (!store_decommission_user_can_manage()) {
    http_response_code(403);
    exit('Forbidden');
  }
}

function store_decommission_manageable_stores(PDO $pdo): array
{
  return store_access_allowed_stores($pdo);
}

function store_decommission_resolve_store_id(PDO $pdo, ?int $requestedStoreId = null): int
{
  return store_access_resolve_manageable_store_id($pdo, $requestedStoreId);
}

function store_decommission_fetch_store(PDO $pdo, int $storeId): array
{
  $hasCode = store_decommission_column_exists($pdo, 'stores', 'code');
  $hasIsActive = store_decommission_column_exists($pdo, 'stores', 'is_active');
  $hasStatus = store_decommission_column_exists($pdo, 'stores', 'status');
  $hasLifecycle = store_decommission_column_exists($pdo, 'stores', 'lifecycle_status');
  $hasLastLogin = store_decommission_column_exists($pdo, 'stores', 'last_login_at');
  $hasUpdatedAt = store_decommission_column_exists($pdo, 'stores', 'updated_at');
  $hasRequestedAt = store_decommission_column_exists($pdo, 'stores', 'decommission_requested_at');
  $hasApprovedAt = store_decommission_column_exists($pdo, 'stores', 'decommission_approved_at');
  $hasScheduledAt = store_decommission_column_exists($pdo, 'stores', 'decommission_scheduled_at');
  $hasCompletedAt = store_decommission_column_exists($pdo, 'stores', 'decommission_completed_at');

  $sql = "
    SELECT
      id,
      " . ($hasCode ? "code" : "'' AS code") . ",
      name,
      " . ($hasIsActive ? "is_active" : "1 AS is_active") . ",
      " . ($hasStatus ? "status" : "'' AS status") . ",
      " . ($hasLifecycle ? "lifecycle_status" : "'active' AS lifecycle_status") . ",
      " . ($hasLastLogin ? "last_login_at" : "NULL AS last_login_at") . ",
      " . ($hasUpdatedAt ? "updated_at" : "NULL AS updated_at") . ",
      " . ($hasRequestedAt ? "decommission_requested_at" : "NULL AS decommission_requested_at") . ",
      " . ($hasApprovedAt ? "decommission_approved_at" : "NULL AS decommission_approved_at") . ",
      " . ($hasScheduledAt ? "decommission_scheduled_at" : "NULL AS decommission_scheduled_at") . ",
      " . ($hasCompletedAt ? "decommission_completed_at" : "NULL AS decommission_completed_at") . "
    FROM stores
    WHERE id = ?
    LIMIT 1
  ";

  $st = $pdo->prepare($sql);
  $st->execute([$storeId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!is_array($row)) {
    throw new RuntimeException('店舗が見つかりません');
  }
  return $row;
}

function store_decommission_verify_password(PDO $pdo, int $userId, string $password): bool
{
  $password = (string)$password;
  if ($userId <= 0 || $password === '') {
    return false;
  }

  $st = $pdo->prepare("SELECT password_hash FROM users WHERE id=? LIMIT 1");
  $st->execute([$userId]);
  $hash = (string)($st->fetchColumn() ?: '');
  if ($hash === '') {
    return false;
  }

  if (str_starts_with($hash, 'sha2:')) {
    return hash_equals(substr($hash, 5), hash('sha256', $password));
  }

  return password_verify($password, $hash);
}

function store_decommission_expected_confirm_text(string $action, int $storeId): string
{
  $action = strtolower(trim($action));
  return match ($action) {
    'suspend' => 'SUSPEND STORE ' . $storeId,
    'request' => 'DELETE STORE ' . $storeId,
    'approve' => 'APPROVE STORE ' . $storeId,
    'cancel' => 'CANCEL STORE ' . $storeId,
    default => 'STORE ' . $storeId,
  };
}

function store_decommission_assert_password_and_confirm(
  PDO $pdo,
  int $userId,
  int $storeId,
  string $password,
  string $confirmText,
  string $action
): void {
  if (!store_decommission_verify_password($pdo, $userId, $password)) {
    throw new RuntimeException('パスワード再入力が一致しません');
  }

  $expected = store_decommission_expected_confirm_text($action, $storeId);
  if (trim($confirmText) !== $expected) {
    throw new RuntimeException('確認文字列が一致しません');
  }
}

function store_decommission_log_step(
  PDO $pdo,
  int $jobId,
  int $storeId,
  string $stepKey,
  string $stepLabel,
  string $status,
  ?string $message = null,
  array $context = [],
  ?int $createdBy = null
): void {
  if (!store_decommission_table_exists($pdo, 'store_decommission_logs')) {
    return;
  }

  $st = $pdo->prepare("
    INSERT INTO store_decommission_logs (
      job_id,
      store_id,
      step_key,
      step_label,
      status,
      message,
      context_json,
      created_by,
      created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
  ");
  $st->execute([
    $jobId,
    $storeId,
    $stepKey,
    $stepLabel,
    $status,
    $message,
    $context ? store_decommission_json_encode($context) : null,
    $createdBy,
  ]);
}

function store_decommission_discover_store_tables(PDO $pdo): array
{
  $st = $pdo->query("
    SELECT TABLE_NAME
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND COLUMN_NAME = 'store_id'
    ORDER BY TABLE_NAME ASC
  ");
  $tables = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];

  $excluded = [
    'stores' => true,
    'store_decommission_jobs' => true,
    'store_decommission_logs' => true,
    'store_decommission_snapshots' => true,
  ];

  $priority = [
    'order_item_cast_assignments',
    'order_items',
    'orders',
    'ticket_items',
    'ticket_payments',
    'ticket_receipt_jobs',
    'ticket_headers',
    'tickets',
    'visit_nomination_events',
    'attendance_audits',
    'attendances',
    'cast_shift_requests',
    'cast_shift_plans',
    'customer_cast_links',
    'customer_notes',
    'customers',
    'wbss_applicant_photos',
    'wbss_applicant_interviews',
    'applicants',
    'interviews',
    'points',
    'point_histories',
    'line_geo_pending',
    'store_event_audit_logs',
    'store_events',
    'store_users',
    'user_roles',
    'store_transport_bases',
    'store_settings',
  ];

  $ordered = [];
  foreach ($priority as $table) {
    if (in_array($table, $tables, true) && !isset($excluded[$table])) {
      $ordered[] = ['table' => $table, 'mode' => 'delete_by_store_id'];
    }
  }
  foreach ($tables as $table) {
    if (isset($excluded[$table])) {
      continue;
    }
    if (in_array($table, $priority, true)) {
      continue;
    }
    $ordered[] = ['table' => $table, 'mode' => 'delete_by_store_id'];
  }

  return $ordered;
}

function store_decommission_preview_definitions(): array
{
  return [
    'customers' => [
      ['table' => 'customers', 'aggregate' => 'COUNT(*)'],
    ],
    'tickets' => [
      ['table' => 'tickets', 'aggregate' => 'COUNT(*)'],
    ],
    'orders' => [
      ['table' => 'orders', 'aggregate' => 'COUNT(*)'],
    ],
    'attendances' => [
      ['table' => 'attendances', 'aggregate' => 'COUNT(*)'],
    ],
    'nominations' => [
      ['table' => 'visit_nomination_events', 'aggregate' => 'COUNT(*)'],
    ],
    'interviews' => [
      ['table' => 'wbss_applicant_interviews', 'aggregate' => 'COUNT(*)'],
      ['table' => 'interviews', 'aggregate' => 'COUNT(*)'],
    ],
    'attachments' => [
      ['table' => 'wbss_applicant_photos', 'aggregate' => 'COUNT(*)'],
    ],
    'attachments_bytes' => [
      ['table' => 'wbss_applicant_photos', 'aggregate' => 'COALESCE(SUM(file_size),0)'],
    ],
  ];
}

function store_decommission_preview(PDO $pdo, int $storeId): array
{
  $summary = [
    'customers' => 0,
    'tickets' => 0,
    'orders' => 0,
    'attendances' => 0,
    'nominations' => 0,
    'interviews' => 0,
    'attachments' => 0,
    'attachments_bytes' => 0,
  ];

  foreach (store_decommission_preview_definitions() as $key => $candidates) {
    foreach ($candidates as $candidate) {
      $table = (string)$candidate['table'];
      if (!store_decommission_table_exists($pdo, $table) || !store_decommission_column_exists($pdo, $table, 'store_id')) {
        continue;
      }

      $sql = "SELECT " . $candidate['aggregate'] . " AS value FROM `{$table}` WHERE store_id = ?";
      $st = $pdo->prepare($sql);
      $st->execute([$storeId]);
      $summary[$key] = (int)($st->fetchColumn() ?: 0);
      break;
    }
  }

  return $summary;
}

function store_decommission_create_snapshot(PDO $pdo, int $jobId, int $storeId, array $summary): void
{
  if (!store_decommission_table_exists($pdo, 'store_decommission_snapshots')) {
    return;
  }

  $pdo->prepare("DELETE FROM store_decommission_snapshots WHERE job_id=?")->execute([$jobId]);

  $st = $pdo->prepare("
    INSERT INTO store_decommission_snapshots (
      job_id,
      store_id,
      customers_count,
      tickets_count,
      orders_count,
      attendances_count,
      nominations_count,
      interviews_count,
      attachments_count,
      attachments_bytes,
      snapshot_json,
      created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
  ");
  $st->execute([
    $jobId,
    $storeId,
    (int)($summary['customers'] ?? 0),
    (int)($summary['tickets'] ?? 0),
    (int)($summary['orders'] ?? 0),
    (int)($summary['attendances'] ?? 0),
    (int)($summary['nominations'] ?? 0),
    (int)($summary['interviews'] ?? 0),
    (int)($summary['attachments'] ?? 0),
    (int)($summary['attachments_bytes'] ?? 0),
    store_decommission_json_encode($summary),
  ]);
}

function store_decommission_fetch_latest_job(PDO $pdo, int $storeId): ?array
{
  if (!store_decommission_table_exists($pdo, 'store_decommission_jobs')) {
    return null;
  }

  $st = $pdo->prepare("
    SELECT *
    FROM store_decommission_jobs
    WHERE store_id = ?
    ORDER BY id DESC
    LIMIT 1
  ");
  $st->execute([$storeId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return is_array($row) ? $row : null;
}

function store_decommission_fetch_logs(PDO $pdo, int $jobId): array
{
  if ($jobId <= 0 || !store_decommission_table_exists($pdo, 'store_decommission_logs')) {
    return [];
  }

  $st = $pdo->prepare("
    SELECT *
    FROM store_decommission_logs
    WHERE job_id = ?
    ORDER BY id DESC
    LIMIT 200
  ");
  $st->execute([$jobId]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function store_decommission_status(PDO $pdo, int $storeId): array
{
  $store = store_decommission_fetch_store($pdo, $storeId);
  $job = store_decommission_fetch_latest_job($pdo, $storeId);
  $summary = store_decommission_preview($pdo, $storeId);
  $logs = $job ? store_decommission_fetch_logs($pdo, (int)$job['id']) : [];

  return [
    'store' => $store,
    'job' => $job,
    'summary' => $summary,
    'logs' => $logs,
  ];
}

function store_decommission_update_store_lifecycle(PDO $pdo, int $storeId, string $status, array $timestamps = []): void
{
  $sets = ['lifecycle_status = :lifecycle_status'];
  $params = [':lifecycle_status' => $status, ':id' => $storeId];

  foreach ([
    'decommission_requested_at',
    'decommission_approved_at',
    'decommission_scheduled_at',
    'decommission_completed_at',
  ] as $column) {
    if (array_key_exists($column, $timestamps)) {
      $sets[] = "{$column} = :{$column}";
      $params[":{$column}"] = $timestamps[$column];
    }
  }

  $sql = "UPDATE stores SET " . implode(', ', $sets) . " WHERE id = :id LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute($params);
}

function store_decommission_suspend(PDO $pdo, int $storeId, int $actorUserId, string $password, string $confirmText, string $reason): array
{
  store_decommission_assert_password_and_confirm($pdo, $actorUserId, $storeId, $password, $confirmText, 'suspend');

  $store = store_decommission_fetch_store($pdo, $storeId);
  if (($store['lifecycle_status'] ?? 'active') === 'decommissioned') {
    throw new RuntimeException('解約済み店舗は停止変更できません');
  }

  store_decommission_update_store_lifecycle($pdo, $storeId, 'suspended');

  $job = store_decommission_fetch_latest_job($pdo, $storeId);
  if ($job) {
    store_decommission_log_step($pdo, (int)$job['id'], $storeId, 'suspend', '店舗停止', 'completed', $reason, [], $actorUserId);
  }

  return [
    'store_id' => $storeId,
    'lifecycle_status' => 'suspended',
  ];
}

function store_decommission_unsuspend(PDO $pdo, int $storeId, int $actorUserId, string $password): array
{
  if (!store_decommission_verify_password($pdo, $actorUserId, $password)) {
    throw new RuntimeException('パスワード再入力が一致しません');
  }

  $store = store_decommission_fetch_store($pdo, $storeId);
  $status = (string)($store['lifecycle_status'] ?? 'active');
  if (!in_array($status, ['suspended', 'decommissioning'], true)) {
    throw new RuntimeException('停止解除できる状態ではありません');
  }

  store_decommission_update_store_lifecycle($pdo, $storeId, 'active', [
    'decommission_requested_at' => null,
    'decommission_approved_at' => null,
    'decommission_scheduled_at' => null,
  ]);

  return [
    'store_id' => $storeId,
    'lifecycle_status' => 'active',
  ];
}

function store_decommission_request(
  PDO $pdo,
  int $storeId,
  int $actorUserId,
  string $password,
  string $confirmText,
  string $reason,
  ?string $requestedScheduleAt = null
): array {
  store_decommission_assert_password_and_confirm($pdo, $actorUserId, $storeId, $password, $confirmText, 'request');

  $store = store_decommission_fetch_store($pdo, $storeId);
  $lifecycle = (string)($store['lifecycle_status'] ?? 'active');
  if (!in_array($lifecycle, ['suspended', 'decommissioning'], true)) {
    throw new RuntimeException('まず店舗を停止してください');
  }
  $latestJob = store_decommission_fetch_latest_job($pdo, $storeId);
  if ($latestJob && in_array((string)$latestJob['status'], ['requested', 'approved', 'scheduled', 'running'], true)) {
    throw new RuntimeException('未完了の解約ジョブがすでに存在します');
  }

  $summary = store_decommission_preview($pdo, $storeId);
  $confirmToken = bin2hex(random_bytes(32));
  $requestedAt = store_decommission_now();

  $st = $pdo->prepare("
    INSERT INTO store_decommission_jobs (
      store_id,
      requested_by,
      status,
      reason,
      confirm_token,
      requested_at,
      scheduled_at,
      requested_ip,
      updated_at
    ) VALUES (?, ?, 'requested', ?, ?, NOW(), ?, ?, NOW())
  ");
  $st->execute([
    $storeId,
    $actorUserId,
    $reason !== '' ? $reason : null,
    $confirmToken,
    $requestedScheduleAt,
    $_SERVER['REMOTE_ADDR'] ?? null,
  ]);

  $jobId = (int)$pdo->lastInsertId();
  store_decommission_create_snapshot($pdo, $jobId, $storeId, $summary);
  store_decommission_update_store_lifecycle($pdo, $storeId, 'decommissioning', [
    'decommission_requested_at' => $requestedAt,
    'decommission_scheduled_at' => $requestedScheduleAt,
  ]);
  store_decommission_log_step($pdo, $jobId, $storeId, 'request', '廃棄申請', 'completed', $reason, [
    'requested_schedule_at' => $requestedScheduleAt,
    'summary' => $summary,
  ], $actorUserId);

  return [
    'job_id' => $jobId,
    'status' => 'requested',
    'confirm_token' => substr($confirmToken, 0, 8) . '...',
  ];
}

function store_decommission_approve(PDO $pdo, int $jobId, int $actorUserId, string $password, bool $approve, string $comment): array
{
  $job = store_decommission_fetch_job($pdo, $jobId);
  if (!$job) {
    throw new RuntimeException('ジョブが見つかりません');
  }
  if (!store_decommission_verify_password($pdo, $actorUserId, $password)) {
    throw new RuntimeException('パスワード再入力が一致しません');
  }
  if (!$approve) {
    throw new RuntimeException('approve=true が必要です');
  }
  if (!in_array((string)$job['status'], ['requested', 'approved'], true)) {
    throw new RuntimeException('承認できる状態ではありません');
  }

  $approvedAt = store_decommission_now();
  $st = $pdo->prepare("
    UPDATE store_decommission_jobs
    SET approved_by = ?,
        status = 'approved',
        approved_at = NOW(),
        approved_ip = ?,
        updated_at = NOW()
    WHERE id = ?
    LIMIT 1
  ");
  $st->execute([$actorUserId, $_SERVER['REMOTE_ADDR'] ?? null, $jobId]);

  store_decommission_update_store_lifecycle($pdo, (int)$job['store_id'], 'decommissioning', [
    'decommission_approved_at' => $approvedAt,
  ]);
  store_decommission_log_step($pdo, $jobId, (int)$job['store_id'], 'approve', '廃棄承認', 'completed', $comment, [], $actorUserId);

  return ['job_id' => $jobId, 'status' => 'approved'];
}

function store_decommission_schedule(PDO $pdo, int $jobId, string $scheduledAt, int $actorUserId): array
{
  $job = store_decommission_fetch_job($pdo, $jobId);
  if (!$job) {
    throw new RuntimeException('ジョブが見つかりません');
  }
  if (!in_array((string)$job['status'], ['requested', 'approved', 'scheduled'], true)) {
    throw new RuntimeException('スケジュール設定できる状態ではありません');
  }
  if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $scheduledAt)) {
    throw new RuntimeException('scheduled_at は YYYY-MM-DD HH:MM:SS 形式で入力してください');
  }

  $st = $pdo->prepare("
    UPDATE store_decommission_jobs
    SET status = 'scheduled',
        scheduled_at = ?,
        updated_at = NOW()
    WHERE id = ?
    LIMIT 1
  ");
  $st->execute([$scheduledAt, $jobId]);

  store_decommission_update_store_lifecycle($pdo, (int)$job['store_id'], 'decommissioning', [
    'decommission_scheduled_at' => $scheduledAt,
  ]);
  store_decommission_log_step($pdo, $jobId, (int)$job['store_id'], 'schedule', '廃棄予約', 'completed', null, [
    'scheduled_at' => $scheduledAt,
  ], $actorUserId);

  return ['job_id' => $jobId, 'status' => 'scheduled', 'scheduled_at' => $scheduledAt];
}

function store_decommission_cancel(PDO $pdo, int $jobId, int $actorUserId, string $password, string $confirmText, string $reason): array
{
  $job = store_decommission_fetch_job($pdo, $jobId);
  if (!$job) {
    throw new RuntimeException('ジョブが見つかりません');
  }

  store_decommission_assert_password_and_confirm($pdo, $actorUserId, (int)$job['store_id'], $password, $confirmText, 'cancel');

  if (in_array((string)$job['status'], ['running', 'completed', 'cancelled'], true)) {
    throw new RuntimeException('キャンセルできる状態ではありません');
  }

  $st = $pdo->prepare("
    UPDATE store_decommission_jobs
    SET status = 'cancelled',
        cancelled_at = NOW(),
        failure_reason = ?,
        updated_at = NOW()
    WHERE id = ?
    LIMIT 1
  ");
  $st->execute([$reason !== '' ? $reason : null, $jobId]);

  store_decommission_update_store_lifecycle($pdo, (int)$job['store_id'], 'suspended', [
    'decommission_scheduled_at' => null,
  ]);
  store_decommission_log_step($pdo, $jobId, (int)$job['store_id'], 'cancel', '廃棄キャンセル', 'completed', $reason, [], $actorUserId);

  return ['job_id' => $jobId, 'status' => 'cancelled'];
}

function store_decommission_fetch_job(PDO $pdo, int $jobId): ?array
{
  if ($jobId <= 0 || !store_decommission_table_exists($pdo, 'store_decommission_jobs')) {
    return null;
  }

  $st = $pdo->prepare("SELECT * FROM store_decommission_jobs WHERE id=? LIMIT 1");
  $st->execute([$jobId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return is_array($row) ? $row : null;
}

function store_decommission_export(PDO $pdo, int $jobId, int $actorUserId): array
{
  $job = store_decommission_fetch_job($pdo, $jobId);
  if (!$job) {
    throw new RuntimeException('ジョブが見つかりません');
  }

  $storeId = (int)$job['store_id'];
  $store = store_decommission_fetch_store($pdo, $storeId);
  $summary = store_decommission_preview($pdo, $storeId);

  $dir = dirname(__DIR__) . '/public/uploads/decommission_exports';
  if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
    throw new RuntimeException('エクスポート保存先を作成できません');
  }

  $fileName = sprintf('store_%d_job_%d_%s.json', $storeId, $jobId, date('Ymd_His'));
  $filePath = $dir . '/' . $fileName;
  $payload = [
    'store' => [
      'id' => $storeId,
      'name' => (string)($store['name'] ?? ''),
      'lifecycle_status' => (string)($store['lifecycle_status'] ?? ''),
    ],
    'job' => [
      'id' => $jobId,
      'status' => (string)($job['status'] ?? ''),
      'requested_at' => (string)($job['requested_at'] ?? ''),
      'scheduled_at' => (string)($job['scheduled_at'] ?? ''),
    ],
    'summary' => $summary,
    'exported_at' => store_decommission_now(),
  ];

  if (file_put_contents($filePath, store_decommission_json_encode($payload)) === false) {
    throw new RuntimeException('エクスポート書き込みに失敗しました');
  }

  $publicPath = '/wbss/public/uploads/decommission_exports/' . $fileName;
  $st = $pdo->prepare("
    UPDATE store_decommission_jobs
    SET export_path = ?, export_ready = 1, updated_at = NOW()
    WHERE id = ?
    LIMIT 1
  ");
  $st->execute([$publicPath, $jobId]);

  store_decommission_log_step($pdo, $jobId, $storeId, 'export', '最終エクスポート作成', 'completed', null, [
    'export_path' => $publicPath,
  ], $actorUserId);

  return ['job_id' => $jobId, 'export_path' => $publicPath, 'export_ready' => true];
}

function store_decommission_mark_job_running(PDO $pdo, int $jobId): void
{
  $pdo->prepare("
    UPDATE store_decommission_jobs
    SET status = 'running',
        started_at = NOW(),
        updated_at = NOW()
    WHERE id = ?
      AND status = 'scheduled'
    LIMIT 1
  ")->execute([$jobId]);
}

function store_decommission_mark_job_failed(PDO $pdo, int $jobId, string $reason): void
{
  $pdo->prepare("
    UPDATE store_decommission_jobs
    SET status = 'failed',
        failed_at = NOW(),
        failure_reason = ?,
        updated_at = NOW()
    WHERE id = ?
    LIMIT 1
  ")->execute([$reason, $jobId]);
}

function store_decommission_mark_job_completed(PDO $pdo, int $jobId, int $actorUserId): void
{
  $job = store_decommission_fetch_job($pdo, $jobId);
  if (!$job) {
    throw new RuntimeException('ジョブが見つかりません');
  }

  $pdo->prepare("
    UPDATE store_decommission_jobs
    SET status = 'completed',
        executed_by = ?,
        completed_at = NOW(),
        updated_at = NOW()
    WHERE id = ?
    LIMIT 1
  ")->execute([$actorUserId > 0 ? $actorUserId : null, $jobId]);

  store_decommission_update_store_lifecycle($pdo, (int)$job['store_id'], 'decommissioned', [
    'decommission_completed_at' => store_decommission_now(),
  ]);
}

function store_decommission_collect_due_jobs(PDO $pdo, int $limit = 10): array
{
  $limit = max(1, min(100, $limit));
  $st = $pdo->query("
    SELECT *
    FROM store_decommission_jobs
    WHERE status = 'scheduled'
      AND scheduled_at IS NOT NULL
      AND scheduled_at <= NOW()
    ORDER BY scheduled_at ASC, id ASC
    LIMIT {$limit}
  ");
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function store_decommission_execute_job(PDO $pdo, int $jobId, bool $dryRun = true, int $systemUserId = 0): array
{
  $job = store_decommission_fetch_job($pdo, $jobId);
  if (!$job) {
    throw new RuntimeException('ジョブが見つかりません');
  }

  $storeId = (int)$job['store_id'];
  $plan = store_decommission_discover_store_tables($pdo);
  $result = ['deleted' => [], 'skipped' => []];

  store_decommission_mark_job_running($pdo, $jobId);
  store_decommission_log_step($pdo, $jobId, $storeId, 'runner.start', '廃棄実行開始', 'started', $dryRun ? 'dry-run' : 'execute', [
    'dry_run' => $dryRun,
  ], $systemUserId > 0 ? $systemUserId : null);

  try {
    foreach ($plan as $entry) {
      $table = (string)$entry['table'];
      $label = 'Delete ' . $table;
      store_decommission_log_step($pdo, $jobId, $storeId, 'delete.' . $table, $label, 'started', null, [], $systemUserId ?: null);

      $countSt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE store_id = ?");
      $countSt->execute([$storeId]);
      $count = (int)$countSt->fetchColumn();

      if ($count === 0) {
        store_decommission_log_step($pdo, $jobId, $storeId, 'delete.' . $table, $label, 'completed', '0 rows', [
          'rows' => 0,
        ], $systemUserId ?: null);
        $result['skipped'][$table] = 0;
        continue;
      }

      if (!$dryRun) {
        $del = $pdo->prepare("DELETE FROM `{$table}` WHERE store_id = ?");
        $del->execute([$storeId]);
      }

      store_decommission_log_step($pdo, $jobId, $storeId, 'delete.' . $table, $label, 'completed', $dryRun ? 'dry-run' : null, [
        'rows' => $count,
        'dry_run' => $dryRun,
      ], $systemUserId ?: null);
      $result['deleted'][$table] = $count;
    }

    if ($dryRun) {
      $pdo->prepare("
        UPDATE store_decommission_jobs
        SET status = 'scheduled',
            started_at = NULL,
            updated_at = NOW()
        WHERE id = ?
        LIMIT 1
      ")->execute([$jobId]);
    } else {
      store_decommission_mark_job_completed($pdo, $jobId, $systemUserId);
    }

    store_decommission_log_step($pdo, $jobId, $storeId, 'runner.finish', '廃棄実行完了', 'completed', null, [
      'dry_run' => $dryRun,
      'deleted_tables' => array_keys($result['deleted']),
    ], $systemUserId ?: null);

    return $result;
  } catch (Throwable $e) {
    store_decommission_mark_job_failed($pdo, $jobId, $e->getMessage());
    store_decommission_log_step($pdo, $jobId, $storeId, 'runner.finish', '廃棄実行完了', 'failed', $e->getMessage(), [
      'dry_run' => $dryRun,
    ], $systemUserId ?: null);
    throw $e;
  }
}

function store_decommission_is_write_blocked(PDO $pdo, int $storeId): bool
{
  $store = store_decommission_fetch_store($pdo, $storeId);
  return in_array((string)($store['lifecycle_status'] ?? 'active'), ['suspended', 'decommissioning', 'decommissioned'], true);
}

function store_decommission_assert_store_writable(PDO $pdo, int $storeId, string $message = 'この店舗は停止中のため操作できません'): void
{
  if (!store_decommission_is_write_blocked($pdo, $storeId)) {
    return;
  }
  throw new RuntimeException($message);
}
