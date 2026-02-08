<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/db.php';

/** env/define から読む */
function conf(string $key): string {
  if (defined($key)) return (string)constant($key);
  $v = getenv($key);
  return is_string($v) ? $v : '';
}

/** ログ：どのファイルが叩かれているか */
error_log('[line_webhook HIT] uri=' . ($_SERVER['REQUEST_URI'] ?? '') . ' file=' . __FILE__);

/** GETなどは200で返す（LINE以外のアクセス対策） */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
  http_response_code(200);
  echo 'OK';
  exit;
}

$channelSecret = conf('LINE_MSG_CHANNEL_SECRET');
$accessToken   = conf('LINE_MSG_CHANNEL_ACCESS_TOKEN');

if ($channelSecret === '' || $accessToken === '') {
  error_log('[line_webhook] config missing secret=' . strlen($channelSecret) . ' token=' . strlen($accessToken));
  http_response_code(500);
  echo 'LINE config missing';
  exit;
}

$rawBody = file_get_contents('php://input') ?: '';
$sig = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';

if ($sig === '') {
  error_log('[line_webhook] Missing signature. body_len=' . strlen($rawBody));
  http_response_code(400);
  echo 'Missing signature';
  exit;
}

$expected = base64_encode(hash_hmac('sha256', $rawBody, $channelSecret, true));
if (!hash_equals($expected, (string)$sig)) {
  error_log('[line_webhook] invalid signature body_len=' . strlen($rawBody));
  http_response_code(401);
  echo 'Bad signature';
  exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload) || !isset($payload['events']) || !is_array($payload['events'])) {
  http_response_code(200);
  echo 'OK';
  exit;
}

$pdo = db();

function line_api_post(string $accessToken, string $path, array $body): array {
  $url = 'https://api.line.me/v2/bot/' . ltrim($path, '/');

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'Authorization: Bearer ' . $accessToken,
    ],
    CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
  ]);
  $res = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  return [$code, (string)$res, (string)$err];
}

function reply_text(string $accessToken, string $replyToken, string $text, bool $askLocation=false): void {
  $msg = [
    'type' => 'text',
    'text' => $text,
  ];
  if ($askLocation) {
    $msg['quickReply'] = [
      'items' => [[
        'type' => 'action',
        'action' => [
          'type' => 'location',
          'label' => '位置情報を送る',
        ]
      ]]
    ];
  }

  [$code, $res, $err] = line_api_post($accessToken, 'message/reply', [
    'replyToken' => $replyToken,
    'messages' => [$msg],
  ]);

  if ($code >= 300) {
    error_log('[line_webhook reply] failed code=' . $code . ' err=' . $err . ' res=' . substr($res, 0, 200));
  }
}

function haversine_m(float $lat1, float $lon1, float $lat2, float $lon2): float {
  $R = 6371000.0;
  $phi1 = deg2rad($lat1); $phi2 = deg2rad($lat2);
  $dphi = deg2rad($lat2 - $lat1);
  $dl   = deg2rad($lon2 - $lon1);
  $a = sin($dphi/2)**2 + cos($phi1)*cos($phi2)*sin($dl/2)**2;
  $c = 2 * atan2(sqrt($a), sqrt(1-$a));
  return $R * $c;
}

function resolve_user_id_by_line(PDO $pdo, string $lineUserId): int {
  $st = $pdo->prepare("
    SELECT user_id
    FROM user_identities
    WHERE provider='line' AND provider_user_id=? AND is_active=1
    LIMIT 1
  ");
  $st->execute([$lineUserId]);
  return (int)($st->fetchColumn() ?: 0);
}

function resolve_cast_store_id(PDO $pdo, int $userId): int {
  $st = $pdo->prepare("
    SELECT ur.store_id
    FROM user_roles ur
    JOIN roles r ON r.id=ur.role_id AND r.code='cast'
    WHERE ur.user_id=?
      AND ur.store_id IS NOT NULL
    ORDER BY ur.store_id ASC
    LIMIT 1
  ");
  $st->execute([$userId]);
  return (int)($st->fetchColumn() ?: 0);
}

function fetch_store_geo(PDO $pdo, int $storeId): array {
  $st = $pdo->prepare("
    SELECT id, name, lat, lon, radius_m, business_day_start
    FROM stores
    WHERE id=?
    LIMIT 1
  ");
  $st->execute([$storeId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return is_array($row) ? $row : [];
}

function business_date_for_store(array $storeRow, ?DateTime $now=null): string {
  $now = $now ?: new DateTime('now', new DateTimeZone('Asia/Tokyo'));
  $cut = (string)($storeRow['business_day_start'] ?? '06:00:00');
  $cutDT = new DateTime($now->format('Y-m-d') . ' ' . $cut, new DateTimeZone('Asia/Tokyo'));
  if ($now < $cutDT) $now->modify('-1 day');
  return $now->format('Y-m-d');
}

function attendance_clock_in(PDO $pdo, int $userId, int $storeId, string $bizDate, string $source='line'): void {
  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare("
      SELECT id, clock_in
      FROM attendances
      WHERE user_id=? AND store_id=? AND business_date=?
      LIMIT 1
    ");
    $st->execute([$userId, $storeId, $bizDate]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
      $st = $pdo->prepare("
        INSERT INTO attendances
          (user_id, store_id, business_date, clock_in, status, source_in, created_at, updated_at)
        VALUES
          (?, ?, ?, NOW(), 'working', ?, NOW(), NOW())
      ");
      $st->execute([$userId, $storeId, $bizDate, $source]);
    } else {
      if (empty($row['clock_in'])) {
        $st = $pdo->prepare("
          UPDATE attendances
          SET clock_in=NOW(), status='working', source_in=?, updated_at=NOW()
          WHERE id=?
          LIMIT 1
        ");
        $st->execute([$source, (int)$row['id']]);
      }
    }

    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

function attendance_clock_out(PDO $pdo, int $userId, int $storeId, string $bizDate, string $source='line'): void {
  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare("
      SELECT id, clock_out
      FROM attendances
      WHERE user_id=? AND store_id=? AND business_date=?
      LIMIT 1
    ");
    $st->execute([$userId, $storeId, $bizDate]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
      $st = $pdo->prepare("
        INSERT INTO attendances
          (user_id, store_id, business_date, clock_out, status, source_out, created_at, updated_at)
        VALUES
          (?, ?, ?, NOW(), 'finished', ?, NOW(), NOW())
      ");
      $st->execute([$userId, $storeId, $bizDate, $source]);
    } else {
      if (empty($row['clock_out'])) {
        $st = $pdo->prepare("
          UPDATE attendances
          SET clock_out=NOW(), status='finished', source_out=?, updated_at=NOW()
          WHERE id=?
          LIMIT 1
        ");
        $st->execute([$source, (int)$row['id']]);
      }
    }

    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

function set_pending(PDO $pdo, string $lineUserId, string $action, int $storeId): void {
  $pdo->prepare("DELETE FROM line_geo_pending WHERE provider_user_id=?")->execute([$lineUserId]);

  $st = $pdo->prepare("
    INSERT INTO line_geo_pending
      (provider_user_id, action, store_id, created_at, expires_at)
    VALUES
      (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 3 MINUTE))
  ");
  $st->execute([$lineUserId, $action, $storeId]);
}

function pop_pending(PDO $pdo, string $lineUserId): ?array {
  $st = $pdo->prepare("
    SELECT id, action, store_id, expires_at
    FROM line_geo_pending
    WHERE provider_user_id=?
    ORDER BY id DESC
    LIMIT 1
  ");
  $st->execute([$lineUserId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) return null;

  if ((string)$row['expires_at'] < date('Y-m-d H:i:s')) {
    $pdo->prepare("DELETE FROM line_geo_pending WHERE id=?")->execute([(int)$row['id']]);
    return null;
  }

  $pdo->prepare("DELETE FROM line_geo_pending WHERE id=?")->execute([(int)$row['id']]);
  return $row;
}

/* =========================
   events loop
========================= */
foreach ($payload['events'] as $ev) {
  $type = (string)($ev['type'] ?? '');
  $replyToken = (string)($ev['replyToken'] ?? '');
  if ($replyToken === '') continue;

  $src = $ev['source'] ?? [];
  $lineUserId = (string)($src['userId'] ?? '');
  if ($lineUserId === '') {
    reply_text($accessToken, $replyToken, 'ユーザー識別に失敗しました。');
    continue;
  }

  // postback（出勤/退勤ボタン）
  if ($type === 'postback') {
    $data = (string)($ev['postback']['data'] ?? '');
    parse_str($data, $qs);

    $att = (string)($qs['att'] ?? '');
    if (!in_array($att, ['clock_in','clock_out'], true)) {
      reply_text($accessToken, $replyToken, '不明な操作です。');
      continue;
    }

    $userId = resolve_user_id_by_line($pdo, $lineUserId);
    if ($userId <= 0) {
      reply_text($accessToken, $replyToken, "このLINEはまだシステムに登録されていません。\n管理者に招待QRで登録してもらってください。");
      continue;
    }

    $storeId = resolve_cast_store_id($pdo, $userId);
    if ($storeId <= 0) {
      reply_text($accessToken, $replyToken, "店舗が未設定です。\n管理者に所属店舗を設定してもらってください。");
      continue;
    }

    set_pending($pdo, $lineUserId, $att, $storeId);

    $label = ($att === 'clock_in') ? '出勤' : '退勤';
    reply_text($accessToken, $replyToken, "{$label}処理です。\n「位置情報を送る」を押して送信してください。", true);
    continue;
  }

  // 位置情報
  if ($type === 'message') {
    $msg = $ev['message'] ?? [];
    if (($msg['type'] ?? '') !== 'location') {
      reply_text($accessToken, $replyToken, "出勤/退勤はボタンからお願いします。\n（位置情報が必要です）");
      continue;
    }

    $pending = pop_pending($pdo, $lineUserId);
    if (!$pending) {
      reply_text($accessToken, $replyToken, "出勤/退勤の操作が見つかりません。\nもう一度ボタンから押してください。");
      continue;
    }

    $userId = resolve_user_id_by_line($pdo, $lineUserId);
    if ($userId <= 0) {
      reply_text($accessToken, $replyToken, "このLINEはまだ登録されていません。");
      continue;
    }

    $storeId = (int)($pending['store_id'] ?? 0);
    if ($storeId <= 0) $storeId = resolve_cast_store_id($pdo, $userId);
    if ($storeId <= 0) {
      reply_text($accessToken, $replyToken, "店舗が未設定です。管理者に確認してください。");
      continue;
    }

    $store = fetch_store_geo($pdo, $storeId);
    if (!$store || $store['lat'] === null || $store['lon'] === null) {
      reply_text($accessToken, $replyToken, "店舗の位置情報が未設定です。\n管理者が stores.lat/lon を設定してください。");
      continue;
    }

    $lat = (float)$msg['latitude'];
    $lon = (float)$msg['longitude'];
    $dist = haversine_m((float)$store['lat'], (float)$store['lon'], $lat, $lon);
    $radius = (int)($store['radius_m'] ?? 150);

    if ($dist > $radius) {
      $m = (int)round($dist);
      reply_text($accessToken, $replyToken, "店舗の近くではないため受付できません。\n距離: 約{$m}m / 許可: {$radius}m以内");
      continue;
    }

    $bizDate = business_date_for_store($store);
    $action = (string)$pending['action'];

    try {
      if ($action === 'clock_in') {
        attendance_clock_in($pdo, $userId, $storeId, $bizDate, 'line');
        reply_text($accessToken, $replyToken, "✅ 出勤しました\n営業日: {$bizDate}");
      } else {
        attendance_clock_out($pdo, $userId, $storeId, $bizDate, 'line');
        reply_text($accessToken, $replyToken, "✅ 退勤しました\n営業日: {$bizDate}");
      }
    } catch (Throwable $e) {
      error_log('[line_webhook attendance] ' . $e->getMessage());
      reply_text($accessToken, $replyToken, "処理に失敗しました。\n管理者に連絡してください。");
    }
    continue;
  }

  reply_text($accessToken, $replyToken, '未対応のイベントです。');
}

http_response_code(200);
echo 'OK';