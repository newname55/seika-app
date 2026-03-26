<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/service_transport.php';

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

function transport_map_table_exists(PDO $pdo, string $tableName): bool {
  try {
    $st = $pdo->prepare("SHOW TABLES LIKE ?");
    $st->execute([$tableName]);
    return (bool)$st->fetchColumn();
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
  $storeId = transport_resolve_store_id($pdo, (int)($source['store_id'] ?? 0));
  $storeRow = transport_map_fetch_store_row($pdo, $storeId);
  $defaultBusinessDate = transport_map_default_business_date($storeRow);

  $driverUserId = (int)($source['driver_user_id'] ?? 0);
  if ($driverUserId < 0) {
    $driverUserId = 0;
  }

  return [
    'store_id' => $storeId,
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

function transport_map_geocode_address(string $address): array {
  return transport_google_geocode($address);
}

function transport_map_fetch_rows(PDO $pdo, array $filters): array {
  if (!transport_map_table_exists($pdo, 'transport_assignments')) {
    throw new RuntimeException('transport_assignments テーブルが未作成です。先にSQLを適用してください。');
  }

  $where = [
    'ta.store_id = :store_id',
    'ta.business_date = :business_date',
  ];
  $params = [
    ':store_id' => (int)$filters['store_id'],
    ':business_date' => (string)$filters['business_date'],
  ];

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
      COALESCE(NULLIF(TRIM(du.display_name), ''), NULLIF(TRIM(du.login_id), ''), '') AS driver_name
    FROM transport_assignments ta
    LEFT JOIN users cu
      ON cu.id = ta.cast_id
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
  $base = transport_map_fetch_base_context($pdo, (int)$filters['store_id']);
  $rows = transport_map_fetch_rows($pdo, $filters);
  $canViewFullAddress = transport_map_can_view_full_address();

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
    $pickupLat = ($row['pickup_lat'] ?? null) !== null ? (float)$row['pickup_lat'] : null;
    $pickupLng = ($row['pickup_lng'] ?? null) !== null ? (float)$row['pickup_lng'] : null;
    $direction = trim((string)($row['direction_bucket'] ?? ''));
    if ($direction === '') {
      $direction = transport_map_compute_direction($base['lat'], $base['lng'], $pickupLat, $pickupLng, trim((string)($row['area_name'] ?? '')));
    }
    if (!in_array($direction, transport_map_direction_options(), true)) {
      $direction = $direction !== '' ? $direction : '未分類';
    }

    $distanceKm = null;
    if ($base['lat'] !== null && $base['lng'] !== null && $pickupLat !== null && $pickupLng !== null) {
      $distanceKm = transport_map_round_distance(transport_haversine_km((float)$base['lat'], (float)$base['lng'], $pickupLat, $pickupLng));
    }

    $status = (string)($row['status'] ?? 'pending');
    if (!array_key_exists($status, transport_map_status_definitions())) {
      $status = 'pending';
    }

    $item = [
      'id' => (int)($row['id'] ?? 0),
      'cast_id' => (int)($row['cast_id'] ?? 0),
      'cast_name' => (string)($row['cast_name'] ?? ''),
      'display_name' => trim((string)($row['pickup_name'] ?? '')) !== '' ? (string)$row['pickup_name'] : (string)($row['cast_name'] ?? ''),
      'store_id' => (int)($row['store_id'] ?? 0),
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
      'route_hint' => [
        'direction_bucket' => $direction,
        'distance_km' => $distanceKm,
        'store_base_id' => $base['id'],
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
    'summary' => $summary,
    'items' => $items,
  ];
}
