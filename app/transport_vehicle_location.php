<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

function transport_vehicle_location_table_exists(PDO $pdo, string $tableName): bool {
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

function transport_vehicle_allowed_stores(PDO $pdo, int $userId): array {
  if ($userId <= 0) {
    return [];
  }
  if (is_role('super_user')) {
    $st = $pdo->query("SELECT id, name FROM stores WHERE is_active = 1 ORDER BY id ASC");
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  $st = $pdo->prepare("
    SELECT DISTINCT s.id, s.name
    FROM user_roles ur
    JOIN stores s
      ON s.id = ur.store_id
     AND s.is_active = 1
    WHERE ur.user_id = ?
      AND ur.store_id IS NOT NULL
    ORDER BY s.id ASC
  ");
  $st->execute([$userId]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function transport_vehicle_resolve_store_id(PDO $pdo, int $userId, ?int $requestedStoreId = null): int {
  $stores = transport_vehicle_allowed_stores($pdo, $userId);
  if ($stores === []) {
    throw new RuntimeException('位置送信できる店舗がありません');
  }

  $allowed = [];
  foreach ($stores as $store) {
    $sid = (int)($store['id'] ?? 0);
    if ($sid > 0) {
      $allowed[$sid] = true;
    }
  }

  $requested = (int)($requestedStoreId ?? 0);
  if ($requested > 0 && isset($allowed[$requested])) {
    return $requested;
  }

  return (int)($stores[0]['id'] ?? 0);
}

function transport_vehicle_normalize_float($value, string $label, ?float $min = null, ?float $max = null): float {
  if ($value === null || $value === '') {
    throw new RuntimeException($label . 'が未指定です');
  }
  if (!is_numeric($value)) {
    throw new RuntimeException($label . 'が不正です');
  }
  $num = (float)$value;
  if ($min !== null && $num < $min) {
    throw new RuntimeException($label . 'が範囲外です');
  }
  if ($max !== null && $num > $max) {
    throw new RuntimeException($label . 'が範囲外です');
  }
  return $num;
}

function transport_vehicle_normalize_nullable_float($value, ?float $min = null, ?float $max = null): ?float {
  if ($value === null || $value === '') {
    return null;
  }
  if (!is_numeric($value)) {
    return null;
  }
  $num = (float)$value;
  if ($min !== null && $num < $min) {
    return null;
  }
  if ($max !== null && $num > $max) {
    return null;
  }
  return $num;
}

function transport_vehicle_normalize_recorded_at(?string $value): string {
  $value = trim((string)$value);
  if ($value === '') {
    return jst_now()->format('Y-m-d H:i:s');
  }
  try {
    $timezone = new DateTimeZone('Asia/Tokyo');
    $dt = new DateTime($value, $timezone);
    $dt->setTimezone($timezone);
    return $dt->format('Y-m-d H:i:s');
  } catch (Throwable $e) {
    return jst_now()->format('Y-m-d H:i:s');
  }
}

function transport_vehicle_normalize_vehicle_label(?string $value): string {
  $value = trim((string)$value);
  return mb_substr($value, 0, 64);
}

function transport_vehicle_save_position(PDO $pdo, array $source, int $userId): array {
  if (!transport_vehicle_location_table_exists($pdo, 'transport_vehicle_positions')) {
    throw new RuntimeException('transport_vehicle_positions テーブルが未作成です。先にSQLを適用してください。');
  }

  $storeId = transport_vehicle_resolve_store_id($pdo, $userId, (int)($source['store_id'] ?? 0));
  $lat = round(transport_vehicle_normalize_float($source['lat'] ?? null, '緯度', -90.0, 90.0), 7);
  $lng = round(transport_vehicle_normalize_float($source['lng'] ?? null, '経度', -180.0, 180.0), 7);
  $accuracy = transport_vehicle_normalize_nullable_float($source['accuracy_m'] ?? null, 0.0, 10000.0);
  $heading = transport_vehicle_normalize_nullable_float($source['heading_deg'] ?? null, 0.0, 360.0);
  $speedKmh = transport_vehicle_normalize_nullable_float($source['speed_kmh'] ?? null, 0.0, 300.0);
  $batteryLevel = transport_vehicle_normalize_nullable_float($source['battery_level'] ?? null, 0.0, 100.0);
  $vehicleLabel = transport_vehicle_normalize_vehicle_label((string)($source['vehicle_label'] ?? ''));
  $recordedAt = transport_vehicle_normalize_recorded_at((string)($source['recorded_at'] ?? ''));
  $driverUserId = $userId;
  $sourceType = trim((string)($source['source'] ?? 'wbss_browser'));
  if ($sourceType === '') {
    $sourceType = 'wbss_browser';
  }

  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare("
      INSERT INTO transport_vehicle_positions (
        store_id,
        driver_user_id,
        vehicle_label,
        lat,
        lng,
        accuracy_m,
        heading_deg,
        speed_kmh,
        recorded_at,
        source,
        battery_level
      ) VALUES (
        :store_id,
        :driver_user_id,
        :vehicle_label,
        :lat,
        :lng,
        :accuracy_m,
        :heading_deg,
        :speed_kmh,
        :recorded_at,
        :source,
        :battery_level
      )
    ");
    $st->execute([
      ':store_id' => $storeId,
      ':driver_user_id' => $driverUserId,
      ':vehicle_label' => $vehicleLabel !== '' ? $vehicleLabel : null,
      ':lat' => $lat,
      ':lng' => $lng,
      ':accuracy_m' => $accuracy,
      ':heading_deg' => $heading,
      ':speed_kmh' => $speedKmh,
      ':recorded_at' => $recordedAt,
      ':source' => $sourceType,
      ':battery_level' => $batteryLevel !== null ? (int)round($batteryLevel) : null,
    ]);

    if (transport_vehicle_location_table_exists($pdo, 'transport_vehicle_position_latest')) {
      $up = $pdo->prepare("
        INSERT INTO transport_vehicle_position_latest (
          store_id,
          driver_user_id,
          vehicle_label,
          lat,
          lng,
          accuracy_m,
          heading_deg,
          speed_kmh,
          recorded_at,
          source,
          battery_level
        ) VALUES (
          :store_id,
          :driver_user_id,
          :vehicle_label,
          :lat,
          :lng,
          :accuracy_m,
          :heading_deg,
          :speed_kmh,
          :recorded_at,
          :source,
          :battery_level
        )
        ON DUPLICATE KEY UPDATE
          vehicle_label = VALUES(vehicle_label),
          lat = VALUES(lat),
          lng = VALUES(lng),
          accuracy_m = VALUES(accuracy_m),
          heading_deg = VALUES(heading_deg),
          speed_kmh = VALUES(speed_kmh),
          recorded_at = VALUES(recorded_at),
          source = VALUES(source),
          battery_level = VALUES(battery_level),
          updated_at = CURRENT_TIMESTAMP
      ");
      $up->execute([
        ':store_id' => $storeId,
        ':driver_user_id' => $driverUserId,
        ':vehicle_label' => $vehicleLabel !== '' ? $vehicleLabel : null,
        ':lat' => $lat,
        ':lng' => $lng,
        ':accuracy_m' => $accuracy,
        ':heading_deg' => $heading,
        ':speed_kmh' => $speedKmh,
        ':recorded_at' => $recordedAt,
        ':source' => $sourceType,
        ':battery_level' => $batteryLevel !== null ? (int)round($batteryLevel) : null,
      ]);
    }

    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }

  return [
    'store_id' => $storeId,
    'driver_user_id' => $driverUserId,
    'vehicle_label' => $vehicleLabel,
    'lat' => $lat,
    'lng' => $lng,
    'recorded_at' => $recordedAt,
  ];
}

function transport_vehicle_fetch_latest(PDO $pdo, int $storeId, int $staleSeconds = 180): array {
  if (transport_vehicle_location_table_exists($pdo, 'transport_vehicle_position_latest')) {
    $sql = "
      SELECT
        l.store_id,
        l.driver_user_id,
        l.vehicle_label,
        l.lat,
        l.lng,
        l.accuracy_m,
        l.heading_deg,
        l.speed_kmh,
        l.recorded_at,
        l.source,
        l.battery_level,
        COALESCE(NULLIF(TRIM(u.display_name), ''), NULLIF(TRIM(u.login_id), ''), CONCAT('user#', u.id)) AS driver_name
      FROM transport_vehicle_position_latest l
      LEFT JOIN users u
        ON u.id = l.driver_user_id
      WHERE l.store_id = ?
      ORDER BY l.recorded_at DESC, l.driver_user_id ASC
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$storeId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } elseif (transport_vehicle_location_table_exists($pdo, 'transport_vehicle_positions')) {
    $sql = "
      SELECT
        p.store_id,
        p.driver_user_id,
        p.vehicle_label,
        p.lat,
        p.lng,
        p.accuracy_m,
        p.heading_deg,
        p.speed_kmh,
        p.recorded_at,
        p.source,
        p.battery_level,
        COALESCE(NULLIF(TRIM(u.display_name), ''), NULLIF(TRIM(u.login_id), ''), CONCAT('user#', u.id)) AS driver_name
      FROM transport_vehicle_positions p
      JOIN (
        SELECT driver_user_id, MAX(recorded_at) AS latest_recorded_at
        FROM transport_vehicle_positions
        WHERE store_id = ?
        GROUP BY driver_user_id
      ) latest
        ON latest.driver_user_id = p.driver_user_id
       AND latest.latest_recorded_at = p.recorded_at
      LEFT JOIN users u
        ON u.id = p.driver_user_id
      WHERE p.store_id = ?
      ORDER BY p.recorded_at DESC, p.driver_user_id ASC
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$storeId, $storeId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } else {
    return [];
  }

  $now = jst_now();
  $vehicles = [];
  foreach ($rows as $row) {
    $recordedAt = (string)($row['recorded_at'] ?? '');
    $isStale = true;
    if ($recordedAt !== '') {
      try {
        $recordedAtDt = new DateTime($recordedAt, new DateTimeZone('Asia/Tokyo'));
        $isStale = (($now->getTimestamp() - $recordedAtDt->getTimestamp()) > $staleSeconds);
      } catch (Throwable $e) {
        $isStale = true;
      }
    }

    $vehicles[] = [
      'store_id' => (int)($row['store_id'] ?? 0),
      'driver_user_id' => (int)($row['driver_user_id'] ?? 0),
      'driver_name' => (string)($row['driver_name'] ?? ''),
      'vehicle_label' => (string)($row['vehicle_label'] ?? ''),
      'lat' => ($row['lat'] ?? null) !== null ? (float)$row['lat'] : null,
      'lng' => ($row['lng'] ?? null) !== null ? (float)$row['lng'] : null,
      'accuracy_m' => ($row['accuracy_m'] ?? null) !== null ? (float)$row['accuracy_m'] : null,
      'heading_deg' => ($row['heading_deg'] ?? null) !== null ? (float)$row['heading_deg'] : null,
      'speed_kmh' => ($row['speed_kmh'] ?? null) !== null ? (float)$row['speed_kmh'] : null,
      'recorded_at' => $recordedAt,
      'source' => (string)($row['source'] ?? ''),
      'battery_level' => ($row['battery_level'] ?? null) !== null ? (int)$row['battery_level'] : null,
      'is_stale' => $isStale,
    ];
  }

  return $vehicles;
}
