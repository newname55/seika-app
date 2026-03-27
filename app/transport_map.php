<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/service_transport.php';
require_once __DIR__ . '/transport_vehicle_location.php';

function transport_map_status_definitions(): array {
  return [
    'pending' => ['label' => '未割当', 'color' => '#dc2626'],
    'assigned' => ['label' => '割当済', 'color' => '#2563eb'],
    'in_progress' => ['label' => '送迎中', 'color' => '#ea580c'],
    'done' => ['label' => '完了', 'color' => '#16a34a'],
    'cancelled' => ['label' => 'キャンセル', 'color' => '#64748b'],
  ];
}

function transport_map_direction_options(): array {
  return ['北', '北東', '東', '南東', '南', '南西', '西', '北西', '未分類'];
}

function transport_map_store_short_labels(): array {
  return [
    1 => '星',
    2 => 'ク',
    3 => '麒',
    4 => 'ス',
    5 => 'パ',
  ];
}

function transport_map_store_short_label(int $storeId): string {
  $labels = transport_map_store_short_labels();
  return (string)($labels[$storeId] ?? (string)$storeId);
}

function transport_map_can_view_all_stores(): bool {
  return function_exists('can_view_all_store_shift') && can_view_all_store_shift();
}

function transport_map_table_exists(PDO $pdo, string $tableName): bool {
  try {
    $st = $pdo->prepare("
      SELECT COUNT(*)
      FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
    ");
    $st->execute([$tableName]);
    return ((int)$st->fetchColumn() > 0);
  } catch (Throwable $e) {
    return false;
  }
}

function transport_map_status_label(string $status): string {
  $defs = transport_map_status_definitions();
  return (string)($defs[$status]['label'] ?? $status);
}

function transport_map_status_color(string $status): string {
  $defs = transport_map_status_definitions();
  return (string)($defs[$status]['color'] ?? '#475569');
}

function transport_map_normalize_status(?string $status): string {
  $status = trim((string)$status);
  if ($status === '' || $status === 'all') {
    return '';
  }
  return array_key_exists($status, transport_map_status_definitions()) ? $status : '';
}

function transport_map_normalize_direction(?string $value): string {
  $value = trim((string)$value);
  if ($value === '' || $value === 'all') {
    return '';
  }
  return in_array($value, transport_map_direction_options(), true) ? $value : '';
}

function transport_map_normalize_date(?string $value, string $default): string {
  $value = trim((string)$value);
  return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $default;
}

function transport_map_normalize_time(?string $value): string {
  $value = trim((string)$value);
  if ($value === '') {
    return '';
  }
  if (preg_match('/^\d{2}:\d{2}$/', $value)) {
    return $value . ':00';
  }
  if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)) {
    return $value;
  }
  return '';
}

function transport_map_fetch_store_row(PDO $pdo, int $storeId): array {
  $st = $pdo->prepare("
    SELECT id, name, business_day_start, lat, lon
    FROM stores
    WHERE id = ?
    LIMIT 1
  ");
  $st->execute([$storeId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    throw new RuntimeException('店舗情報が見つかりません');
  }
  return $row;
}

function transport_map_default_business_date(array $storeRow): string {
  return business_date_for_store($storeRow, null);
}

function transport_map_filters_from_request(PDO $pdo, array $source): array {
  $stores = transport_allowed_stores($pdo);
  $allowedStoreIds = [];
  foreach ($stores as $store) {
    $sid = (int)($store['id'] ?? 0);
    if ($sid > 0) {
      $allowedStoreIds[] = $sid;
    }
  }
  if ($allowedStoreIds === []) {
    throw new RuntimeException('閲覧可能な店舗がありません');
  }

  $requestedStoreRaw = trim((string)($source['store_id'] ?? ''));
  $isAllStores = transport_map_can_view_all_stores() && in_array($requestedStoreRaw, ['all', '*', '0'], true);
  $storeId = $isAllStores ? 0 : transport_resolve_store_id($pdo, (int)$requestedStoreRaw);
  $defaultStoreId = $storeId > 0 ? $storeId : (int)$allowedStoreIds[0];
  $storeRow = transport_map_fetch_store_row($pdo, $defaultStoreId);
  $defaultBusinessDate = transport_map_default_business_date($storeRow);

  $driverUserId = (int)($source['driver_user_id'] ?? 0);
  if ($driverUserId < 0) {
    $driverUserId = 0;
  }

  return [
    'store_id' => $storeId,
    'store_scope' => $isAllStores ? 'all' : 'single',
    'store_ids' => $isAllStores ? $allowedStoreIds : [$defaultStoreId],
    'business_date' => transport_map_normalize_date((string)($source['business_date'] ?? ''), $defaultBusinessDate),
    'status' => transport_map_normalize_status((string)($source['status'] ?? '')),
    'driver_user_id' => $driverUserId,
    'direction_bucket' => transport_map_normalize_direction((string)($source['direction_bucket'] ?? '')),
    'unassigned_only' => ((string)($source['unassigned_only'] ?? '0') === '1') ? 1 : 0,
    'time_from' => transport_map_normalize_time((string)($source['time_from'] ?? '')),
    'time_to' => transport_map_normalize_time((string)($source['time_to'] ?? '')),
  ];
}

function transport_map_fetch_driver_options(PDO $pdo, int $storeId): array {
  $sql = "
    SELECT DISTINCT
      u.id,
      COALESCE(NULLIF(TRIM(u.display_name), ''), NULLIF(TRIM(u.login_id), ''), CONCAT('user#', u.id)) AS name
    FROM user_roles ur
    JOIN roles r
      ON r.id = ur.role_id
    JOIN users u
      ON u.id = ur.user_id
     AND u.is_active = 1
    WHERE ur.store_id = ?
      AND r.code IN ('staff', 'manager', 'admin', 'super_user')
    ORDER BY name ASC, u.id ASC
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$storeId]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function transport_map_fetch_driver_options_for_stores(PDO $pdo, array $storeIds): array {
  $merged = [];
  foreach ($storeIds as $storeId) {
    foreach (transport_map_fetch_driver_options($pdo, (int)$storeId) as $driver) {
      $driverId = (int)($driver['id'] ?? 0);
      if ($driverId <= 0) {
        continue;
      }
      $merged[$driverId] = [
        'id' => $driverId,
        'name' => (string)($driver['name'] ?? ''),
      ];
    }
  }
  uasort($merged, static function (array $a, array $b): int {
    return strcmp((string)$a['name'], (string)$b['name']);
  });
  return array_values($merged);
}

function transport_map_can_view_full_address(): bool {
  return is_role('manager') || is_role('admin') || is_role('super_user');
}

function transport_map_shorten_address(string $address): string {
  $address = trim($address);
  if ($address === '') {
    return '';
  }
  if (mb_strlen($address) <= 24) {
    return $address;
  }
  return mb_substr($address, 0, 24) . '…';
}

function transport_map_mask_address(string $address): string {
  $address = trim($address);
  if ($address === '') {
    return '';
  }
  if (mb_strlen($address) <= 8) {
    return mb_substr($address, 0, 4) . '…';
  }
  return mb_substr($address, 0, 8) . '…';
}

function transport_map_compute_direction(?float $originLat, ?float $originLng, ?float $targetLat, ?float $targetLng, string $fallback = ''): string {
  if ($originLat === null || $originLng === null || $targetLat === null || $targetLng === null) {
    return $fallback !== '' ? $fallback : '未分類';
  }

  $dy = $targetLat - $originLat;
  $dx = $targetLng - $originLng;
  if (abs($dx) < 0.00001 && abs($dy) < 0.00001) {
    return '未分類';
  }

  $angle = rad2deg(atan2($dy, $dx));
  $angle = fmod(($angle + 360.0), 360.0);
  $labels = ['東', '北東', '北', '北西', '西', '南西', '南', '南東'];
  $index = (int)floor(($angle + 22.5) / 45.0) % 8;
  return $labels[$index] ?? '未分類';
}

function transport_map_round_distance(?float $value): ?float {
  if ($value === null) {
    return null;
  }
  return round($value, 1);
}

function transport_map_fetch_base_context(PDO $pdo, int $storeId): array {
  $base = transport_fetch_store_base($pdo, $storeId);
  return [
    'id' => (int)($base['id'] ?? 0),
    'name' => (string)($base['name'] ?? '店舗'),
    'address_text' => (string)($base['address_text'] ?? ''),
    'lat' => isset($base['lat']) ? ($base['lat'] !== null ? (float)$base['lat'] : null) : null,
    'lng' => isset($base['lng']) ? ($base['lng'] !== null ? (float)$base['lng'] : null) : null,
  ];
}

function transport_map_fetch_base_contexts(PDO $pdo, array $storeIds): array {
  $contexts = [];
  foreach ($storeIds as $storeId) {
    $storeId = (int)$storeId;
    if ($storeId <= 0) {
      continue;
    }
    $contexts[$storeId] = transport_map_fetch_base_context($pdo, $storeId);
    $contexts[$storeId]['store_id'] = $storeId;
    $contexts[$storeId]['store_short_label'] = transport_map_store_short_label($storeId);
  }
  return $contexts;
}

function transport_map_geocode_address(string $address): array {
  return transport_google_geocode($address);
}

function transport_map_fetch_required_candidates(PDO $pdo, int $storeId, string $businessDate): array {
  $groups = transport_fetch_route_candidates($pdo, $businessDate, [$storeId]);
  if ($groups === []) {
    return [];
  }

  $group = $groups[0];
  $items = [];
  foreach ((array)($group['casts'] ?? []) as $cast) {
    if (empty($cast['requires_pickup'])) {
      continue;
    }

    $castId = (int)($cast['user_id'] ?? 0);
    if ($castId <= 0) {
      continue;
    }

    $items[$castId] = [
      'id' => -1 * (1000000 + $castId),
      'store_id' => $storeId,
      'business_date' => $businessDate,
      'cast_id' => $castId,
      'pickup_name' => (string)($cast['display_name'] ?? ''),
      'cast_name' => (string)($cast['display_name'] ?? ''),
      'shop_tag' => (string)($cast['shop_tag'] ?? ''),
      'pickup_address' => (string)($cast['pickup_address'] ?? ''),
      'pickup_lat' => ($cast['pickup_lat'] ?? null) !== null ? (float)$cast['pickup_lat'] : null,
      'pickup_lng' => ($cast['pickup_lng'] ?? null) !== null ? (float)$cast['pickup_lng'] : null,
      'pickup_note' => (string)($cast['pickup_note'] ?? ''),
      'pickup_time_from' => (string)($cast['start_time'] ?? ''),
      'pickup_time_to' => '',
      'area_name' => '',
      'direction_bucket' => '',
      'status' => 'pending',
      'driver_user_id' => null,
      'driver_name' => '',
      'vehicle_label' => null,
      'sort_order' => 0,
      'source_type' => 'shift_plan',
      'pickup_target' => (string)($cast['pickup_target'] ?? 'primary'),
      'pickup_target_label' => (string)($cast['pickup_target_label'] ?? transport_pickup_target_label((string)($cast['pickup_target'] ?? 'primary'))),
      'has_coords' => !empty($cast['has_coords']),
      'has_address' => !empty($cast['has_address']),
    ];
  }

  return $items;
}

function transport_map_fetch_required_candidates_for_stores(PDO $pdo, array $storeIds, string $businessDate): array {
  $groups = transport_fetch_route_candidates($pdo, $businessDate, $storeIds);
  if ($groups === []) {
    return [];
  }

  $items = [];
  foreach ($groups as $group) {
    $groupStoreId = (int)($group['store_id'] ?? 0);
    if ($groupStoreId <= 0) {
      continue;
    }
    foreach ((array)($group['casts'] ?? []) as $cast) {
      if (empty($cast['requires_pickup'])) {
        continue;
      }
      $castId = (int)($cast['user_id'] ?? 0);
      if ($castId <= 0) {
        continue;
      }
      $key = $groupStoreId . ':' . $castId;
      $items[$key] = [
        'id' => -1 * (1000000 + ($groupStoreId * 10000) + $castId),
        'store_id' => $groupStoreId,
        'business_date' => $businessDate,
        'cast_id' => $castId,
        'pickup_name' => (string)($cast['display_name'] ?? ''),
        'cast_name' => (string)($cast['display_name'] ?? ''),
        'shop_tag' => (string)($cast['shop_tag'] ?? ''),
        'pickup_address' => (string)($cast['pickup_address'] ?? ''),
        'pickup_lat' => ($cast['pickup_lat'] ?? null) !== null ? (float)$cast['pickup_lat'] : null,
        'pickup_lng' => ($cast['pickup_lng'] ?? null) !== null ? (float)$cast['pickup_lng'] : null,
        'pickup_note' => (string)($cast['pickup_note'] ?? ''),
        'pickup_time_from' => (string)($cast['start_time'] ?? ''),
        'pickup_time_to' => '',
        'area_name' => '',
        'direction_bucket' => '',
        'status' => 'pending',
        'driver_user_id' => null,
        'driver_name' => '',
        'vehicle_label' => null,
        'sort_order' => 0,
        'source_type' => 'shift_plan',
        'pickup_target' => (string)($cast['pickup_target'] ?? 'primary'),
        'pickup_target_label' => (string)($cast['pickup_target_label'] ?? transport_pickup_target_label((string)($cast['pickup_target'] ?? 'primary'))),
        'has_coords' => !empty($cast['has_coords']),
        'has_address' => !empty($cast['has_address']),
      ];
    }
  }

  return $items;
}

function transport_map_normalize_driver_user_id($value): ?int {
  if ($value === null || $value === '') {
    return null;
  }
  if (!is_numeric($value)) {
    throw new RuntimeException('ドライバー指定が不正です');
  }
  $driverUserId = (int)$value;
  return $driverUserId > 0 ? $driverUserId : null;
}

function transport_map_validate_driver_user_id(PDO $pdo, int $storeId, ?int $driverUserId): void {
  if ($driverUserId === null) {
    return;
  }
  foreach (transport_map_fetch_driver_options($pdo, $storeId) as $driver) {
    if ((int)($driver['id'] ?? 0) === $driverUserId) {
      return;
    }
  }
  throw new RuntimeException('指定されたドライバーはこの店舗で割り当てできません');
}

function transport_map_normalize_vehicle_label(?string $value): ?string {
  $value = trim((string)$value);
  if ($value === '') {
    return null;
  }
  return mb_substr($value, 0, 64);
}

function transport_map_normalize_sort_order($value): ?int {
  if ($value === null || $value === '') {
    return null;
  }
  if (!is_numeric($value)) {
    throw new RuntimeException('並び順が不正です');
  }
  $sortOrder = (int)$value;
  return $sortOrder >= 0 ? $sortOrder : null;
}

function transport_map_fetch_assignment_by_cast(PDO $pdo, int $storeId, string $businessDate, int $castId): ?array {
  $st = $pdo->prepare("
    SELECT *
    FROM transport_assignments
    WHERE store_id = ?
      AND business_date = ?
      AND cast_id = ?
    LIMIT 1
  ");
  $st->execute([$storeId, $businessDate, $castId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function transport_map_resolve_save_store_id(PDO $pdo, array $source): int {
  $requestedStoreId = (int)($source['store_id'] ?? 0);
  if ($requestedStoreId > 0) {
    return transport_resolve_store_id($pdo, $requestedStoreId);
  }

  $castId = (int)($source['cast_id'] ?? 0);
  $businessDate = trim((string)($source['business_date'] ?? ''));
  if ($castId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate)) {
    throw new RuntimeException('対象店舗が不正です');
  }

  $stores = transport_allowed_stores($pdo);
  $allowedStoreIds = [];
  foreach ($stores as $store) {
    $sid = (int)($store['id'] ?? 0);
    if ($sid > 0) {
      $allowedStoreIds[] = $sid;
    }
  }
  if ($allowedStoreIds === []) {
    throw new RuntimeException('対象店舗が不正です');
  }

  if (transport_map_table_exists($pdo, 'transport_assignments')) {
    $ph = implode(',', array_fill(0, count($allowedStoreIds), '?'));
    $st = $pdo->prepare("
      SELECT store_id
      FROM transport_assignments
      WHERE business_date = ?
        AND cast_id = ?
        AND store_id IN ({$ph})
      ORDER BY updated_at DESC, id DESC
      LIMIT 2
    ");
    $st->execute(array_merge([$businessDate, $castId], $allowedStoreIds));
    $matchedStoreIds = array_values(array_unique(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: [])));
    if (count($matchedStoreIds) === 1 && $matchedStoreIds[0] > 0) {
      return $matchedStoreIds[0];
    }
  }

  $candidates = transport_map_fetch_required_candidates_for_stores($pdo, $allowedStoreIds, $businessDate);
  $matchedStoreIds = [];
  foreach ($candidates as $candidate) {
    if ((int)($candidate['cast_id'] ?? 0) !== $castId) {
      continue;
    }
    $candidateStoreId = (int)($candidate['store_id'] ?? 0);
    if ($candidateStoreId > 0) {
      $matchedStoreIds[] = $candidateStoreId;
    }
  }
  $matchedStoreIds = array_values(array_unique($matchedStoreIds));
  if (count($matchedStoreIds) === 1 && $matchedStoreIds[0] > 0) {
    return $matchedStoreIds[0];
  }

  throw new RuntimeException('対象店舗が不正です');
}

function transport_map_resolve_assignment_status(?string $requestedStatus, ?int $driverUserId, string $fallbackStatus): string {
  $status = transport_map_normalize_status($requestedStatus);
  if ($status === '') {
    if ($driverUserId === null) {
      return in_array($fallbackStatus, ['in_progress', 'done', 'cancelled'], true) ? $fallbackStatus : 'pending';
    }
    return $fallbackStatus === 'pending' ? 'assigned' : $fallbackStatus;
  }

  if ($driverUserId === null && in_array($status, ['assigned', 'in_progress', 'done'], true)) {
    throw new RuntimeException('ドライバー未割当ではこのステータスにできません');
  }

  return $status;
}

function transport_map_log_assignment_change(PDO $pdo, int $assignmentId, int $storeId, string $action, int $actorUserId, ?array $before, ?array $after): void {
  if (!transport_map_table_exists($pdo, 'transport_assignment_logs')) {
    return;
  }

  $st = $pdo->prepare("
    INSERT INTO transport_assignment_logs (
      assignment_id,
      store_id,
      action,
      actor_user_id,
      before_json,
      after_json
    ) VALUES (
      :assignment_id,
      :store_id,
      :action,
      :actor_user_id,
      :before_json,
      :after_json
    )
  ");
  $st->execute([
    ':assignment_id' => $assignmentId,
    ':store_id' => $storeId,
    ':action' => $action,
    ':actor_user_id' => $actorUserId > 0 ? $actorUserId : null,
    ':before_json' => $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
    ':after_json' => $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
  ]);
}

function transport_map_save_assignment(PDO $pdo, array $source, int $actorUserId): array {
  if (!transport_map_table_exists($pdo, 'transport_assignments')) {
    throw new RuntimeException('transport_assignments テーブルが未作成です。先にSQLを適用してください。');
  }

  error_log('[transport_map_save_assignment] source=' . json_encode([
    'store_id' => $source['store_id'] ?? null,
    'business_date' => $source['business_date'] ?? null,
    'cast_id' => $source['cast_id'] ?? null,
    'driver_user_id' => $source['driver_user_id'] ?? null,
    'status' => $source['status'] ?? null,
    'sort_order' => $source['sort_order'] ?? null,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

  $storeId = transport_map_resolve_save_store_id($pdo, $source);
  $storeRow = transport_map_fetch_store_row($pdo, $storeId);
  $businessDate = transport_map_normalize_date((string)($source['business_date'] ?? ''), transport_map_default_business_date($storeRow));
  $castId = (int)($source['cast_id'] ?? 0);
  if ($castId <= 0) {
    throw new RuntimeException('送迎対象のキャストを特定できません');
  }

  $driverUserId = transport_map_normalize_driver_user_id($source['driver_user_id'] ?? null);
  transport_map_validate_driver_user_id($pdo, $storeId, $driverUserId);
  $vehicleLabel = transport_map_normalize_vehicle_label((string)($source['vehicle_label'] ?? ''));
  $sortOrder = transport_map_normalize_sort_order($source['sort_order'] ?? null);

  error_log('[transport_map_save_assignment] normalized=' . json_encode([
    'store_id' => $storeId,
    'business_date' => $businessDate,
    'cast_id' => $castId,
    'driver_user_id' => $driverUserId,
    'status' => $source['status'] ?? null,
    'sort_order' => $sortOrder,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

  $pdo->beginTransaction();
  try {
    $existing = transport_map_fetch_assignment_by_cast($pdo, $storeId, $businessDate, $castId);
    $action = 'update';
    $assignmentId = 0;

    if ($existing) {
      $nextStatus = transport_map_resolve_assignment_status((string)($source['status'] ?? ''), $driverUserId, (string)($existing['status'] ?? 'pending'));
      $assignmentId = (int)($existing['id'] ?? 0);
      $st = $pdo->prepare("
        UPDATE transport_assignments
        SET driver_user_id = :driver_user_id,
            status = :status,
            vehicle_label = :vehicle_label,
            sort_order = COALESCE(:sort_order, sort_order),
            updated_by_user_id = :updated_by_user_id,
            updated_at = NOW()
        WHERE id = :id
          AND store_id = :store_id
      ");
      $st->execute([
        ':driver_user_id' => $driverUserId,
        ':status' => $nextStatus,
        ':vehicle_label' => $vehicleLabel,
        ':sort_order' => $sortOrder,
        ':updated_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
        ':id' => $assignmentId,
        ':store_id' => $storeId,
      ]);
    } else {
      $candidates = transport_map_fetch_required_candidates($pdo, $storeId, $businessDate);
      $candidate = $candidates[$castId] ?? null;
      if (!$candidate) {
        throw new RuntimeException('勤務予定ベースの送迎対象データが見つかりません');
      }
      if (trim((string)($candidate['pickup_address'] ?? '')) === '') {
        throw new RuntimeException('住所未登録のため送迎割当を保存できません');
      }

      $base = transport_map_fetch_base_context($pdo, $storeId);
      $directionBucket = transport_map_compute_direction(
        $base['lat'],
        $base['lng'],
        ($candidate['pickup_lat'] ?? null) !== null ? (float)$candidate['pickup_lat'] : null,
        ($candidate['pickup_lng'] ?? null) !== null ? (float)$candidate['pickup_lng'] : null,
        (string)($candidate['area_name'] ?? '')
      );
      $nextStatus = transport_map_resolve_assignment_status((string)($source['status'] ?? ''), $driverUserId, 'pending');

      $st = $pdo->prepare("
        INSERT INTO transport_assignments (
          store_id,
          business_date,
          cast_id,
          pickup_name,
          pickup_address,
          pickup_lat,
          pickup_lng,
          pickup_note,
          pickup_time_from,
          pickup_time_to,
          area_name,
          direction_bucket,
          status,
          driver_user_id,
          vehicle_label,
          sort_order,
          source_type,
          created_by_user_id,
          updated_by_user_id
        ) VALUES (
          :store_id,
          :business_date,
          :cast_id,
          :pickup_name,
          :pickup_address,
          :pickup_lat,
          :pickup_lng,
          :pickup_note,
          :pickup_time_from,
          :pickup_time_to,
          :area_name,
          :direction_bucket,
          :status,
          :driver_user_id,
          :vehicle_label,
          :sort_order,
          :source_type,
          :created_by_user_id,
          :updated_by_user_id
        )
      ");
      $st->execute([
        ':store_id' => $storeId,
        ':business_date' => $businessDate,
        ':cast_id' => $castId,
        ':pickup_name' => (string)($candidate['pickup_name'] ?? ''),
        ':pickup_address' => (string)($candidate['pickup_address'] ?? ''),
        ':pickup_lat' => $candidate['pickup_lat'] ?? null,
        ':pickup_lng' => $candidate['pickup_lng'] ?? null,
        ':pickup_note' => (string)($candidate['pickup_note'] ?? ''),
        ':pickup_time_from' => (string)($candidate['pickup_time_from'] ?? '') !== '' ? (string)$candidate['pickup_time_from'] : null,
        ':pickup_time_to' => (string)($candidate['pickup_time_to'] ?? '') !== '' ? (string)$candidate['pickup_time_to'] : null,
        ':area_name' => (string)($candidate['area_name'] ?? ''),
        ':direction_bucket' => $directionBucket !== '' ? $directionBucket : null,
        ':status' => $nextStatus,
        ':driver_user_id' => $driverUserId,
        ':vehicle_label' => $vehicleLabel,
        ':sort_order' => (int)($sortOrder ?? (int)($candidate['sort_order'] ?? 0)),
        ':source_type' => 'shift_plan',
        ':created_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
        ':updated_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
      ]);
      $assignmentId = (int)$pdo->lastInsertId();
      $action = 'create';
      $existing = null;
    }

    $after = transport_map_fetch_assignment_by_cast($pdo, $storeId, $businessDate, $castId);
    if (!$after || (int)($after['id'] ?? 0) <= 0) {
      throw new RuntimeException('送迎割当の保存後データを取得できませんでした');
    }

    transport_map_log_assignment_change(
      $pdo,
      (int)$after['id'],
      $storeId,
      $action,
      $actorUserId,
      $existing,
      $after
    );

    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }

  $data = transport_map_fetch_data($pdo, [
    'store_id' => $storeId,
    'business_date' => $businessDate,
    'status' => '',
    'driver_user_id' => 0,
    'direction_bucket' => '',
    'unassigned_only' => 0,
    'time_from' => '',
    'time_to' => '',
  ]);
  foreach ((array)($data['items'] ?? []) as $item) {
    if ((int)($item['cast_id'] ?? 0) === $castId) {
      return $item;
    }
  }

  throw new RuntimeException('保存後の送迎データを一覧へ反映できませんでした');
}

function transport_map_bulk_unassign(PDO $pdo, array $source, int $actorUserId): array {
  if (!transport_map_table_exists($pdo, 'transport_assignments')) {
    throw new RuntimeException('transport_assignments テーブルが未作成です。先にSQLを適用してください。');
  }

  $storeId = transport_map_resolve_save_store_id($pdo, $source);
  $storeRow = transport_map_fetch_store_row($pdo, $storeId);
  $businessDate = transport_map_normalize_date((string)($source['business_date'] ?? ''), transport_map_default_business_date($storeRow));

  $st = $pdo->prepare("
    SELECT *
    FROM transport_assignments
    WHERE store_id = ?
      AND business_date = ?
      AND driver_user_id IS NOT NULL
      AND status IN ('assigned', 'in_progress')
    ORDER BY id ASC
  ");
  $st->execute([$storeId, $businessDate]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  if ($rows === []) {
    return [
      'updated' => 0,
      'store_id' => $storeId,
      'business_date' => $businessDate,
    ];
  }

  $pdo->beginTransaction();
  try {
    $updateSt = $pdo->prepare("
      UPDATE transport_assignments
      SET driver_user_id = NULL,
          status = 'pending',
          vehicle_label = NULL,
          sort_order = 0,
          updated_by_user_id = :updated_by_user_id,
          updated_at = NOW()
      WHERE id = :id
        AND store_id = :store_id
    ");

    foreach ($rows as $row) {
      $assignmentId = (int)($row['id'] ?? 0);
      if ($assignmentId <= 0) {
        continue;
      }
      $updateSt->execute([
        ':updated_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
        ':id' => $assignmentId,
        ':store_id' => $storeId,
      ]);
      $after = $row;
      $after['driver_user_id'] = null;
      $after['status'] = 'pending';
      $after['vehicle_label'] = null;
      $after['sort_order'] = 0;
      transport_map_log_assignment_change(
        $pdo,
        $assignmentId,
        $storeId,
        'bulk_unassign',
        $actorUserId,
        $row,
        $after
      );
    }

    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }

  return [
    'updated' => count($rows),
    'store_id' => $storeId,
    'business_date' => $businessDate,
  ];
}

function transport_map_fetch_rows(PDO $pdo, array $filters): array {
  if (!transport_map_table_exists($pdo, 'transport_assignments')) {
    return [];
  }

  $where = [
    'ta.business_date = :business_date',
  ];
  $params = [
    ':business_date' => (string)$filters['business_date'],
  ];
  $storeIds = array_values(array_filter(array_map('intval', (array)($filters['store_ids'] ?? [])), static fn(int $id): bool => $id > 0));
  if ($storeIds === []) {
    throw new RuntimeException('対象店舗が不正です');
  }
  if (count($storeIds) === 1) {
    $where[] = 'ta.store_id = :store_id';
    $params[':store_id'] = $storeIds[0];
  } else {
    $ph = [];
    foreach ($storeIds as $index => $storeId) {
      $key = ':store_id_' . $index;
      $ph[] = $key;
      $params[$key] = $storeId;
    }
    $where[] = 'ta.store_id IN (' . implode(', ', $ph) . ')';
  }

  if ((string)$filters['status'] !== '') {
    $where[] = 'ta.status = :status';
    $params[':status'] = (string)$filters['status'];
  }
  if ((int)$filters['driver_user_id'] > 0) {
    $where[] = 'ta.driver_user_id = :driver_user_id';
    $params[':driver_user_id'] = (int)$filters['driver_user_id'];
  }
  if ((int)$filters['unassigned_only'] === 1) {
    $where[] = 'ta.driver_user_id IS NULL';
  }
  if ((string)$filters['time_from'] !== '') {
    $where[] = "COALESCE(ta.pickup_time_to, ta.pickup_time_from, '23:59:59') >= :time_from";
    $params[':time_from'] = (string)$filters['time_from'];
  }
  if ((string)$filters['time_to'] !== '') {
    $where[] = "COALESCE(ta.pickup_time_from, ta.pickup_time_to, '00:00:00') <= :time_to";
    $params[':time_to'] = (string)$filters['time_to'];
  }

  $sql = "
    SELECT
      ta.id,
      ta.store_id,
      ta.business_date,
      ta.cast_id,
      ta.pickup_name,
      ta.pickup_address,
      ta.pickup_lat,
      ta.pickup_lng,
      ta.pickup_note,
      ta.pickup_time_from,
      ta.pickup_time_to,
      ta.area_name,
      ta.direction_bucket,
      ta.status,
      ta.driver_user_id,
      ta.vehicle_label,
      ta.sort_order,
      ta.created_at,
      ta.updated_at,
      COALESCE(NULLIF(TRIM(cu.display_name), ''), NULLIF(TRIM(cu.login_id), ''), CONCAT('cast#', ta.cast_id)) AS cast_name,
      COALESCE(NULLIF(TRIM(cp.shop_tag), ''), '') AS shop_tag,
      COALESCE(NULLIF(TRIM(du.display_name), ''), NULLIF(TRIM(du.login_id), ''), '') AS driver_name
    FROM transport_assignments ta
    LEFT JOIN users cu
      ON cu.id = ta.cast_id
    LEFT JOIN cast_profiles cp
      ON cp.user_id = ta.cast_id
     AND (cp.store_id = ta.store_id OR cp.store_id IS NULL)
    LEFT JOIN users du
      ON du.id = ta.driver_user_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY
      CASE ta.status
        WHEN 'pending' THEN 0
        WHEN 'assigned' THEN 1
        WHEN 'in_progress' THEN 2
        WHEN 'done' THEN 3
        WHEN 'cancelled' THEN 4
        ELSE 5
      END,
      ta.pickup_time_from ASC,
      ta.sort_order ASC,
      ta.id ASC
  ";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function transport_map_fetch_data(PDO $pdo, array $filters): array {
  $storeIds = array_values(array_filter(array_map('intval', (array)($filters['store_ids'] ?? [])), static fn(int $id): bool => $id > 0));
  $baseContexts = transport_map_fetch_base_contexts($pdo, $storeIds);
  $base = (count($storeIds) === 1 && isset($baseContexts[$storeIds[0]])) ? $baseContexts[$storeIds[0]] : [
    'id' => 0,
    'name' => '全店舗',
    'address_text' => '',
    'lat' => null,
    'lng' => null,
  ];
  $rows = transport_map_fetch_rows($pdo, $filters);
  $requiredCandidates = transport_map_fetch_required_candidates_for_stores($pdo, $storeIds, (string)$filters['business_date']);
  $vehicles = [];
  foreach ($storeIds as $storeId) {
    foreach (transport_vehicle_fetch_latest($pdo, (int)$storeId) as $vehicle) {
      $vehicle['store_short_label'] = transport_map_store_short_label((int)($vehicle['store_id'] ?? 0));
      $vehicles[] = $vehicle;
    }
  }
  $canViewFullAddress = transport_map_can_view_full_address();

  foreach ($rows as $row) {
    $castId = (int)($row['cast_id'] ?? 0);
    $storeId = (int)($row['store_id'] ?? 0);
    $candidateKey = $storeId . ':' . $castId;
    if ($castId > 0 && isset($requiredCandidates[$candidateKey])) {
      unset($requiredCandidates[$candidateKey]);
    }
  }
  if ($requiredCandidates !== []) {
    $rows = array_merge($rows, array_values($requiredCandidates));
  }

  $items = [];
  $summary = [
    'total' => 0,
    'mappable' => 0,
    'without_coords' => 0,
    'pending' => 0,
    'assigned' => 0,
    'in_progress' => 0,
    'done' => 0,
    'cancelled' => 0,
    'unassigned' => 0,
    'by_direction' => [],
    'by_driver' => [],
  ];

  foreach ($rows as $row) {
    $rowStoreId = (int)($row['store_id'] ?? 0);
    $rowBase = $baseContexts[$rowStoreId] ?? $base;
    $pickupLat = ($row['pickup_lat'] ?? null) !== null ? (float)$row['pickup_lat'] : null;
    $pickupLng = ($row['pickup_lng'] ?? null) !== null ? (float)$row['pickup_lng'] : null;
    $direction = trim((string)($row['direction_bucket'] ?? ''));
    if ($direction === '') {
      $direction = transport_map_compute_direction($rowBase['lat'], $rowBase['lng'], $pickupLat, $pickupLng, trim((string)($row['area_name'] ?? '')));
    }
    if (!in_array($direction, transport_map_direction_options(), true)) {
      $direction = $direction !== '' ? $direction : '未分類';
    }

    $distanceKm = null;
    if ($rowBase['lat'] !== null && $rowBase['lng'] !== null && $pickupLat !== null && $pickupLng !== null) {
      $distanceKm = transport_map_round_distance(transport_haversine_km((float)$rowBase['lat'], (float)$rowBase['lng'], $pickupLat, $pickupLng));
    }

    $status = (string)($row['status'] ?? 'pending');
    if (!array_key_exists($status, transport_map_status_definitions())) {
      $status = 'pending';
    }

    $item = [
      'id' => (int)($row['id'] ?? 0),
      'cast_id' => (int)($row['cast_id'] ?? 0),
      'cast_name' => (string)($row['cast_name'] ?? ''),
      'shop_tag' => (string)($row['shop_tag'] ?? ''),
      'display_name' => trim((string)($row['pickup_name'] ?? '')) !== '' ? (string)$row['pickup_name'] : (string)($row['cast_name'] ?? ''),
      'store_id' => (int)($row['store_id'] ?? 0),
      'store_short_label' => transport_map_store_short_label($rowStoreId),
      'business_date' => (string)($row['business_date'] ?? ''),
      'pickup_address' => $canViewFullAddress ? (string)($row['pickup_address'] ?? '') : transport_map_mask_address((string)($row['pickup_address'] ?? '')),
      'pickup_address_short' => transport_map_shorten_address((string)($row['pickup_address'] ?? '')),
      'pickup_lat' => $pickupLat,
      'pickup_lng' => $pickupLng,
      'has_coords' => ($pickupLat !== null && $pickupLng !== null),
      'pickup_note' => (string)($row['pickup_note'] ?? ''),
      'pickup_time_from' => (string)($row['pickup_time_from'] ?? ''),
      'pickup_time_to' => (string)($row['pickup_time_to'] ?? ''),
      'area_name' => (string)($row['area_name'] ?? ''),
      'direction_bucket' => $direction,
      'status' => $status,
      'status_label' => transport_map_status_label($status),
      'status_color' => transport_map_status_color($status),
      'driver_user_id' => ($row['driver_user_id'] ?? null) !== null ? (int)$row['driver_user_id'] : null,
      'driver_name' => trim((string)($row['driver_name'] ?? '')) !== '' ? (string)$row['driver_name'] : null,
      'vehicle_label' => trim((string)($row['vehicle_label'] ?? '')) !== '' ? (string)$row['vehicle_label'] : null,
      'distance_km' => $distanceKm,
      'sort_order' => (int)($row['sort_order'] ?? 0),
      'note_exists' => trim((string)($row['pickup_note'] ?? '')) !== '',
      'source_type' => (string)($row['source_type'] ?? 'assignment'),
      'pickup_target' => (string)($row['pickup_target'] ?? 'primary'),
      'pickup_target_label' => (string)($row['pickup_target_label'] ?? transport_pickup_target_label((string)($row['pickup_target'] ?? 'primary'))),
      'route_hint' => [
        'direction_bucket' => $direction,
        'distance_km' => $distanceKm,
        'store_base_id' => $rowBase['id'],
      ],
    ];

    if ((string)$filters['direction_bucket'] !== '' && $item['direction_bucket'] !== (string)$filters['direction_bucket']) {
      continue;
    }

    $items[] = $item;
    $summary['total']++;
    if ($item['has_coords']) {
      $summary['mappable']++;
    } else {
      $summary['without_coords']++;
    }
    $summary[$status] = (int)($summary[$status] ?? 0) + 1;
    if ($item['driver_user_id'] === null) {
      $summary['unassigned']++;
    } else {
      $driverKey = (string)$item['driver_name'];
      if ($driverKey !== '') {
        $summary['by_driver'][$driverKey] = (int)($summary['by_driver'][$driverKey] ?? 0) + 1;
      }
    }
    $summary['by_direction'][$item['direction_bucket']] = (int)($summary['by_direction'][$item['direction_bucket']] ?? 0) + 1;
  }

  $directionSort = transport_map_direction_options();
  uksort($summary['by_direction'], static function (string $a, string $b) use ($directionSort): int {
    $posA = array_search($a, $directionSort, true);
    $posB = array_search($b, $directionSort, true);
    $posA = ($posA === false) ? 999 : $posA;
    $posB = ($posB === false) ? 999 : $posB;
    if ($posA === $posB) {
      return strcmp($a, $b);
    }
    return $posA <=> $posB;
  });
  arsort($summary['by_driver']);

  return [
    'filters' => $filters,
    'base' => $base,
    'bases' => array_values($baseContexts),
    'summary' => $summary,
    'items' => $items,
    'vehicles' => $vehicles,
  ];
}
