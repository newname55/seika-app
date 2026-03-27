<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../transport_map.php';
require_once __DIR__ . '/../service_transport.php';
require_once __DIR__ . '/route_optimizer.php';

function transport_assign_service_target_rows(PDO $pdo, array $filters): array {
  if (!transport_map_table_exists($pdo, 'transport_assignments')) {
    return [];
  }

  $storeIds = array_values(array_filter(array_map('intval', (array)($filters['store_ids'] ?? [])), static fn(int $id): bool => $id > 0));
  if ($storeIds === []) {
    return [];
  }

  $params = [
    ':business_date' => (string)$filters['business_date'],
  ];
  $where = [
    "ta.business_date = :business_date",
    "ta.status = 'pending'",
    "ta.driver_user_id IS NULL",
    "ta.pickup_lat IS NOT NULL",
    "ta.pickup_lng IS NOT NULL",
  ];

  $ph = [];
  foreach ($storeIds as $index => $storeId) {
    $key = ':store_id_' . $index;
    $ph[] = $key;
    $params[$key] = $storeId;
  }
  $where[] = 'ta.store_id IN (' . implode(', ', $ph) . ')';

  $sql = "
    SELECT
      ta.id,
      ta.store_id,
      ta.business_date,
      ta.cast_id,
      ta.pickup_name,
      ta.pickup_lat,
      ta.pickup_lng,
      ta.direction_bucket,
      ta.area_name,
      ta.sort_order,
      COALESCE(NULLIF(TRIM(cu.display_name), ''), NULLIF(TRIM(cu.login_id), ''), CONCAT('cast#', ta.cast_id)) AS cast_name
    FROM transport_assignments ta
    LEFT JOIN users cu
      ON cu.id = ta.cast_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY ta.store_id ASC, ta.pickup_time_from ASC, ta.id ASC
  ";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function transport_assign_service_driver_loads(PDO $pdo, int $storeId, string $businessDate): array {
  $st = $pdo->prepare("
    SELECT
      ta.driver_user_id,
      COUNT(*) AS assigned_count,
      MAX(ta.direction_bucket) AS last_direction
    FROM transport_assignments ta
    WHERE ta.store_id = ?
      AND ta.business_date = ?
      AND ta.driver_user_id IS NOT NULL
      AND ta.status IN ('assigned', 'in_progress', 'done')
    GROUP BY ta.driver_user_id
  ");
  $st->execute([$storeId, $businessDate]);
  $loads = [];
  foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
    $driverId = (int)($row['driver_user_id'] ?? 0);
    if ($driverId <= 0) {
      continue;
    }
    $loads[$driverId] = [
      'count' => (int)($row['assigned_count'] ?? 0),
      'last_direction' => (string)($row['last_direction'] ?? ''),
    ];
  }
  return $loads;
}

function transport_assign_service_cluster_rows(array $rows, array $baseContexts): array {
  $clusters = [];
  $visited = [];
  $indexByStore = [];

  foreach ($rows as $index => $row) {
    $indexByStore[(int)$row['store_id']][] = $index;
  }

  foreach ($rows as $index => $row) {
    if (isset($visited[$index])) {
      continue;
    }
    $storeId = (int)($row['store_id'] ?? 0);
    $base = $baseContexts[$storeId] ?? ['lat' => null, 'lng' => null];
    $direction = trim((string)($row['direction_bucket'] ?? ''));
    if ($direction === '') {
      $direction = transport_map_compute_direction(
        ($base['lat'] ?? null) !== null ? (float)$base['lat'] : null,
        ($base['lng'] ?? null) !== null ? (float)$base['lng'] : null,
        ($row['pickup_lat'] ?? null) !== null ? (float)$row['pickup_lat'] : null,
        ($row['pickup_lng'] ?? null) !== null ? (float)$row['pickup_lng'] : null,
        trim((string)($row['area_name'] ?? ''))
      );
    }
    if ($direction === '') {
      $direction = '未分類';
    }

    $queue = [$index];
    $clusterIndexes = [];
    while ($queue !== []) {
      $currentIndex = array_shift($queue);
      if (isset($visited[$currentIndex])) {
        continue;
      }
      $visited[$currentIndex] = true;
      $clusterIndexes[] = $currentIndex;
      $current = $rows[$currentIndex];

      foreach ($indexByStore[$storeId] ?? [] as $candidateIndex) {
        if (isset($visited[$candidateIndex])) {
          continue;
        }
        $candidate = $rows[$candidateIndex];
        $candidateDirection = trim((string)($candidate['direction_bucket'] ?? ''));
        if ($candidateDirection === '') {
          $candidateDirection = transport_map_compute_direction(
            ($base['lat'] ?? null) !== null ? (float)$base['lat'] : null,
            ($base['lng'] ?? null) !== null ? (float)$base['lng'] : null,
            ($candidate['pickup_lat'] ?? null) !== null ? (float)$candidate['pickup_lat'] : null,
            ($candidate['pickup_lng'] ?? null) !== null ? (float)$candidate['pickup_lng'] : null,
            trim((string)($candidate['area_name'] ?? ''))
          );
        }
        if ($candidateDirection !== $direction) {
          continue;
        }
        $distance = transport_haversine_km(
          (float)$current['pickup_lat'],
          (float)$current['pickup_lng'],
          (float)$candidate['pickup_lat'],
          (float)$candidate['pickup_lng']
        );
        if ($distance <= 2.0) {
          $queue[] = $candidateIndex;
        }
      }
    }

    $clusters[] = [
      'store_id' => $storeId,
      'direction_bucket' => $direction,
      'rows' => array_map(static fn(int $clusterIndex): array => $rows[$clusterIndex], $clusterIndexes),
    ];
  }

  return $clusters;
}

function transport_assign_service_pick_driver(array $cluster, array $drivers, array $base, array &$loads, array &$driverAssignments): array {
  $storeId = (int)$cluster['store_id'];
  $direction = (string)$cluster['direction_bucket'];
  $clusterSize = count($cluster['rows']);
  $best = null;
  $bestScore = -INF;
  $bestReason = '候補ドライバーなし';

  foreach ($drivers as $driver) {
    $driverId = (int)($driver['id'] ?? 0);
    if ($driverId <= 0) {
      continue;
    }
    $load = (int)($loads[$driverId]['count'] ?? 0);
    $sameDirection = (string)($loads[$driverId]['last_direction'] ?? '') === $direction ? 1 : 0;
    $sameCluster = 0;
    $distancePenalty = 0.0;

    foreach ($driverAssignments[$driverId] ?? [] as $assigned) {
      if ((string)($assigned['direction_bucket'] ?? '') === $direction) {
        $sameCluster++;
      }
      $distancePenalty += transport_haversine_km(
        (float)$assigned['pickup_lat'],
        (float)$assigned['pickup_lng'],
        (float)$cluster['rows'][0]['pickup_lat'],
        (float)$cluster['rows'][0]['pickup_lng']
      );
    }

    $baseDistance = 0.0;
    if (($base['lat'] ?? null) !== null && ($base['lng'] ?? null) !== null) {
      $baseDistance = transport_haversine_km(
        (float)$base['lat'],
        (float)$base['lng'],
        (float)$cluster['rows'][0]['pickup_lat'],
        (float)$cluster['rows'][0]['pickup_lng']
      );
    }

    $score = ($sameDirection * 50)
      + ($sameCluster > 0 ? 40 : 0)
      + max(0, 30 - (int)round($baseDistance * 6))
      - (int)round($distancePenalty * 8)
      - max(0, ($load + $clusterSize - 4) * 15);

    if ($score > $bestScore) {
      $bestScore = $score;
      $best = $driver;
      $bestReason = '方面一致:' . ($sameDirection ? '有' : '無')
        . ' / 近距離:' . transport_map_round_distance($baseDistance)
        . 'km / 担当件数:' . $load . '件';
    }
  }

  if ($best) {
    $driverId = (int)$best['id'];
    $loads[$driverId]['count'] = (int)($loads[$driverId]['count'] ?? 0) + $clusterSize;
    $loads[$driverId]['last_direction'] = $direction;
    foreach ($cluster['rows'] as $row) {
      $driverAssignments[$driverId][] = $row;
    }
  }

  return [
    'driver' => $best,
    'score' => $bestScore > -INF ? (int)round($bestScore) : 0,
    'reason' => $bestReason,
  ];
}

function transport_assign_service_generate(PDO $pdo, array $filters): array {
  $rows = transport_assign_service_target_rows($pdo, $filters);
  if ($rows === []) {
    return [];
  }

  $storeIds = array_values(array_filter(array_map('intval', (array)($filters['store_ids'] ?? [])), static fn(int $id): bool => $id > 0));
  $baseContexts = transport_map_fetch_base_contexts($pdo, $storeIds);
  $clusters = transport_assign_service_cluster_rows($rows, $baseContexts);
  $proposals = [];
  $loadsByStore = [];
  $driverAssignmentsByStore = [];

  foreach ($clusters as $clusterIndex => $cluster) {
    $storeId = (int)$cluster['store_id'];
    $drivers = transport_map_fetch_driver_options($pdo, $storeId);
    if (!isset($loadsByStore[$storeId])) {
      $loadsByStore[$storeId] = transport_assign_service_driver_loads($pdo, $storeId, (string)$filters['business_date']);
      $driverAssignmentsByStore[$storeId] = [];
    }
    $picked = transport_assign_service_pick_driver(
      $cluster,
      $drivers,
      $baseContexts[$storeId] ?? [],
      $loadsByStore[$storeId],
      $driverAssignmentsByStore[$storeId]
    );
    $driverId = (int)($picked['driver']['id'] ?? 0);
    $driverName = (string)($picked['driver']['name'] ?? '');
    $groupId = 'S' . $storeId . '-' . mb_substr((string)$cluster['direction_bucket'], 0, 1) . '-' . str_pad((string)($clusterIndex + 1), 2, '0', STR_PAD_LEFT);
    $routeRows = $driverId > 0
      ? transport_route_optimizer_nearest_neighbor($baseContexts[$storeId] ?? [], $cluster['rows'])
      : [];
    $orderByRequestId = [];
    foreach ($routeRows as $routeRow) {
      $orderByRequestId[(int)$routeRow['request_id']] = $routeRow;
    }

    foreach ($cluster['rows'] as $row) {
      $route = $orderByRequestId[(int)$row['id']] ?? ['order' => null, 'eta_minutes' => null];
      $proposals[] = [
        'request_id' => (int)$row['id'],
        'store_id' => $storeId,
        'suggested_driver_id' => $driverId > 0 ? $driverId : null,
        'suggested_driver_name' => $driverName !== '' ? $driverName : null,
        'group_id' => $groupId,
        'suggested_order' => ($route['order'] ?? null) !== null ? (int)$route['order'] : null,
        'eta_minutes' => ($route['eta_minutes'] ?? null) !== null ? (int)$route['eta_minutes'] : null,
        'score' => (int)$picked['score'],
        'reason' => (string)$picked['reason'],
      ];
    }
  }

  usort($proposals, static function (array $a, array $b): int {
    if ((int)($a['store_id'] ?? 0) !== (int)($b['store_id'] ?? 0)) {
      return ((int)$a['store_id'] <=> (int)$b['store_id']);
    }
    return ((int)($a['request_id'] ?? 0) <=> (int)($b['request_id'] ?? 0));
  });

  error_log('[transport_auto_assign] generated=' . count($proposals) . ' store_scope=' . (string)($filters['store_scope'] ?? 'single'));
  return $proposals;
}
