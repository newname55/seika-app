<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../service_transport.php';
require_once __DIR__ . '/../transport_map.php';

function transport_route_optimizer_nearest_neighbor(array $base, array $requests): array {
  $remaining = array_values(array_filter($requests, static function (array $request): bool {
    return ($request['pickup_lat'] ?? null) !== null && ($request['pickup_lng'] ?? null) !== null;
  }));
  if ($remaining === []) {
    return [];
  }

  $currentLat = ($base['lat'] ?? null) !== null ? (float)$base['lat'] : (float)$remaining[0]['pickup_lat'];
  $currentLng = ($base['lng'] ?? null) !== null ? (float)$base['lng'] : (float)$remaining[0]['pickup_lng'];
  $ordered = [];
  $elapsedMinutes = 0;

  while ($remaining !== []) {
    $bestIndex = 0;
    $bestDistance = null;
    foreach ($remaining as $index => $request) {
      $distance = transport_haversine_km($currentLat, $currentLng, (float)$request['pickup_lat'], (float)$request['pickup_lng']);
      if ($bestDistance === null || $distance < $bestDistance) {
        $bestDistance = $distance;
        $bestIndex = $index;
      }
    }

    $selected = $remaining[$bestIndex];
    array_splice($remaining, $bestIndex, 1);
    $travelMinutes = (int)round((($bestDistance ?? 0.0) / 25.0) * 60.0);
    $elapsedMinutes += max(3, $travelMinutes);
    $ordered[] = [
      'request_id' => (int)($selected['id'] ?? 0),
      'order' => count($ordered) + 1,
      'eta_minutes' => $elapsedMinutes,
    ];
    $currentLat = (float)$selected['pickup_lat'];
    $currentLng = (float)$selected['pickup_lng'];
  }

  return $ordered;
}

