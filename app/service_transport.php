<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/store_access.php';
require_once __DIR__ . '/repo_casts.php';

function transport_normalize_text(?string $value, int $max = 255): string {
  $value = trim((string)$value);
  if ($value === '') {
    return '';
  }
  return mb_substr($value, 0, $max);
}

function transport_normalize_nullable_text(?string $value, int $max = 255): ?string {
  $value = transport_normalize_text($value, $max);
  return $value === '' ? null : $value;
}

function transport_normalize_latlng(?string $value): ?float {
  $value = trim((string)$value);
  if ($value === '') {
    return null;
  }
  if (!is_numeric($value)) {
    throw new RuntimeException('緯度経度は数値で入力してください');
  }
  return round((float)$value, 7);
}

function transport_normalize_positive_int($value, int $default, int $min, int $max): int {
  $num = is_numeric($value) ? (int)$value : $default;
  if ($num < $min) {
    $num = $min;
  }
  if ($num > $max) {
    $num = $max;
  }
  return $num;
}

function transport_profile_has_secondary_fields(PDO $pdo): bool {
  static $cache = null;
  if ($cache !== null) {
    return $cache;
  }

  try {
    $sql = "
      SELECT COUNT(*)
      FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'cast_transport_profiles'
        AND COLUMN_NAME IN (
          'pickup_sub_zip',
          'pickup_sub_prefecture',
          'pickup_sub_city',
          'pickup_sub_address1',
          'pickup_sub_address2',
          'pickup_sub_building',
          'pickup_sub_note',
          'pickup_sub_lat',
          'pickup_sub_lng',
          'pickup_sub_geocoded_at'
        )
    ";
    $st = $pdo->query($sql);
    $cache = ((int)$st->fetchColumn() >= 10);
  } catch (Throwable $e) {
    $cache = false;
  }

  return $cache;
}

function transport_profile_has_pickup_target_field(PDO $pdo): bool {
  static $cache = null;
  if ($cache !== null) {
    return $cache;
  }

  try {
    $st = $pdo->prepare("
      SELECT COUNT(*)
      FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'cast_transport_profiles'
        AND COLUMN_NAME = 'pickup_target'
    ");
    $st->execute();
    $cache = ((int)$st->fetchColumn() > 0);
  } catch (Throwable $e) {
    $cache = false;
  }

  return $cache;
}

function transport_pickup_target_label(string $pickupTarget, bool $forPickup = false): string {
  return match ($pickupTarget) {
    'secondary' => $forPickup ? 'サブ迎え' : 'サブ',
    'self' => '自走',
    default => $forPickup ? '基本迎え' : '基本',
  };
}

function transport_pickup_target_requires_pickup(string $pickupTarget, int $pickupEnabled = 1): bool {
  if ($pickupEnabled !== 1) {
    return false;
  }
  return $pickupTarget !== 'self';
}

function transport_store_base_has_dispatch_origin_field(PDO $pdo): bool {
  static $cache = null;
  if ($cache !== null) {
    return $cache;
  }

  try {
    $st = $pdo->prepare("
      SELECT COUNT(*)
      FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'store_transport_bases'
        AND COLUMN_NAME = 'is_dispatch_origin'
    ");
    $st->execute();
    $cache = ((int)$st->fetchColumn() > 0);
  } catch (Throwable $e) {
    $cache = false;
  }

  return $cache;
}

function transport_fetch_profile(PDO $pdo, int $storeId, int $userId): array {
  $row = [
    'pickup_zip' => '',
    'pickup_prefecture' => '',
    'pickup_city' => '',
    'pickup_address1' => '',
    'pickup_address2' => '',
    'pickup_building' => '',
    'pickup_note' => '',
    'pickup_lat' => '',
    'pickup_lng' => '',
    'pickup_enabled' => 1,
    'privacy_level' => 'manager_only',
    'pickup_target' => 'primary',
    'pickup_sub_zip' => '',
    'pickup_sub_prefecture' => '',
    'pickup_sub_city' => '',
    'pickup_sub_address1' => '',
    'pickup_sub_address2' => '',
    'pickup_sub_building' => '',
    'pickup_sub_note' => '',
    'pickup_sub_lat' => '',
    'pickup_sub_lng' => '',
  ];

  if ($storeId <= 0 || $userId <= 0) {
    return $row;
  }

  $subSelect = '';
  if (transport_profile_has_secondary_fields($pdo)) {
    $subSelect = ",
      pickup_sub_zip,
      pickup_sub_prefecture,
      pickup_sub_city,
      pickup_sub_address1,
      pickup_sub_address2,
      pickup_sub_building,
      pickup_sub_note,
      pickup_sub_lat,
      pickup_sub_lng
    ";
  }

  $targetSelect = transport_profile_has_pickup_target_field($pdo)
    ? ", pickup_target"
    : "";

  $st = $pdo->prepare("
    SELECT
      pickup_zip,
      pickup_prefecture,
      pickup_city,
      pickup_address1,
      pickup_address2,
      pickup_building,
      pickup_note,
      pickup_lat,
      pickup_lng,
      pickup_enabled,
      privacy_level
      {$targetSelect}
      {$subSelect}
    FROM cast_transport_profiles
    WHERE store_id = ?
      AND user_id = ?
    LIMIT 1
  ");
  $st->execute([$storeId, $userId]);
  $profile = $st->fetch(PDO::FETCH_ASSOC);
  if (!$profile) {
    return $row;
  }

  foreach ($row as $key => $default) {
    if (!array_key_exists($key, $profile)) {
      continue;
    }
    $value = $profile[$key];
    $row[$key] = $value === null ? '' : (string)$value;
  }
  $row['pickup_enabled'] = (int)($profile['pickup_enabled'] ?? 1);
  $row['privacy_level'] = (string)($profile['privacy_level'] ?? 'manager_only');
  $row['pickup_target'] = (string)($profile['pickup_target'] ?? 'primary');

  return $row;
}

function transport_build_unavailable_reasons(array $casts, array $base, ?array $dispatchBase = null): array {
  $reasons = [];
  if (($base['lat'] ?? null) === null || ($base['lng'] ?? null) === null) {
    $reasons[] = '店舗拠点の座標が未登録です';
  }
  if ($dispatchBase !== null && (($dispatchBase['lat'] ?? null) === null || ($dispatchBase['lng'] ?? null) === null)) {
    $reasons[] = '出発拠点の座標が未登録です';
  }

  $pickupEnabledCount = 0;
  $coordReadyCount = 0;
  foreach ($casts as $cast) {
    if ((int)($cast['pickup_enabled'] ?? 1) !== 1) {
      continue;
    }
    if (array_key_exists('requires_pickup', $cast) && !$cast['requires_pickup']) {
      continue;
    }
    $pickupEnabledCount++;
    if (!empty($cast['has_coords'])) {
      $coordReadyCount++;
    }
  }

  if ($pickupEnabledCount === 0) {
    $reasons[] = '送迎対象のキャストがいません';
  } elseif ($coordReadyCount === 0) {
    $reasons[] = '送迎対象キャストの座標が未登録です';
  }

  return $reasons;
}

function transport_join_address_parts(array $parts): string {
  $values = [];
  foreach ($parts as $part) {
    $text = trim((string)$part);
    if ($text !== '') {
      $values[] = $text;
    }
  }
  return trim(implode(' ', $values));
}

function transport_google_geocode(string $address): array {
  $address = trim($address);
  if ($address === '') {
    throw new RuntimeException('ジオコーディング対象の住所が空です');
  }

  $apiKey = trim((string)conf('GOOGLE_MAPS_GEOCODING_API_KEY'));
  if ($apiKey === '') {
    throw new RuntimeException('GOOGLE_MAPS_GEOCODING_API_KEY が未設定です');
  }

  $url = 'https://maps.googleapis.com/maps/api/geocode/json?'
    . http_build_query([
      'address' => $address,
      'key' => $apiKey,
      'language' => 'ja',
      'region' => 'jp',
    ], '', '&', PHP_QUERY_RFC3986);

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER => [
      'Accept: application/json',
    ],
  ]);
  $body = curl_exec($ch);
  $curlErr = curl_error($ch);
  $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if (!is_string($body) || $body === '') {
    $detail = $curlErr !== '' ? $curlErr : 'empty response';
    throw new RuntimeException('Google Geocoding API から応答がありません: ' . $detail);
  }
  if ($httpCode >= 300) {
    throw new RuntimeException('Google Geocoding API がエラーを返しました: HTTP ' . $httpCode);
  }

  $json = json_decode($body, true);
  if (!is_array($json)) {
    throw new RuntimeException('Google Geocoding API の応答を解釈できませんでした');
  }

  $status = (string)($json['status'] ?? '');
  if ($status !== 'OK') {
    $errorMessage = trim((string)($json['error_message'] ?? ''));
    if ($status === 'ZERO_RESULTS') {
      throw new RuntimeException('住所から座標を取得できませんでした。住所をもう少し詳しく入力してください');
    }
    throw new RuntimeException('Google Geocoding API エラー: ' . ($errorMessage !== '' ? $errorMessage : $status));
  }

  $results = $json['results'] ?? null;
  if (!is_array($results) || !isset($results[0]) || !is_array($results[0])) {
    throw new RuntimeException('Google Geocoding API の結果が空です');
  }

  $location = $results[0]['geometry']['location'] ?? null;
  if (!is_array($location) || !isset($location['lat'], $location['lng'])) {
    throw new RuntimeException('Google Geocoding API の座標情報が不正です');
  }

  return [
    'lat' => round((float)$location['lat'], 7),
    'lng' => round((float)$location['lng'], 7),
    'formatted_address' => (string)($results[0]['formatted_address'] ?? ''),
    'address_components' => is_array($results[0]['address_components'] ?? null) ? $results[0]['address_components'] : [],
  ];
}

function transport_routes_api_key(): string {
  $key = trim((string)conf('GOOGLE_MAPS_ROUTES_API_KEY'));
  if ($key !== '') {
    return $key;
  }
  return trim((string)conf('GOOGLE_MAPS_GEOCODING_API_KEY'));
}

function transport_parse_google_duration_seconds(?string $duration): int {
  $duration = trim((string)$duration);
  if ($duration === '') {
    return 0;
  }
  if (preg_match('/^([0-9]+(?:\.[0-9]+)?)s$/', $duration, $m)) {
    return (int)ceil((float)$m[1]);
  }
  return 0;
}

function transport_google_route_matrix(array $points, array $base, ?array $dispatchBase = null): ?array {
  $apiKey = transport_routes_api_key();
  if ($apiKey === '') {
    return null;
  }

  $n = count($points);
  if ($n === 0) {
    return null;
  }
  if ($n * ($n + 1) > 625) {
    return null;
  }
  if (($base['lat'] ?? null) === null || ($base['lng'] ?? null) === null) {
    return null;
  }
  if (($dispatchBase['lat'] ?? null) === null || ($dispatchBase['lng'] ?? null) === null) {
    return null;
  }

  $origins = [];
  $origins[] = [
    'waypoint' => [
      'location' => [
        'latLng' => [
          'latitude' => (float)$dispatchBase['lat'],
          'longitude' => (float)$dispatchBase['lng'],
        ],
      ],
    ],
  ];
  foreach ($points as $point) {
    $origins[] = [
      'waypoint' => [
        'location' => [
          'latLng' => [
            'latitude' => (float)$point['lat'],
            'longitude' => (float)$point['lng'],
          ],
        ],
      ],
    ];
  }

  $destinations = [];
  foreach ($points as $point) {
    $destinations[] = [
      'waypoint' => [
        'location' => [
          'latLng' => [
            'latitude' => (float)$point['lat'],
            'longitude' => (float)$point['lng'],
          ],
        ],
      ],
    ];
  }
  $destinations[] = [
    'waypoint' => [
      'location' => [
        'latLng' => [
          'latitude' => (float)$base['lat'],
          'longitude' => (float)$base['lng'],
        ],
      ],
    ],
  ];

  $payload = [
    'origins' => $origins,
    'destinations' => $destinations,
    'travelMode' => 'DRIVE',
    'routingPreference' => 'TRAFFIC_UNAWARE',
    'languageCode' => 'ja',
    'units' => 'METRIC',
  ];

  $ch = curl_init('https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'X-Goog-Api-Key: ' . $apiKey,
      'X-Goog-FieldMask: originIndex,destinationIndex,status,condition,distanceMeters,duration',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
  ]);
  $body = curl_exec($ch);
  $curlErr = curl_error($ch);
  $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if (!is_string($body) || $body === '' || $httpCode >= 300 || $curlErr !== '') {
    return null;
  }

  $durationMatrix = array_fill(0, $n, array_fill(0, $n, INF));
  $distanceMatrix = array_fill(0, $n, array_fill(0, $n, INF));
  $fromDispatchDuration = array_fill(0, $n, INF);
  $fromDispatchDistance = array_fill(0, $n, INF);
  $toBaseDuration = array_fill(0, $n, INF);
  $toBaseDistance = array_fill(0, $n, INF);

  $items = [];
  $decoded = json_decode($body, true);
  if (is_array($decoded) && array_is_list($decoded)) {
    $items = $decoded;
  } else {
    $lines = preg_split("/\r\n|\n|\r/", trim($body)) ?: [];
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }
      $row = json_decode($line, true);
      if (is_array($row)) {
        $items[] = $row;
      }
    }
  }

  foreach ($items as $row) {
    $originIndex = (int)($row['originIndex'] ?? -1);
    $destinationIndex = (int)($row['destinationIndex'] ?? -1);
    $statusCode = (string)($row['status']['code'] ?? '');
    if ($originIndex < 0 || $destinationIndex < 0) {
      continue;
    }
    if ($statusCode !== '' && $statusCode !== 'OK') {
      continue;
    }
    $seconds = transport_parse_google_duration_seconds((string)($row['duration'] ?? ''));
    $km = isset($row['distanceMeters']) ? ((float)$row['distanceMeters'] / 1000.0) : 0.0;

    if ($originIndex === 0) {
      if ($destinationIndex < $n) {
        $fromDispatchDuration[$destinationIndex] = $seconds;
        $fromDispatchDistance[$destinationIndex] = $km;
      }
      continue;
    }

    $pointOriginIndex = $originIndex - 1;
    if ($pointOriginIndex < 0 || $pointOriginIndex >= $n) {
      continue;
    }

    if ($destinationIndex < $n) {
      $durationMatrix[$pointOriginIndex][$destinationIndex] = $seconds;
      $distanceMatrix[$pointOriginIndex][$destinationIndex] = $km;
    } elseif ($destinationIndex === $n) {
      $toBaseDuration[$pointOriginIndex] = $seconds;
      $toBaseDistance[$pointOriginIndex] = $km;
    }
  }

  for ($i = 0; $i < $n; $i++) {
    $durationMatrix[$i][$i] = 0.0;
    $distanceMatrix[$i][$i] = 0.0;
  }

  foreach ($fromDispatchDuration as $seconds) {
    if (!is_finite($seconds)) {
      return null;
    }
  }
  foreach ($toBaseDuration as $idx => $seconds) {
    if (!is_finite($seconds)) {
      return null;
    }
  }
  foreach ($durationMatrix as $row) {
    foreach ($row as $value) {
      if (!is_finite($value)) {
        return null;
      }
    }
  }

  return [
    'duration_matrix' => $durationMatrix,
    'distance_matrix' => $distanceMatrix,
    'from_dispatch_duration' => $fromDispatchDuration,
    'from_dispatch_distance' => $fromDispatchDistance,
    'to_base_duration' => $toBaseDuration,
    'to_base_distance' => $toBaseDistance,
    'metric_source' => 'google_routes',
  ];
}

function transport_extract_google_component(array $components, array $wantedTypes): string {
  foreach ($components as $component) {
    if (!is_array($component)) {
      continue;
    }
    $types = $component['types'] ?? null;
    if (!is_array($types)) {
      continue;
    }
    foreach ($wantedTypes as $type) {
      if (in_array($type, $types, true)) {
        return trim((string)($component['long_name'] ?? ''));
      }
    }
  }
  return '';
}

function transport_lookup_address_by_zip(string $zip): array {
  $zip = preg_replace('/\D+/', '', $zip) ?? '';
  if (!preg_match('/^\d{7}$/', $zip)) {
    throw new RuntimeException('郵便番号は7桁で入力してください');
  }

  $geo = transport_google_geocode($zip . ', Japan');
  $components = $geo['address_components'] ?? [];

  $prefecture = transport_extract_google_component($components, ['administrative_area_level_1']);
  $city = transport_extract_google_component($components, ['locality', 'administrative_area_level_2']);
  $ward = transport_extract_google_component($components, ['sublocality_level_1', 'sublocality', 'ward']);
  $route = transport_extract_google_component($components, ['route']);
  $street = transport_extract_google_component($components, ['street_number', 'premise']);

  $address1 = transport_join_address_parts([$ward, $route, $street]);

  if ($prefecture === '' && $city === '' && $address1 === '') {
    $formatted = trim((string)($geo['formatted_address'] ?? ''));
    if ($formatted !== '') {
      $formatted = preg_replace('/^〒?\d{3}-?\d{4}\s*/u', '', $formatted) ?? $formatted;
      $prefecture = mb_substr($formatted, 0, 0);
    }
  }

  return [
    'zip' => $zip,
    'prefecture' => $prefecture,
    'city' => $city,
    'address1' => $address1,
    'formatted_address' => (string)($geo['formatted_address'] ?? ''),
    'lat' => $geo['lat'] ?? null,
    'lng' => $geo['lng'] ?? null,
  ];
}

function transport_allowed_stores(PDO $pdo): array {
  return store_access_allowed_stores($pdo);
}

function transport_resolve_store_id(PDO $pdo, ?int $requestedStoreId = null): int {
  return store_access_resolve_manageable_store_id($pdo, $requestedStoreId);
}

function transport_fetch_profiles(PDO $pdo, int $storeId): array {
  $casts = repo_fetch_casts($pdo, $storeId, 'all');
  $userIds = [];
  foreach ($casts as $cast) {
    $uid = (int)($cast['id'] ?? 0);
    if ($uid > 0) {
      $userIds[] = $uid;
    }
  }

  $profiles = [];
  if ($userIds !== []) {
    $ph = implode(',', array_fill(0, count($userIds), '?'));
    $hasSecondary = transport_profile_has_secondary_fields($pdo);
    $selectCols = [
      'user_id',
      'pickup_zip',
      'pickup_prefecture',
      'pickup_city',
      'pickup_address1',
      'pickup_address2',
      'pickup_building',
      'pickup_note',
      'pickup_lat',
      'pickup_lng',
      'pickup_geocoded_at',
      'pickup_enabled',
      'privacy_level',
      transport_profile_has_pickup_target_field($pdo) ? 'pickup_target' : "'primary' AS pickup_target",
    ];
    if ($hasSecondary) {
      $selectCols = array_merge($selectCols, [
        'pickup_sub_zip',
        'pickup_sub_prefecture',
        'pickup_sub_city',
        'pickup_sub_address1',
        'pickup_sub_address2',
        'pickup_sub_building',
        'pickup_sub_note',
        'pickup_sub_lat',
        'pickup_sub_lng',
        'pickup_sub_geocoded_at',
      ]);
    }
    $selectCols[] = 'updated_at';
    $sql = "
      SELECT
        " . implode(",\n        ", $selectCols) . "
      FROM cast_transport_profiles
      WHERE store_id = ?
        AND user_id IN ({$ph})
    ";
    $st = $pdo->prepare($sql);
    $st->execute(array_merge([$storeId], $userIds));
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
      $profiles[(int)$row['user_id']] = $row;
    }
  }

  $rows = [];
  foreach ($casts as $cast) {
    $uid = (int)($cast['id'] ?? 0);
    $profile = $profiles[$uid] ?? [];
    $rows[] = [
      'store_user_id' => (int)($cast['store_user_id'] ?? 0),
      'user_id' => $uid,
      'display_name' => (string)($cast['display_name'] ?? ''),
      'shop_tag' => (string)($cast['shop_tag'] ?? ''),
      'employment_type' => (string)($cast['employment_type'] ?? 'part'),
      'default_start_time' => (string)($cast['default_start_time'] ?? ''),
      'pickup_zip' => (string)($profile['pickup_zip'] ?? ''),
      'pickup_prefecture' => (string)($profile['pickup_prefecture'] ?? ''),
      'pickup_city' => (string)($profile['pickup_city'] ?? ''),
      'pickup_address1' => (string)($profile['pickup_address1'] ?? ''),
      'pickup_address2' => (string)($profile['pickup_address2'] ?? ''),
      'pickup_building' => (string)($profile['pickup_building'] ?? ''),
      'pickup_note' => (string)($profile['pickup_note'] ?? ''),
      'pickup_lat' => array_key_exists('pickup_lat', $profile) && $profile['pickup_lat'] !== null ? (string)$profile['pickup_lat'] : '',
      'pickup_lng' => array_key_exists('pickup_lng', $profile) && $profile['pickup_lng'] !== null ? (string)$profile['pickup_lng'] : '',
      'pickup_geocoded_at' => (string)($profile['pickup_geocoded_at'] ?? ''),
      'pickup_enabled' => (int)($profile['pickup_enabled'] ?? 1),
      'privacy_level' => (string)($profile['privacy_level'] ?? 'manager_only'),
      'pickup_target' => (string)($profile['pickup_target'] ?? 'primary'),
      'pickup_sub_zip' => (string)($profile['pickup_sub_zip'] ?? ''),
      'pickup_sub_prefecture' => (string)($profile['pickup_sub_prefecture'] ?? ''),
      'pickup_sub_city' => (string)($profile['pickup_sub_city'] ?? ''),
      'pickup_sub_address1' => (string)($profile['pickup_sub_address1'] ?? ''),
      'pickup_sub_address2' => (string)($profile['pickup_sub_address2'] ?? ''),
      'pickup_sub_building' => (string)($profile['pickup_sub_building'] ?? ''),
      'pickup_sub_note' => (string)($profile['pickup_sub_note'] ?? ''),
      'pickup_sub_lat' => array_key_exists('pickup_sub_lat', $profile) && $profile['pickup_sub_lat'] !== null ? (string)$profile['pickup_sub_lat'] : '',
      'pickup_sub_lng' => array_key_exists('pickup_sub_lng', $profile) && $profile['pickup_sub_lng'] !== null ? (string)$profile['pickup_sub_lng'] : '',
      'pickup_sub_geocoded_at' => (string)($profile['pickup_sub_geocoded_at'] ?? ''),
      'updated_at' => (string)($profile['updated_at'] ?? ''),
      'has_address' => (
        trim((string)($profile['pickup_prefecture'] ?? '')) !== '' ||
        trim((string)($profile['pickup_city'] ?? '')) !== '' ||
        trim((string)($profile['pickup_address1'] ?? '')) !== ''
      ),
      'has_coords' => (
        array_key_exists('pickup_lat', $profile) &&
        array_key_exists('pickup_lng', $profile) &&
        $profile['pickup_lat'] !== null &&
        $profile['pickup_lng'] !== null
      ),
      'has_sub_address' => (
        trim((string)($profile['pickup_sub_prefecture'] ?? '')) !== '' ||
        trim((string)($profile['pickup_sub_city'] ?? '')) !== '' ||
        trim((string)($profile['pickup_sub_address1'] ?? '')) !== ''
      ),
      'has_sub_coords' => (
        array_key_exists('pickup_sub_lat', $profile) &&
        array_key_exists('pickup_sub_lng', $profile) &&
        $profile['pickup_sub_lat'] !== null &&
        $profile['pickup_sub_lng'] !== null
      ),
    ];
  }

  usort($rows, static function (array $a, array $b): int {
    $tagA = trim((string)($a['shop_tag'] ?? ''));
    $tagB = trim((string)($b['shop_tag'] ?? ''));
    if ($tagA !== '' && $tagB !== '') {
      return ((int)$tagA <=> (int)$tagB);
    }
    if ($tagA !== '' || $tagB !== '') {
      return $tagA !== '' ? -1 : 1;
    }
    return strcmp((string)$a['display_name'], (string)$b['display_name']);
  });

  return $rows;
}

function transport_save_profile(PDO $pdo, int $storeId, int $userId, array $input, int $actorUserId): void {
  if ($storeId <= 0 || $userId <= 0) {
    throw new RuntimeException('対象キャストが不正です');
  }

  $pickupZip = transport_normalize_nullable_text($input['pickup_zip'] ?? '', 16);
  $pickupPrefecture = transport_normalize_nullable_text($input['pickup_prefecture'] ?? '', 64);
  $pickupCity = transport_normalize_nullable_text($input['pickup_city'] ?? '', 128);
  $pickupAddress1 = transport_normalize_nullable_text($input['pickup_address1'] ?? '', 255);
  $pickupAddress2 = transport_normalize_nullable_text($input['pickup_address2'] ?? '', 255);
  $pickupBuilding = transport_normalize_nullable_text($input['pickup_building'] ?? '', 255);
  $pickupNote = transport_normalize_nullable_text($input['pickup_note'] ?? '', 255);
  $pickupLat = transport_normalize_latlng($input['pickup_lat'] ?? null);
  $pickupLng = transport_normalize_latlng($input['pickup_lng'] ?? null);
  $pickupEnabled = ((string)($input['pickup_enabled'] ?? '1') === '0') ? 0 : 1;
  $privacyLevel = transport_normalize_text($input['privacy_level'] ?? 'manager_only', 32);
  $pickupTarget = transport_normalize_text($input['pickup_target'] ?? 'primary', 32);
  if (!in_array($privacyLevel, ['manager_only', 'admin_only'], true)) {
    $privacyLevel = 'manager_only';
  }
  if (!in_array($pickupTarget, ['primary', 'secondary', 'self'], true)) {
    $pickupTarget = 'primary';
  }

  $hasSecondary = transport_profile_has_secondary_fields($pdo);
  $hasPickupTarget = transport_profile_has_pickup_target_field($pdo);
  $pickupSubZip = null;
  $pickupSubPrefecture = null;
  $pickupSubCity = null;
  $pickupSubAddress1 = null;
  $pickupSubAddress2 = null;
  $pickupSubBuilding = null;
  $pickupSubNote = null;
  $pickupSubLat = null;
  $pickupSubLng = null;
  $pickupSubGeocodedAt = null;

  if ($hasSecondary) {
    $pickupSubZip = transport_normalize_nullable_text($input['pickup_sub_zip'] ?? '', 16);
    $pickupSubPrefecture = transport_normalize_nullable_text($input['pickup_sub_prefecture'] ?? '', 64);
    $pickupSubCity = transport_normalize_nullable_text($input['pickup_sub_city'] ?? '', 128);
    $pickupSubAddress1 = transport_normalize_nullable_text($input['pickup_sub_address1'] ?? '', 255);
    $pickupSubAddress2 = transport_normalize_nullable_text($input['pickup_sub_address2'] ?? '', 255);
    $pickupSubBuilding = transport_normalize_nullable_text($input['pickup_sub_building'] ?? '', 255);
    $pickupSubNote = transport_normalize_nullable_text($input['pickup_sub_note'] ?? '', 255);
    $pickupSubLat = transport_normalize_latlng($input['pickup_sub_lat'] ?? null);
    $pickupSubLng = transport_normalize_latlng($input['pickup_sub_lng'] ?? null);
    if (($pickupSubLat === null) !== ($pickupSubLng === null)) {
      throw new RuntimeException('サブ地点の緯度と経度はセットで入力してください');
    }
  }

  if (($pickupLat === null) !== ($pickupLng === null)) {
    throw new RuntimeException('緯度と経度はセットで入力してください');
  }

  $pickupAddressFull = transport_join_address_parts([
    $pickupPrefecture,
    $pickupCity,
    $pickupAddress1,
    $pickupAddress2,
    $pickupBuilding,
  ]);
  if ($pickupAddressFull !== '' && $pickupLat === null && $pickupLng === null) {
    $geo = transport_google_geocode($pickupAddressFull);
    $pickupLat = (float)$geo['lat'];
    $pickupLng = (float)$geo['lng'];
  }

  $geocodedAt = ($pickupLat !== null && $pickupLng !== null) ? date('Y-m-d H:i:s') : null;

  if ($hasSecondary) {
    $pickupSubAddressFull = transport_join_address_parts([
      $pickupSubPrefecture,
      $pickupSubCity,
      $pickupSubAddress1,
      $pickupSubAddress2,
      $pickupSubBuilding,
    ]);
    if ($pickupSubAddressFull !== '' && $pickupSubLat === null && $pickupSubLng === null) {
      $geo = transport_google_geocode($pickupSubAddressFull);
      $pickupSubLat = (float)$geo['lat'];
      $pickupSubLng = (float)$geo['lng'];
    }
    $pickupSubGeocodedAt = ($pickupSubLat !== null && $pickupSubLng !== null) ? date('Y-m-d H:i:s') : null;
  }

  $insertCols = [
    'store_id',
    'user_id',
    'pickup_zip',
    'pickup_prefecture',
    'pickup_city',
    'pickup_address1',
    'pickup_address2',
    'pickup_building',
    'pickup_note',
    'pickup_lat',
    'pickup_lng',
    'pickup_geocoded_at',
    'pickup_enabled',
    'privacy_level',
    ($hasPickupTarget ? 'pickup_target' : null),
    'created_by_user_id',
    'updated_by_user_id',
    'created_at',
    'updated_at',
  ];
  $insertValues = [
    ':store_id',
    ':user_id',
    ':pickup_zip',
    ':pickup_prefecture',
    ':pickup_city',
    ':pickup_address1',
    ':pickup_address2',
    ':pickup_building',
    ':pickup_note',
    ':pickup_lat',
    ':pickup_lng',
    ':pickup_geocoded_at',
    ':pickup_enabled',
    ':privacy_level',
    ($hasPickupTarget ? ':pickup_target' : null),
    ':created_by_user_id',
    ':updated_by_user_id',
    'NOW()',
    'NOW()',
  ];
  $updateCols = [
    'pickup_zip = VALUES(pickup_zip)',
    'pickup_prefecture = VALUES(pickup_prefecture)',
    'pickup_city = VALUES(pickup_city)',
    'pickup_address1 = VALUES(pickup_address1)',
    'pickup_address2 = VALUES(pickup_address2)',
    'pickup_building = VALUES(pickup_building)',
    'pickup_note = VALUES(pickup_note)',
    'pickup_lat = VALUES(pickup_lat)',
    'pickup_lng = VALUES(pickup_lng)',
    'pickup_geocoded_at = VALUES(pickup_geocoded_at)',
    'pickup_enabled = VALUES(pickup_enabled)',
    'privacy_level = VALUES(privacy_level)',
    ($hasPickupTarget ? 'pickup_target = VALUES(pickup_target)' : null),
    'updated_by_user_id = VALUES(updated_by_user_id)',
    'updated_at = NOW()',
  ];
  $insertCols = array_values(array_filter($insertCols, static fn($v): bool => $v !== null));
  $insertValues = array_values(array_filter($insertValues, static fn($v): bool => $v !== null));
  $updateCols = array_values(array_filter($updateCols, static fn($v): bool => $v !== null));

  if ($hasSecondary) {
    $insertCols = array_merge($insertCols, [
      'pickup_sub_zip',
      'pickup_sub_prefecture',
      'pickup_sub_city',
      'pickup_sub_address1',
      'pickup_sub_address2',
      'pickup_sub_building',
      'pickup_sub_note',
      'pickup_sub_lat',
      'pickup_sub_lng',
      'pickup_sub_geocoded_at',
    ]);
    $insertValues = array_merge($insertValues, [
      ':pickup_sub_zip',
      ':pickup_sub_prefecture',
      ':pickup_sub_city',
      ':pickup_sub_address1',
      ':pickup_sub_address2',
      ':pickup_sub_building',
      ':pickup_sub_note',
      ':pickup_sub_lat',
      ':pickup_sub_lng',
      ':pickup_sub_geocoded_at',
    ]);
    $updateCols = array_merge($updateCols, [
      'pickup_sub_zip = VALUES(pickup_sub_zip)',
      'pickup_sub_prefecture = VALUES(pickup_sub_prefecture)',
      'pickup_sub_city = VALUES(pickup_sub_city)',
      'pickup_sub_address1 = VALUES(pickup_sub_address1)',
      'pickup_sub_address2 = VALUES(pickup_sub_address2)',
      'pickup_sub_building = VALUES(pickup_sub_building)',
      'pickup_sub_note = VALUES(pickup_sub_note)',
      'pickup_sub_lat = VALUES(pickup_sub_lat)',
      'pickup_sub_lng = VALUES(pickup_sub_lng)',
      'pickup_sub_geocoded_at = VALUES(pickup_sub_geocoded_at)',
    ]);
  }

  $sql = "
    INSERT INTO cast_transport_profiles (
      " . implode(",\n      ", $insertCols) . "
    ) VALUES (
      " . implode(",\n      ", $insertValues) . "
    )
    ON DUPLICATE KEY UPDATE
      " . implode(",\n      ", $updateCols) . "
  ";
  $st = $pdo->prepare($sql);
  $params = [
    ':store_id' => $storeId,
    ':user_id' => $userId,
    ':pickup_zip' => $pickupZip,
    ':pickup_prefecture' => $pickupPrefecture,
    ':pickup_city' => $pickupCity,
    ':pickup_address1' => $pickupAddress1,
    ':pickup_address2' => $pickupAddress2,
    ':pickup_building' => $pickupBuilding,
    ':pickup_note' => $pickupNote,
    ':pickup_lat' => $pickupLat,
    ':pickup_lng' => $pickupLng,
    ':pickup_geocoded_at' => $geocodedAt,
    ':pickup_enabled' => $pickupEnabled,
    ':privacy_level' => $privacyLevel,
    ':pickup_target' => $pickupTarget,
    ':created_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
    ':updated_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
  ];
  if ($hasSecondary) {
    $params[':pickup_sub_zip'] = $pickupSubZip;
    $params[':pickup_sub_prefecture'] = $pickupSubPrefecture;
    $params[':pickup_sub_city'] = $pickupSubCity;
    $params[':pickup_sub_address1'] = $pickupSubAddress1;
    $params[':pickup_sub_address2'] = $pickupSubAddress2;
    $params[':pickup_sub_building'] = $pickupSubBuilding;
    $params[':pickup_sub_note'] = $pickupSubNote;
    $params[':pickup_sub_lat'] = $pickupSubLat;
    $params[':pickup_sub_lng'] = $pickupSubLng;
    $params[':pickup_sub_geocoded_at'] = $pickupSubGeocodedAt;
  }
  $st->execute($params);
}

function transport_fetch_store_base(PDO $pdo, int $storeId): array {
  $st = $pdo->prepare("
    SELECT id, name, address_text, lat, lng, is_default
    FROM store_transport_bases
    WHERE store_id = ?
    ORDER BY is_default DESC, sort_order ASC, id ASC
    LIMIT 1
  ");
  $st->execute([$storeId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if ($row) {
    return [
      'id' => (int)($row['id'] ?? 0),
      'name' => (string)($row['name'] ?? '店舗'),
      'address_text' => (string)($row['address_text'] ?? ''),
      'lat' => $row['lat'] !== null ? (float)$row['lat'] : null,
      'lng' => $row['lng'] !== null ? (float)$row['lng'] : null,
      'source' => 'store_transport_bases',
    ];
  }

  $st = $pdo->prepare("SELECT id, name, lat, lon FROM stores WHERE id = ? LIMIT 1");
  $st->execute([$storeId]);
  $store = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  return [
    'id' => 0,
    'name' => (string)($store['name'] ?? ('店舗#' . $storeId)),
    'address_text' => '',
    'lat' => $store['lat'] !== null ? (float)$store['lat'] : null,
    'lng' => $store['lon'] !== null ? (float)$store['lon'] : null,
    'source' => 'stores',
  ];
}

function transport_fetch_dispatch_base(PDO $pdo, int $storeId): array {
  if (transport_store_base_has_dispatch_origin_field($pdo)) {
    $st = $pdo->prepare("
      SELECT id, name, address_text, lat, lng, is_default, is_dispatch_origin
      FROM store_transport_bases
      WHERE store_id = ?
      ORDER BY is_dispatch_origin DESC, is_default DESC, sort_order ASC, id ASC
      LIMIT 1
    ");
    $st->execute([$storeId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row && (int)($row['is_dispatch_origin'] ?? 0) === 1) {
      return [
        'id' => (int)($row['id'] ?? 0),
        'name' => (string)($row['name'] ?? '出発拠点'),
        'address_text' => (string)($row['address_text'] ?? ''),
        'lat' => $row['lat'] !== null ? (float)$row['lat'] : null,
        'lng' => $row['lng'] !== null ? (float)$row['lng'] : null,
        'source' => 'store_transport_bases_dispatch',
      ];
    }
  }

  return transport_fetch_store_base($pdo, $storeId);
}

function transport_fetch_route_bases(PDO $pdo, int $storeId): array {
  $arrivalBase = transport_fetch_store_base($pdo, $storeId);
  $dispatchBase = transport_fetch_dispatch_base($pdo, $storeId);
  return [
    'arrival_base' => $arrivalBase,
    'dispatch_base' => $dispatchBase,
  ];
}

function transport_fetch_store_bases(PDO $pdo, int $storeId): array {
  $hasDispatchOrigin = transport_store_base_has_dispatch_origin_field($pdo);
  $st = $pdo->prepare("
    SELECT
      id,
      store_id,
      base_type,
      name,
      address_text,
      lat,
      lng,
      is_default,
      " . ($hasDispatchOrigin ? "is_dispatch_origin," : "0 AS is_dispatch_origin,") . "
      sort_order,
      created_at,
      updated_at
    FROM store_transport_bases
    WHERE store_id = ?
    ORDER BY is_default DESC, sort_order ASC, id ASC
  ");
  $st->execute([$storeId]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function transport_save_store_base(PDO $pdo, int $storeId, array $input): int {
  $baseId = (int)($input['base_id'] ?? 0);
  $baseType = transport_normalize_text($input['base_type'] ?? 'store', 32);
  if (!in_array($baseType, ['store', 'garage', 'meeting_point'], true)) {
    $baseType = 'store';
  }

  $name = transport_normalize_text($input['name'] ?? '', 128);
  if ($name === '') {
    throw new RuntimeException('拠点名を入力してください');
  }

  $addressText = transport_normalize_nullable_text($input['address_text'] ?? '', 255);
  $lat = transport_normalize_latlng($input['lat'] ?? null);
  $lng = transport_normalize_latlng($input['lng'] ?? null);
  $isDefault = ((string)($input['is_default'] ?? '0') === '1') ? 1 : 0;
  $hasDispatchOrigin = transport_store_base_has_dispatch_origin_field($pdo);
  $isDispatchOrigin = $hasDispatchOrigin && ((string)($input['is_dispatch_origin'] ?? '0') === '1') ? 1 : 0;
  $sortOrder = (int)($input['sort_order'] ?? 0);

  if (($lat === null) !== ($lng === null)) {
    throw new RuntimeException('拠点の緯度と経度はセットで入力してください');
  }
  if ($addressText !== null && $addressText !== '' && $lat === null && $lng === null) {
    $geo = transport_google_geocode($addressText);
    $lat = (float)$geo['lat'];
    $lng = (float)$geo['lng'];
  }

  $pdo->beginTransaction();
  try {
    if ($isDefault === 1) {
      $stReset = $pdo->prepare("UPDATE store_transport_bases SET is_default = 0 WHERE store_id = ?");
      $stReset->execute([$storeId]);
    }
    if ($hasDispatchOrigin && $isDispatchOrigin === 1) {
      $stResetDispatch = $pdo->prepare("UPDATE store_transport_bases SET is_dispatch_origin = 0 WHERE store_id = ?");
      $stResetDispatch->execute([$storeId]);
    }

    if ($baseId > 0) {
      $sql = "
        UPDATE store_transport_bases
        SET
          base_type = ?,
          name = ?,
          address_text = ?,
          lat = ?,
          lng = ?,
          is_default = ?,
          " . ($hasDispatchOrigin ? "is_dispatch_origin = ?," : "") . "
          sort_order = ?,
          updated_at = NOW()
        WHERE id = ?
          AND store_id = ?
        LIMIT 1
      ";
      $st = $pdo->prepare($sql);
      $params = [
        $baseType,
        $name,
        $addressText,
        $lat,
        $lng,
        $isDefault,
      ];
      if ($hasDispatchOrigin) {
        $params[] = $isDispatchOrigin;
      }
      $params[] = $sortOrder;
      $params[] = $baseId;
      $params[] = $storeId;
      $st->execute($params);
      if ($st->rowCount() <= 0) {
        throw new RuntimeException('更新対象の拠点が見つかりません');
      }
    } else {
      $sql = "
        INSERT INTO store_transport_bases (
          store_id,
          base_type,
          name,
          address_text,
          lat,
          lng,
          is_default,
          " . ($hasDispatchOrigin ? "is_dispatch_origin," : "") . "
          sort_order,
          created_at,
          updated_at
        ) VALUES (
          ?, ?, ?, ?, ?, ?, ?, " . ($hasDispatchOrigin ? "?," : "") . " ?, NOW(), NOW()
        )
      ";
      $st = $pdo->prepare($sql);
      $params = [
        $storeId,
        $baseType,
        $name,
        $addressText,
        $lat,
        $lng,
        $isDefault,
      ];
      if ($hasDispatchOrigin) {
        $params[] = $isDispatchOrigin;
      }
      $params[] = $sortOrder;
      $st->execute($params);
      $baseId = (int)$pdo->lastInsertId();
    }

    if ($isDefault === 0) {
      $stCheck = $pdo->prepare("SELECT COUNT(*) FROM store_transport_bases WHERE store_id = ? AND is_default = 1");
      $stCheck->execute([$storeId]);
      if ((int)$stCheck->fetchColumn() === 0) {
        $stPromote = $pdo->prepare("
          UPDATE store_transport_bases
          SET is_default = 1, updated_at = NOW()
          WHERE store_id = ?
          ORDER BY sort_order ASC, id ASC
          LIMIT 1
        ");
        $stPromote->execute([$storeId]);
      }
    }
    if ($hasDispatchOrigin && $isDispatchOrigin === 0) {
      $stCheckDispatch = $pdo->prepare("SELECT COUNT(*) FROM store_transport_bases WHERE store_id = ? AND is_dispatch_origin = 1");
      $stCheckDispatch->execute([$storeId]);
      if ((int)$stCheckDispatch->fetchColumn() === 0) {
        $stPromoteDispatch = $pdo->prepare("
          UPDATE store_transport_bases
          SET is_dispatch_origin = 1, updated_at = NOW()
          WHERE store_id = ?
          ORDER BY is_default DESC, sort_order ASC, id ASC
          LIMIT 1
        ");
        $stPromoteDispatch->execute([$storeId]);
      }
    }

    $pdo->commit();
    return $baseId;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }
}

function transport_delete_store_base(PDO $pdo, int $storeId, int $baseId): void {
  if ($baseId <= 0) {
    throw new RuntimeException('削除対象の拠点が不正です');
  }

  $pdo->beginTransaction();
  try {
    $hasDispatchOrigin = transport_store_base_has_dispatch_origin_field($pdo);
    $stInfo = $pdo->prepare("
      SELECT is_default, " . ($hasDispatchOrigin ? "is_dispatch_origin" : "0 AS is_dispatch_origin") . "
      FROM store_transport_bases
      WHERE id = ? AND store_id = ?
      LIMIT 1
    ");
    $stInfo->execute([$baseId, $storeId]);
    $row = $stInfo->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
      throw new RuntimeException('削除対象の拠点が見つかりません');
    }
    $wasDefault = ((int)($row['is_default'] ?? 0) === 1);
    $wasDispatchOrigin = ((int)($row['is_dispatch_origin'] ?? 0) === 1);

    $st = $pdo->prepare("DELETE FROM store_transport_bases WHERE id = ? AND store_id = ? LIMIT 1");
    $st->execute([$baseId, $storeId]);

    if ($wasDefault) {
      $stPromote = $pdo->prepare("
        UPDATE store_transport_bases
        SET is_default = 1, updated_at = NOW()
        WHERE store_id = ?
        ORDER BY sort_order ASC, id ASC
        LIMIT 1
      ");
      $stPromote->execute([$storeId]);
    }
    if ($hasDispatchOrigin && $wasDispatchOrigin) {
      $stPromoteDispatch = $pdo->prepare("
        UPDATE store_transport_bases
        SET is_dispatch_origin = 1, updated_at = NOW()
        WHERE store_id = ?
        ORDER BY is_default DESC, sort_order ASC, id ASC
        LIMIT 1
      ");
      $stPromoteDispatch->execute([$storeId]);
    }

    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }
}

function transport_fetch_route_candidates(PDO $pdo, string $businessDate, array $storeIds): array {
  $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds), static fn(int $id): bool => $id > 0)));
  if ($storeIds === []) {
    return [];
  }

  $ph = implode(',', array_fill(0, count($storeIds), '?'));
  $hasSecondary = transport_profile_has_secondary_fields($pdo);
  $targetSelect = transport_profile_has_pickup_target_field($pdo)
    ? "ctp.pickup_target,"
    : "'primary' AS pickup_target,";
  $subSelect = '';
  if ($hasSecondary) {
    $subSelect = "
      ctp.pickup_sub_zip,
      ctp.pickup_sub_prefecture,
      ctp.pickup_sub_city,
      ctp.pickup_sub_address1,
      ctp.pickup_sub_address2,
      ctp.pickup_sub_building,
      ctp.pickup_sub_note,
      ctp.pickup_sub_lat,
      ctp.pickup_sub_lng,
    ";
  }
  $sql = "
    SELECT
      sp.store_id,
      sp.user_id,
      sp.business_date,
      sp.start_time,
      sp.note AS shift_note,
      u.display_name,
      COALESCE(NULLIF(TRIM(cp.shop_tag), ''), '') AS shop_tag,
      ctp.pickup_zip,
      ctp.pickup_prefecture,
      ctp.pickup_city,
      ctp.pickup_address1,
      ctp.pickup_address2,
      ctp.pickup_building,
      ctp.pickup_note,
      ctp.pickup_lat,
      ctp.pickup_lng,
      ctp.pickup_enabled,
      {$targetSelect}
      {$subSelect}
      s.name AS store_name
    FROM cast_shift_plans sp
    JOIN users u
      ON u.id = sp.user_id
    JOIN stores s
      ON s.id = sp.store_id
    LEFT JOIN cast_profiles cp
      ON cp.user_id = sp.user_id
     AND (cp.store_id = sp.store_id OR cp.store_id IS NULL)
    LEFT JOIN cast_transport_profiles ctp
      ON ctp.store_id = sp.store_id
     AND ctp.user_id = sp.user_id
    WHERE sp.business_date = ?
      AND sp.status = 'planned'
      AND sp.is_off = 0
      AND sp.store_id IN ({$ph})
    ORDER BY sp.store_id ASC, sp.start_time ASC, u.display_name ASC
  ";
  $st = $pdo->prepare($sql);
  $st->execute(array_merge([$businessDate], $storeIds));

  $grouped = [];
  foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
    $storeId = (int)($row['store_id'] ?? 0);
    if ($storeId <= 0) {
      continue;
    }
    if (!isset($grouped[$storeId])) {
      $bases = transport_fetch_route_bases($pdo, $storeId);
      $grouped[$storeId] = [
        'store_id' => $storeId,
        'store_name' => (string)($row['store_name'] ?? ''),
        'business_date' => $businessDate,
        'base' => $bases['arrival_base'],
        'dispatch_base' => $bases['dispatch_base'],
        'casts' => [],
        'stats' => [
          'planned_count' => 0,
          'address_ready_count' => 0,
          'coord_ready_count' => 0,
          'missing_address_count' => 0,
          'missing_coord_count' => 0,
        ],
      ];
    }

    $pickupTarget = (string)($row['pickup_target'] ?? 'primary');
    $pickupEnabled = (int)($row['pickup_enabled'] ?? 1);
    $requiresPickup = transport_pickup_target_requires_pickup($pickupTarget, $pickupEnabled);
    $useSecondary = $hasSecondary && $pickupTarget === 'secondary';
    $pickupPrefix = $useSecondary ? 'pickup_sub_' : 'pickup_';
    $pickupAddress = $requiresPickup
      ? trim(implode(' ', array_filter([
          (string)($row[$pickupPrefix . 'prefecture'] ?? ''),
          (string)($row[$pickupPrefix . 'city'] ?? ''),
          (string)($row[$pickupPrefix . 'address1'] ?? ''),
          (string)($row[$pickupPrefix . 'address2'] ?? ''),
          (string)($row[$pickupPrefix . 'building'] ?? ''),
        ], static fn(string $v): bool => trim($v) !== '')))
      : '自走';
    $hasAddress = $requiresPickup ? ($pickupAddress !== '') : true;
    $hasCoords = $requiresPickup
      ? (($row[$pickupPrefix . 'lat'] ?? null) !== null && ($row[$pickupPrefix . 'lng'] ?? null) !== null)
      : true;

    $grouped[$storeId]['stats']['planned_count']++;
    if ($requiresPickup) {
      if ($hasAddress) {
        $grouped[$storeId]['stats']['address_ready_count']++;
      } else {
        $grouped[$storeId]['stats']['missing_address_count']++;
      }
      if ($hasCoords) {
        $grouped[$storeId]['stats']['coord_ready_count']++;
      } else {
        $grouped[$storeId]['stats']['missing_coord_count']++;
      }
    }

    $grouped[$storeId]['casts'][] = [
      'user_id' => (int)($row['user_id'] ?? 0),
      'display_name' => (string)($row['display_name'] ?? ''),
      'shop_tag' => (string)($row['shop_tag'] ?? ''),
      'start_time' => (string)($row['start_time'] ?? ''),
      'pickup_zip' => (string)($row[$pickupPrefix . 'zip'] ?? ''),
      'pickup_target' => $pickupTarget,
      'pickup_target_label' => transport_pickup_target_label($pickupTarget),
      'pickup_address' => $pickupAddress,
      'pickup_note' => (string)($row[$pickupPrefix . 'note'] ?? ''),
      'pickup_lat' => ($row[$pickupPrefix . 'lat'] ?? null) !== null ? (float)$row[$pickupPrefix . 'lat'] : null,
      'pickup_lng' => ($row[$pickupPrefix . 'lng'] ?? null) !== null ? (float)$row[$pickupPrefix . 'lng'] : null,
      'pickup_enabled' => $pickupEnabled,
      'requires_pickup' => $requiresPickup,
      'has_address' => $hasAddress,
      'has_coords' => $hasCoords,
    ];
  }

  return array_values($grouped);
}

function transport_haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
  $earthRadiusKm = 6371.0;
  $dLat = deg2rad($lat2 - $lat1);
  $dLng = deg2rad($lng2 - $lng1);
  $a = sin($dLat / 2) ** 2
    + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
  $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
  return $earthRadiusKm * $c;
}

function transport_cluster_centroid(array $casts): array {
  if ($casts === []) {
    return ['lat' => 0.0, 'lng' => 0.0];
  }
  $lat = 0.0;
  $lng = 0.0;
  foreach ($casts as $cast) {
    $lat += (float)($cast['pickup_lat'] ?? $cast['lat'] ?? 0.0);
    $lng += (float)($cast['pickup_lng'] ?? $cast['lng'] ?? 0.0);
  }
  return [
    'lat' => $lat / count($casts),
    'lng' => $lng / count($casts),
  ];
}

function transport_decode_plan_meta(?string $json): array {
  $json = trim((string)$json);
  if ($json === '') {
    return [];
  }
  $decoded = json_decode($json, true);
  return is_array($decoded) ? $decoded : [];
}

function transport_build_vehicle_route_set(array $casts, array $base, ?array $dispatchBase, int $vehicleCount, int $maxPassengers): array {
  $dispatchBase = $dispatchBase ?? $base;
  $vehicleCount = transport_normalize_positive_int($vehicleCount, 1, 1, 20);
  $maxPassengers = transport_normalize_positive_int($maxPassengers, 6, 1, 6);

  $eligible = array_values(array_filter($casts, static function (array $cast): bool {
    return !empty($cast['has_coords'])
      && (int)($cast['pickup_enabled'] ?? 1) === 1
      && (!array_key_exists('requires_pickup', $cast) || !empty($cast['requires_pickup']));
  }));

  if ($eligible === [] || ($base['lat'] ?? null) === null || ($base['lng'] ?? null) === null || ($dispatchBase['lat'] ?? null) === null || ($dispatchBase['lng'] ?? null) === null) {
    $single = transport_optimize_pickup_order($casts, $base, $dispatchBase);
    return [
      'routes' => [],
      'used_vehicle_count' => 0,
      'requested_vehicle_count' => $vehicleCount,
      'max_passengers' => $maxPassengers,
      'total_distance_km' => $single['total_distance_km'] ?? null,
      'total_duration_min' => $single['total_duration_min'] ?? null,
      'unavailable_reasons' => (array)($single['unavailable_reasons'] ?? []),
    ];
  }

  $eligibleCount = count($eligible);
  $requiredVehicles = (int)ceil($eligibleCount / max($maxPassengers, 1));
  if ($requiredVehicles > $vehicleCount) {
    return [
      'routes' => [],
      'used_vehicle_count' => 0,
      'requested_vehicle_count' => $vehicleCount,
      'max_passengers' => $maxPassengers,
      'total_distance_km' => null,
      'total_duration_min' => null,
      'unavailable_reasons' => [
        '送迎対象 ' . $eligibleCount . ' 名に対して、車両 ' . $vehicleCount . ' 台・1台最大 ' . $maxPassengers . ' 名では不足しています',
      ],
    ];
  }

  $usedVehicleCount = min($vehicleCount, $eligibleCount);
  $dispatchLat = (float)$dispatchBase['lat'];
  $dispatchLng = (float)$dispatchBase['lng'];

  $seedIndexes = [];
  $remaining = array_keys($eligible);
  usort($remaining, static function (int $a, int $b) use ($eligible, $dispatchLat, $dispatchLng): int {
    $distA = transport_haversine_km($dispatchLat, $dispatchLng, (float)$eligible[$a]['pickup_lat'], (float)$eligible[$a]['pickup_lng']);
    $distB = transport_haversine_km($dispatchLat, $dispatchLng, (float)$eligible[$b]['pickup_lat'], (float)$eligible[$b]['pickup_lng']);
    return $distB <=> $distA;
  });
  $seedIndexes[] = array_shift($remaining);
  while (count($seedIndexes) < $usedVehicleCount && $remaining !== []) {
    $bestIdx = $remaining[0];
    $bestScore = -1.0;
    foreach ($remaining as $candidate) {
      $minDist = null;
      foreach ($seedIndexes as $seedIdx) {
        $dist = transport_haversine_km(
          (float)$eligible[$candidate]['pickup_lat'],
          (float)$eligible[$candidate]['pickup_lng'],
          (float)$eligible[$seedIdx]['pickup_lat'],
          (float)$eligible[$seedIdx]['pickup_lng']
        );
        if ($minDist === null || $dist < $minDist) {
          $minDist = $dist;
        }
      }
      if ($minDist !== null && $minDist > $bestScore) {
        $bestScore = $minDist;
        $bestIdx = $candidate;
      }
    }
    $seedIndexes[] = $bestIdx;
    $remaining = array_values(array_filter($remaining, static fn(int $idx): bool => $idx !== $bestIdx));
  }

  $clusters = [];
  foreach ($seedIndexes as $seedIdx) {
    $clusters[] = [$eligible[$seedIdx]];
  }

  $assignedSeeds = array_fill_keys($seedIndexes, true);
  $unassigned = [];
  foreach ($eligible as $idx => $cast) {
    if (!isset($assignedSeeds[$idx])) {
      $unassigned[] = $cast;
    }
  }

  usort($unassigned, static function (array $a, array $b) use ($dispatchLat, $dispatchLng): int {
    $distA = transport_haversine_km($dispatchLat, $dispatchLng, (float)$a['pickup_lat'], (float)$a['pickup_lng']);
    $distB = transport_haversine_km($dispatchLat, $dispatchLng, (float)$b['pickup_lat'], (float)$b['pickup_lng']);
    return $distB <=> $distA;
  });

  foreach ($unassigned as $cast) {
    $bestCluster = 0;
    $bestScore = null;
    foreach ($clusters as $clusterIndex => $clusterCasts) {
      if (count($clusterCasts) >= $maxPassengers) {
        continue;
      }
      $centroid = transport_cluster_centroid($clusterCasts);
      $score = transport_haversine_km(
        $centroid['lat'],
        $centroid['lng'],
        (float)$cast['pickup_lat'],
        (float)$cast['pickup_lng']
      );
      if ($bestScore === null || $score < $bestScore) {
        $bestScore = $score;
        $bestCluster = $clusterIndex;
      }
    }
    $clusters[$bestCluster][] = $cast;
  }

  $routes = [];
  $totalDistance = 0.0;
  $totalDuration = 0;
  foreach ($clusters as $clusterIndex => $clusterCasts) {
    $route = transport_optimize_pickup_order($clusterCasts, $base, $dispatchBase);
    if (($route['stops'] ?? []) === []) {
      return [
        'routes' => [],
        'used_vehicle_count' => 0,
        'requested_vehicle_count' => $vehicleCount,
        'max_passengers' => $maxPassengers,
        'total_distance_km' => null,
        'total_duration_min' => null,
        'unavailable_reasons' => (array)($route['unavailable_reasons'] ?? []),
      ];
    }
    $route['vehicle_no'] = $clusterIndex + 1;
    $route['vehicle_label'] = ($clusterIndex + 1) . '号車';
    $route['cast_count'] = count(array_filter((array)($route['stops'] ?? []), static fn(array $stop): bool => (string)($stop['stop_type'] ?? '') === 'pickup'));
    $routes[] = $route;
    $totalDistance += (float)($route['total_distance_km'] ?? 0.0);
    $totalDuration += (int)($route['total_duration_min'] ?? 0);
  }

  return [
    'routes' => $routes,
    'used_vehicle_count' => count($routes),
    'requested_vehicle_count' => $vehicleCount,
    'max_passengers' => $maxPassengers,
    'total_distance_km' => round($totalDistance, 2),
    'total_duration_min' => $totalDuration,
    'unavailable_reasons' => [],
  ];
}

function transport_speed_km_per_min(): float {
  return 0.5;
}

function transport_normalize_travel_minutes(int $minutes): int {
  if ($minutes <= 0) {
    return 0;
  }
  return max(3, $minutes);
}

function transport_build_distance_matrix(array $points): array {
  $n = count($points);
  $matrix = array_fill(0, $n, array_fill(0, $n, 0.0));
  for ($i = 0; $i < $n; $i++) {
    for ($j = $i + 1; $j < $n; $j++) {
      $d = transport_haversine_km(
        (float)$points[$i]['lat'],
        (float)$points[$i]['lng'],
        (float)$points[$j]['lat'],
        (float)$points[$j]['lng']
      );
      $matrix[$i][$j] = $d;
      $matrix[$j][$i] = $d;
    }
  }
  return $matrix;
}

function transport_optimize_pickup_order(array $casts, array $base, ?array $dispatchBase = null): array {
  $eligible = [];
  foreach ($casts as $cast) {
    if (($cast['has_coords'] ?? false) !== true) {
      continue;
    }
    if ((int)($cast['pickup_enabled'] ?? 1) !== 1) {
      continue;
    }
    if (array_key_exists('requires_pickup', $cast) && !$cast['requires_pickup']) {
      continue;
    }
    $eligible[] = [
      'user_id' => (int)$cast['user_id'],
      'display_name' => (string)$cast['display_name'],
      'shop_tag' => (string)$cast['shop_tag'],
      'start_time' => (string)$cast['start_time'],
      'pickup_address' => (string)$cast['pickup_address'],
      'pickup_note' => (string)$cast['pickup_note'],
      'lat' => (float)$cast['pickup_lat'],
      'lng' => (float)$cast['pickup_lng'],
    ];
  }

  $dispatchBase = $dispatchBase ?? $base;
  if ($eligible === [] || $base['lat'] === null || $base['lng'] === null || ($dispatchBase['lat'] ?? null) === null || ($dispatchBase['lng'] ?? null) === null) {
    return [
      'algorithm' => 'unavailable',
      'order' => [],
      'total_distance_km' => null,
      'total_duration_min' => null,
      'unavailable_reasons' => transport_build_unavailable_reasons($casts, $base, $dispatchBase),
      'stops' => [],
    ];
  }

  $points = $eligible;
  $n = count($points);
  $baseLat = (float)$base['lat'];
  $baseLng = (float)$base['lng'];
  $dispatchLat = (float)$dispatchBase['lat'];
  $dispatchLng = (float)$dispatchBase['lng'];
  $metricSource = 'haversine';
  $googleMatrix = transport_google_route_matrix($points, $base, $dispatchBase);
  if (is_array($googleMatrix)) {
    $metricSource = (string)($googleMatrix['metric_source'] ?? 'google_routes');
    $durationMatrix = $googleMatrix['duration_matrix'];
    $distanceMatrix = $googleMatrix['distance_matrix'];
    $fromDispatchDuration = $googleMatrix['from_dispatch_duration'];
    $fromDispatchDistance = $googleMatrix['from_dispatch_distance'];
    $toBaseDuration = $googleMatrix['to_base_duration'];
    $toBaseDistance = $googleMatrix['to_base_distance'];
  } else {
    $fromDispatchDistance = [];
    $toBaseDistance = [];
    foreach ($points as $point) {
      $fromDispatchDistance[] = transport_haversine_km($dispatchLat, $dispatchLng, $point['lat'], $point['lng']);
      $toBaseDistance[] = transport_haversine_km($point['lat'], $point['lng'], $baseLat, $baseLng);
    }
    $distanceMatrix = transport_build_distance_matrix($points);
    $durationMatrix = [];
    $fromDispatchDuration = [];
    $toBaseDuration = [];
    $speed = transport_speed_km_per_min();
    foreach ($distanceMatrix as $i => $row) {
      $durationMatrix[$i] = [];
      foreach ($row as $j => $km) {
        $durationMatrix[$i][$j] = ($i === $j)
          ? 0.0
          : (float)(transport_normalize_travel_minutes((int)ceil($km / $speed)) * 60);
      }
      $fromDispatchDuration[$i] = (float)(transport_normalize_travel_minutes((int)ceil($fromDispatchDistance[$i] / $speed)) * 60);
      $toBaseDuration[$i] = (float)(transport_normalize_travel_minutes((int)ceil($toBaseDistance[$i] / $speed)) * 60);
    }
  }

  $order = [];
  $algorithm = 'nearest_neighbor';

  if ($n <= 12) {
    $algorithm = 'held_karp';
    $fullMask = (1 << $n) - 1;
    $dp = [];
    $parent = [];
    for ($j = 0; $j < $n; $j++) {
      $mask = 1 << $j;
      $dp[$mask][$j] = (float)$fromDispatchDuration[$j];
      $parent[$mask][$j] = -1;
    }

    for ($mask = 1; $mask <= $fullMask; $mask++) {
      if (!isset($dp[$mask])) {
        continue;
      }
      for ($j = 0; $j < $n; $j++) {
        if (!isset($dp[$mask][$j])) {
          continue;
        }
        for ($next = 0; $next < $n; $next++) {
          if (($mask & (1 << $next)) !== 0) {
            continue;
          }
          $nextMask = $mask | (1 << $next);
          $newCost = $dp[$mask][$j] + $durationMatrix[$j][$next];
          if (!isset($dp[$nextMask][$next]) || $newCost < $dp[$nextMask][$next]) {
            $dp[$nextMask][$next] = $newCost;
            $parent[$nextMask][$next] = $j;
          }
        }
      }
    }

    $bestCost = null;
    $bestEnd = -1;
    foreach ($dp[$fullMask] ?? [] as $j => $cost) {
      $total = $cost + $toBaseDuration[$j];
      if ($bestCost === null || $total < $bestCost) {
        $bestCost = $total;
        $bestEnd = (int)$j;
      }
    }

    $mask = $fullMask;
    $cur = $bestEnd;
    while ($cur >= 0) {
      $order[] = $cur;
      $prev = $parent[$mask][$cur] ?? -1;
      $mask = $mask ^ (1 << $cur);
      $cur = (int)$prev;
    }
    $order = array_reverse($order);
  } else {
    $remaining = array_keys($points);
    $start = 0;
    $nearest = null;
    foreach ($remaining as $idx) {
      if ($nearest === null || $fromDispatchDuration[$idx] < $nearest) {
        $nearest = $fromDispatchDuration[$idx];
        $start = $idx;
      }
    }
    $order[] = $start;
    $remaining = array_values(array_filter($remaining, static fn(int $idx): bool => $idx !== $start));
    while ($remaining !== []) {
      $last = $order[count($order) - 1];
      $bestIdx = 0;
      $bestDist = null;
      foreach ($remaining as $pos => $idx) {
        $dist = $durationMatrix[$last][$idx];
        if ($bestDist === null || $dist < $bestDist) {
          $bestDist = $dist;
          $bestIdx = $pos;
        }
      }
      $order[] = $remaining[$bestIdx];
      array_splice($remaining, $bestIdx, 1);
    }
  }

  $stops = [[
    'stop_order' => 1,
    'stop_type' => 'dispatch_origin',
    'cast_user_id' => null,
    'display_name' => (string)($dispatchBase['name'] ?? '出発拠点'),
    'shop_tag' => '',
    'pickup_address' => (string)($dispatchBase['address_text'] ?? ''),
    'pickup_note' => '',
    'lat' => $dispatchLat,
    'lng' => $dispatchLng,
    'distance_km_from_prev' => 0.0,
    'travel_minutes_from_prev' => 0,
  ]];
  $totalDistance = 0.0;
  $totalDuration = 0;
  foreach ($order as $seq => $pointIdx) {
    $point = $points[$pointIdx];
    $distanceFromPrev = 0.0;
    $durationFromPrev = 0;
    if ($seq > 0) {
      $prevIdx = $order[$seq - 1];
      $distanceFromPrev = (float)$distanceMatrix[$prevIdx][$pointIdx];
      $durationFromPrev = transport_normalize_travel_minutes((int)ceil((float)$durationMatrix[$prevIdx][$pointIdx] / 60));
    } else {
      $distanceFromPrev = (float)$fromDispatchDistance[$pointIdx];
      $durationFromPrev = transport_normalize_travel_minutes((int)ceil((float)$fromDispatchDuration[$pointIdx] / 60));
    }
    $totalDistance += $distanceFromPrev;
    $totalDuration += $durationFromPrev;
    $stops[] = [
      'stop_order' => count($stops) + 1,
      'stop_type' => 'pickup',
      'cast_user_id' => (int)$point['user_id'],
      'display_name' => (string)$point['display_name'],
      'shop_tag' => (string)$point['shop_tag'],
      'pickup_address' => (string)$point['pickup_address'],
      'pickup_note' => (string)$point['pickup_note'],
      'lat' => (float)$point['lat'],
      'lng' => (float)$point['lng'],
      'distance_km_from_prev' => round($distanceFromPrev, 2),
      'travel_minutes_from_prev' => $durationFromPrev,
    ];
  }

  $lastIdx = $order[count($order) - 1] ?? null;
  $toBaseDistance = $lastIdx === null ? 0.0 : (float)$toBaseDistance[$lastIdx];
  $toBaseMinutes = $lastIdx === null
    ? 0
    : transport_normalize_travel_minutes((int)ceil((float)$toBaseDuration[$lastIdx] / 60));
  $totalDistance += $toBaseDistance;
  $totalDuration += $toBaseMinutes;
  $stops[] = [
    'stop_order' => count($stops) + 1,
    'stop_type' => 'store_arrival',
    'cast_user_id' => null,
    'display_name' => (string)$base['name'],
    'shop_tag' => '',
    'pickup_address' => (string)($base['address_text'] ?? ''),
    'pickup_note' => '',
    'lat' => $baseLat,
    'lng' => $baseLng,
    'distance_km_from_prev' => round($toBaseDistance, 2),
    'travel_minutes_from_prev' => $toBaseMinutes,
  ];

  return [
      'algorithm' => $algorithm . ' / ' . $metricSource,
    'order' => $order,
    'total_distance_km' => round($totalDistance, 2),
    'total_duration_min' => $totalDuration,
    'unavailable_reasons' => [],
    'stops' => $stops,
  ];
}

function transport_assign_planned_times(array $route, string $businessDate, ?string $arrivalTime = null): array {
  $arrivalTime = trim((string)$arrivalTime);
  if ($arrivalTime === '') {
    $arrivalTime = '20:00';
  }
  if (!preg_match('/^\d{2}:\d{2}$/', $arrivalTime)) {
    $arrivalTime = '20:00';
  }
  $arrival = new DateTime($businessDate . ' ' . $arrivalTime . ':00', new DateTimeZone('Asia/Tokyo'));
  $stops = $route['stops'] ?? [];
  for ($i = count($stops) - 1; $i >= 0; $i--) {
    $travel = (int)($stops[$i]['travel_minutes_from_prev'] ?? 0);
    $stops[$i]['planned_at'] = $arrival->format('Y-m-d H:i:s');
    if ($travel > 0) {
      $arrival->modify("-{$travel} minutes");
    }
  }
  $route['stops'] = $stops;
  return $route;
}

function transport_create_route_plan(PDO $pdo, int $storeId, string $businessDate, string $direction, ?string $arrivalTime, int $actorUserId, int $vehicleCount = 1, ?array $meta = null): int {
  $st = $pdo->prepare("
    INSERT INTO transport_route_plans (
      business_date,
      store_id,
      direction,
      plan_status,
      target_arrival_time,
      vehicle_count,
      cast_count,
      optimizer_version,
      optimizer_meta_json,
      created_by_user_id,
      created_at,
      updated_at
    ) VALUES (
      ?, ?, ?, 'draft', ?, ?, 0, 'mvp-v1', ?, ?, NOW(), NOW()
    )
  ");
  $st->execute([
    $businessDate,
    $storeId,
    $direction,
    $arrivalTime !== null && preg_match('/^\d{2}:\d{2}$/', $arrivalTime) ? $arrivalTime . ':00' : null,
    $vehicleCount,
    $meta !== null ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
    $actorUserId > 0 ? $actorUserId : null,
  ]);
  return (int)$pdo->lastInsertId();
}

function transport_save_generated_route(PDO $pdo, int $routePlanId, int $storeId, array $base, array $route, ?array $meta = null): void {
  $stDelete = $pdo->prepare("DELETE FROM transport_route_stops WHERE route_plan_id = ?");
  $stDelete->execute([$routePlanId]);

  $stInsert = $pdo->prepare("
    INSERT INTO transport_route_stops (
      route_plan_id,
      store_id,
      cast_user_id,
      stop_order,
      stop_type,
      planned_at,
      travel_minutes_from_prev,
      distance_km_from_prev,
      source_lat,
      source_lng,
      dest_lat,
      dest_lng,
      address_snapshot,
      note,
      created_at,
      updated_at
    ) VALUES (
      :route_plan_id,
      :store_id,
      :cast_user_id,
      :stop_order,
      :stop_type,
      :planned_at,
      :travel_minutes_from_prev,
      :distance_km_from_prev,
      :source_lat,
      :source_lng,
      :dest_lat,
      :dest_lng,
      :address_snapshot,
      :note,
      NOW(),
      NOW()
    )
  ");

  $prevLat = null;
  $prevLng = null;
  foreach (($route['stops'] ?? []) as $stop) {
    $destLat = $stop['lat'] ?? null;
    $destLng = $stop['lng'] ?? null;
    $sourceLat = $prevLat;
    $sourceLng = $prevLng;
    $stInsert->execute([
      ':route_plan_id' => $routePlanId,
      ':store_id' => $storeId,
      ':cast_user_id' => $stop['cast_user_id'],
      ':stop_order' => (int)$stop['stop_order'],
      ':stop_type' => (string)$stop['stop_type'],
      ':planned_at' => $stop['planned_at'] ?? null,
      ':travel_minutes_from_prev' => (int)($stop['travel_minutes_from_prev'] ?? 0),
      ':distance_km_from_prev' => $stop['distance_km_from_prev'] ?? null,
      ':source_lat' => $sourceLat,
      ':source_lng' => $sourceLng,
      ':dest_lat' => $destLat,
      ':dest_lng' => $destLng,
      ':address_snapshot' => (string)($stop['pickup_address'] ?? ''),
      ':note' => (string)($stop['pickup_note'] ?? ''),
    ]);
    $prevLat = $destLat;
    $prevLng = $destLng;
  }

  $pickupCount = 0;
  foreach (($route['stops'] ?? []) as $stop) {
    if ((string)($stop['stop_type'] ?? '') === 'pickup') {
      $pickupCount++;
    }
  }

  $stUpdate = $pdo->prepare("
    UPDATE transport_route_plans
    SET
      base_id = ?,
      cast_count = ?,
      total_distance_km = ?,
      total_duration_min = ?,
      optimizer_meta_json = ?,
      updated_at = NOW()
    WHERE id = ?
    LIMIT 1
  ");
  $stUpdate->execute([
    (int)($base['id'] ?? 0) > 0 ? (int)$base['id'] : null,
    $pickupCount,
    $route['total_distance_km'],
    $route['total_duration_min'],
    json_encode([
      'algorithm' => $route['algorithm'] ?? 'unknown',
      'base_source' => $base['source'] ?? '',
    ] + ($meta ?? []), JSON_UNESCAPED_UNICODE),
    $routePlanId,
  ]);
}

function transport_recalculate_route_from_stops(array $pickupStops, array $base, string $businessDate, ?string $arrivalTime): array {
  $dispatchBase = $base['dispatch_base'] ?? $base;
  $arrivalBase = $base['arrival_base'] ?? $base;
  if (($arrivalBase['lat'] ?? null) === null || ($arrivalBase['lng'] ?? null) === null) {
    throw new RuntimeException('店舗拠点の座標が未登録のため再計算できません');
  }
  if (($dispatchBase['lat'] ?? null) === null || ($dispatchBase['lng'] ?? null) === null) {
    throw new RuntimeException('出発拠点の座標が未登録のため再計算できません');
  }

  $baseLat = (float)$arrivalBase['lat'];
  $baseLng = (float)$arrivalBase['lng'];
  $dispatchLat = (float)$dispatchBase['lat'];
  $dispatchLng = (float)$dispatchBase['lng'];
  $speed = transport_speed_km_per_min();
  $stops = [[
    'stop_order' => 1,
    'stop_type' => 'dispatch_origin',
    'cast_user_id' => null,
    'display_name' => (string)($dispatchBase['name'] ?? '出発拠点'),
    'pickup_address' => (string)($dispatchBase['address_text'] ?? ''),
    'pickup_note' => '',
    'lat' => $dispatchLat,
    'lng' => $dispatchLng,
    'distance_km_from_prev' => 0.0,
    'travel_minutes_from_prev' => 0,
  ]];
  $totalDistance = 0.0;
  $points = [];
  foreach ($pickupStops as $stop) {
    $points[] = [
      'lat' => (float)($stop['dest_lat'] ?? $stop['lat'] ?? 0.0),
      'lng' => (float)($stop['dest_lng'] ?? $stop['lng'] ?? 0.0),
    ];
  }
  $googleMatrix = transport_google_route_matrix($points, $arrivalBase, $dispatchBase);

  foreach (array_values($pickupStops) as $index => $stop) {
    $destLat = (float)($stop['dest_lat'] ?? $stop['lat'] ?? 0.0);
    $destLng = (float)($stop['dest_lng'] ?? $stop['lng'] ?? 0.0);
    $distance = 0.0;
    $minutes = 0;
    if ($index > 0) {
      if (is_array($googleMatrix)) {
        $distance = (float)$googleMatrix['distance_matrix'][$index - 1][$index];
        $minutes = transport_normalize_travel_minutes((int)ceil((float)$googleMatrix['duration_matrix'][$index - 1][$index] / 60));
      } else {
        $prev = $pickupStops[$index - 1];
        $prevLat = (float)($prev['dest_lat'] ?? $prev['lat'] ?? 0.0);
        $prevLng = (float)($prev['dest_lng'] ?? $prev['lng'] ?? 0.0);
        $distance = transport_haversine_km($prevLat, $prevLng, $destLat, $destLng);
        $minutes = transport_normalize_travel_minutes((int)ceil($distance / $speed));
      }
    } else {
      if (is_array($googleMatrix)) {
        $distance = (float)$googleMatrix['from_dispatch_distance'][$index];
        $minutes = transport_normalize_travel_minutes((int)ceil((float)$googleMatrix['from_dispatch_duration'][$index] / 60));
      } else {
        $distance = transport_haversine_km($dispatchLat, $dispatchLng, $destLat, $destLng);
        $minutes = transport_normalize_travel_minutes((int)ceil($distance / $speed));
      }
    }
    $totalDistance += $distance;
    $stops[] = [
      'stop_order' => count($stops) + 1,
      'stop_type' => 'pickup',
      'cast_user_id' => $stop['cast_user_id'] ?? null,
      'display_name' => (string)($stop['display_name'] ?? ''),
      'pickup_address' => (string)($stop['address_snapshot'] ?? ''),
      'pickup_note' => (string)($stop['note'] ?? ''),
      'lat' => $destLat,
      'lng' => $destLng,
      'distance_km_from_prev' => round($distance, 2),
      'travel_minutes_from_prev' => $minutes,
    ];
  }

  $lastLat = $baseLat;
  $lastLng = $baseLng;
  if ($pickupStops !== []) {
    $last = $pickupStops[count($pickupStops) - 1];
    $lastLat = (float)($last['dest_lat'] ?? $last['lat'] ?? $baseLat);
    $lastLng = (float)($last['dest_lng'] ?? $last['lng'] ?? $baseLng);
  }
  if ($pickupStops === []) {
    $toBaseDistance = 0.0;
    $toBaseMinutes = 0;
  } elseif (is_array($googleMatrix)) {
    $lastIndex = count($pickupStops) - 1;
    $toBaseDistance = (float)$googleMatrix['to_base_distance'][$lastIndex];
    $toBaseMinutes = transport_normalize_travel_minutes((int)ceil((float)$googleMatrix['to_base_duration'][$lastIndex] / 60));
  } else {
    $toBaseDistance = transport_haversine_km($lastLat, $lastLng, $baseLat, $baseLng);
    $toBaseMinutes = transport_normalize_travel_minutes((int)ceil($toBaseDistance / $speed));
  }
  $totalDistance += $toBaseDistance;
  $stops[] = [
    'stop_order' => count($stops) + 1,
    'stop_type' => 'store_arrival',
    'cast_user_id' => null,
    'display_name' => (string)($arrivalBase['name'] ?? '店舗到着'),
    'pickup_address' => (string)($arrivalBase['address_text'] ?? ''),
    'pickup_note' => '',
    'lat' => $baseLat,
    'lng' => $baseLng,
    'distance_km_from_prev' => round($toBaseDistance, 2),
    'travel_minutes_from_prev' => $toBaseMinutes,
  ];

  $route = [
    'algorithm' => 'manual / ' . (is_array($googleMatrix) ? 'google_routes' : 'haversine'),
    'order' => [],
    'total_distance_km' => round($totalDistance, 2),
    'total_duration_min' => array_sum(array_map(static fn(array $stop): int => (int)($stop['travel_minutes_from_prev'] ?? 0), $stops)),
    'unavailable_reasons' => [],
    'stops' => $stops,
  ];
  return transport_assign_planned_times($route, $businessDate, $arrivalTime);
}

function transport_reorder_route_plan(PDO $pdo, int $routePlanId, array $requestedOrders): void {
  $detail = transport_fetch_route_detail($pdo, $routePlanId);
  if ($detail === null) {
    throw new RuntimeException('並び替え対象のルートが見つかりません');
  }

  $plan = $detail['plan'] ?? [];
  $stops = $detail['stops'] ?? [];
  $storeId = (int)($plan['store_id'] ?? 0);
  $base = transport_fetch_route_bases($pdo, $storeId);
  $meta = transport_decode_plan_meta((string)($plan['optimizer_meta_json'] ?? ''));

  $pickupStops = array_values(array_filter($stops, static fn(array $stop): bool => (string)($stop['stop_type'] ?? '') === 'pickup'));
  if ($pickupStops === []) {
    throw new RuntimeException('並び替えできる停車先がありません');
  }

  $ranked = [];
  foreach ($pickupStops as $idx => $stop) {
    $stopId = (int)($stop['id'] ?? 0);
    $rank = isset($requestedOrders[$stopId]) ? (int)$requestedOrders[$stopId] : ($idx + 1);
    $ranked[] = [
      'rank' => $rank,
      'seq' => $idx,
      'stop' => $stop,
    ];
  }
  usort($ranked, static function (array $a, array $b): int {
    if ($a['rank'] === $b['rank']) {
      return $a['seq'] <=> $b['seq'];
    }
    return $a['rank'] <=> $b['rank'];
  });
  $orderedStops = array_values(array_map(static fn(array $row): array => $row['stop'], $ranked));

  $route = transport_recalculate_route_from_stops(
    $orderedStops,
    $base,
    (string)($plan['business_date'] ?? ''),
    substr((string)($plan['target_arrival_time'] ?? ''), 0, 5) ?: null
  );

  $pdo->beginTransaction();
  try {
    transport_save_generated_route($pdo, $routePlanId, $storeId, $base['arrival_base'], $route, $meta);
    $st = $pdo->prepare("
      UPDATE transport_route_plans
      SET plan_status = 'draft', updated_at = NOW()
      WHERE id = ?
      LIMIT 1
    ");
    $st->execute([$routePlanId]);
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }
}

function transport_generate_and_save_routes(PDO $pdo, int $storeId, string $businessDate, ?string $arrivalTime, int $actorUserId, int $vehicleCount = 1, int $maxPassengers = 6): array {
  $groups = transport_fetch_route_candidates($pdo, $businessDate, [$storeId]);
  if ($groups === []) {
    throw new RuntimeException('対象店舗の出勤予定がありません');
  }

  $group = $groups[0];
  $routeSet = transport_build_vehicle_route_set(
    $group['casts'],
    $group['base'],
    $group['dispatch_base'] ?? $group['base'],
    $vehicleCount,
    $maxPassengers
  );
  if (($routeSet['routes'] ?? []) === []) {
    $reasons = (array)($routeSet['unavailable_reasons'] ?? []);
    throw new RuntimeException($reasons[0] ?? 'ルート生成に必要な送迎座標が不足しています');
  }

  $pdo->beginTransaction();
  try {
    $batchId = 'batch_' . str_replace('.', '', uniqid('', true));
    $routePlanIds = [];
    foreach ((array)$routeSet['routes'] as $route) {
      $route = transport_assign_planned_times($route, $businessDate, $arrivalTime);
      $routeMeta = [
        'batch_id' => $batchId,
        'vehicle_no' => (int)($route['vehicle_no'] ?? 1),
        'vehicle_label' => (string)($route['vehicle_label'] ?? ((int)($route['vehicle_no'] ?? 1) . '号車')),
        'requested_vehicle_count' => (int)($routeSet['requested_vehicle_count'] ?? $vehicleCount),
        'used_vehicle_count' => (int)($routeSet['used_vehicle_count'] ?? 1),
        'max_passengers' => (int)($routeSet['max_passengers'] ?? $maxPassengers),
      ];
      $routePlanId = transport_create_route_plan($pdo, $storeId, $businessDate, 'pickup', $arrivalTime, $actorUserId, 1, $routeMeta);
      transport_save_generated_route($pdo, $routePlanId, $storeId, $group['base'], $route, $routeMeta);
      $routePlanIds[] = $routePlanId;
    }
    $pdo->commit();
    return $routePlanIds;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }
}

function transport_generate_and_save_route(PDO $pdo, int $storeId, string $businessDate, ?string $arrivalTime, int $actorUserId, int $vehicleCount = 1, int $maxPassengers = 6): int {
  $ids = transport_generate_and_save_routes($pdo, $storeId, $businessDate, $arrivalTime, $actorUserId, $vehicleCount, $maxPassengers);
  return (int)($ids[0] ?? 0);
}

function transport_fetch_route_plans(PDO $pdo, string $businessDate, array $storeIds): array {
  $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds), static fn(int $id): bool => $id > 0)));
  if ($storeIds === []) {
    return [];
  }

  $ph = implode(',', array_fill(0, count($storeIds), '?'));
  $sql = "
    SELECT
      rp.id,
      rp.business_date,
      rp.store_id,
      rp.direction,
      rp.plan_status,
      rp.target_arrival_time,
      rp.cast_count,
      rp.total_distance_km,
      rp.total_duration_min,
      rp.optimizer_meta_json,
      rp.created_at,
      s.name AS store_name
    FROM transport_route_plans rp
    JOIN stores s
      ON s.id = rp.store_id
    WHERE rp.business_date = ?
      AND rp.store_id IN ({$ph})
    ORDER BY rp.created_at DESC, rp.id DESC
  ";
  $st = $pdo->prepare($sql);
  $st->execute(array_merge([$businessDate], $storeIds));
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($rows as &$row) {
    $meta = transport_decode_plan_meta((string)($row['optimizer_meta_json'] ?? ''));
    $row['vehicle_no'] = (int)($meta['vehicle_no'] ?? 1);
    $row['vehicle_label'] = (string)($meta['vehicle_label'] ?? ((int)$row['vehicle_no'] . '号車'));
    $row['requested_vehicle_count'] = (int)($meta['requested_vehicle_count'] ?? 1);
    $row['max_passengers'] = (int)($meta['max_passengers'] ?? 6);
  }
  unset($row);
  return $rows;
}

function transport_fetch_route_detail(PDO $pdo, int $routePlanId): ?array {
  $st = $pdo->prepare("
    SELECT
      rp.*,
      s.name AS store_name
    FROM transport_route_plans rp
    JOIN stores s
      ON s.id = rp.store_id
    WHERE rp.id = ?
    LIMIT 1
  ");
  $st->execute([$routePlanId]);
  $plan = $st->fetch(PDO::FETCH_ASSOC);
  if (!$plan) {
    return null;
  }
  $planMeta = transport_decode_plan_meta((string)($plan['optimizer_meta_json'] ?? ''));
  $plan['vehicle_no'] = (int)($planMeta['vehicle_no'] ?? 1);
  $plan['vehicle_label'] = (string)($planMeta['vehicle_label'] ?? ((int)$plan['vehicle_no'] . '号車'));
  $plan['requested_vehicle_count'] = (int)($planMeta['requested_vehicle_count'] ?? 1);
  $plan['used_vehicle_count'] = (int)($planMeta['used_vehicle_count'] ?? 1);
  $plan['max_passengers'] = (int)($planMeta['max_passengers'] ?? 6);
  $plan['batch_id'] = (string)($planMeta['batch_id'] ?? '');

  $stStops = $pdo->prepare("
    SELECT
      trs.*,
      u.display_name
    FROM transport_route_stops trs
    LEFT JOIN users u
      ON u.id = trs.cast_user_id
    WHERE trs.route_plan_id = ?
    ORDER BY trs.stop_order ASC, trs.id ASC
  ");
  $stStops->execute([$routePlanId]);
  $stops = $stStops->fetchAll(PDO::FETCH_ASSOC) ?: [];

  return [
    'plan' => $plan,
    'stops' => $stops,
  ];
}

function transport_update_route_plan_status(PDO $pdo, int $routePlanId, string $status, int $actorUserId): void {
  if (!in_array($status, ['draft', 'confirmed', 'completed'], true)) {
    throw new RuntimeException('更新先の状態が不正です');
  }

  $confirmedAt = null;
  $confirmedBy = null;
  if ($status === 'confirmed') {
    $confirmedAt = date('Y-m-d H:i:s');
    $confirmedBy = $actorUserId > 0 ? $actorUserId : null;
  }

  $st = $pdo->prepare("
    UPDATE transport_route_plans
    SET
      plan_status = ?,
      confirmed_at = ?,
      confirmed_by_user_id = ?,
      updated_at = NOW()
    WHERE id = ?
    LIMIT 1
  ");
  $st->execute([$status, $confirmedAt, $confirmedBy, $routePlanId]);
  if ($st->rowCount() <= 0) {
    throw new RuntimeException('更新対象のルートが見つかりません');
  }
}
