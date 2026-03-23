<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/db.php';

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

/* =========================
   LINE API helpers
========================= */
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
  $msg = ['type' => 'text', 'text' => $text];

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

/* =========================
   domain helpers
========================= */
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

function table_has_column(PDO $pdo, string $table, string $column): bool {
  try {
    $st = $pdo->prepare("
      SELECT COUNT(*)
      FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
    ");
    $st->execute([$table, $column]);
    return ((int)$st->fetchColumn() > 0);
  } catch (Throwable $e) {
    return false;
  }
}

if (!function_exists('business_date_for_store')) {
  function business_date_for_store(array $storeRow, ?DateTime $now=null): string {
    $now = $now ?: new DateTime('now', new DateTimeZone('Asia/Tokyo'));
    $cut = (string)($storeRow['business_day_start'] ?? '06:00:00');
    $cutDT = new DateTime($now->format('Y-m-d') . ' ' . $cut, new DateTimeZone('Asia/Tokyo'));
    if ($now < $cutDT) $now->modify('-1 day');
    return $now->format('Y-m-d');
  }
}

function detect_notice_reply_choice(string $kind, string $text): ?string {
  if ($kind !== 'attendance_confirm') {
    return null;
  }

  $text = trim($text);
  if ($text === '') {
    return null;
  }

  if (preg_match('/^出勤します/u', $text)) return 'confirm';
  if (preg_match('/^遅れます/u', $text) || mb_strpos($text, '遅れ', 0, 'UTF-8') !== false) return 'late';
  if (preg_match('/^休みます/u', $text) || mb_strpos($text, '休み', 0, 'UTF-8') !== false || mb_strpos($text, '欠勤', 0, 'UTF-8') !== false) return 'absent';
  return 'other';
}

function fetch_attendance_today(PDO $pdo, int $userId, int $storeId, string $bizDate): ?array {
  $st = $pdo->prepare("
    SELECT id, clock_in, clock_out, status
    FROM attendances
    WHERE user_id=? AND store_id=? AND business_date=?
    LIMIT 1
  ");
  $st->execute([$userId, $storeId, $bizDate]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function attendance_clock_in(PDO $pdo, int $userId, int $storeId, string $bizDate, string $source='line'): void {
  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare("
      SELECT id, clock_in, clock_out
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
      // すでにclock_inがある場合は何もしない（安全）
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
   notice reply (late/absent/attendance_confirm)
   - line_notice_actions を「返信待ち親」として扱う
========================= */

function find_pending_notice_action(PDO $pdo, int $storeId, int $castUserId, string $bizDate): ?array {
  // 直近の遅刻/欠勤/出勤確認通知で、まだ返信がないもの（優先：当日）
  $st = $pdo->prepare("
    SELECT *
    FROM line_notice_actions
    WHERE store_id=?
      AND cast_user_id=?
      AND business_date=?
      AND kind IN ('late','absent','attendance_confirm')
      AND status='sent'
      AND responded_at IS NULL
    ORDER BY sent_at DESC, id DESC
    LIMIT 1
  ");
  $st->execute([$storeId, $castUserId, $bizDate]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if ($row) return $row;

  // 保険：日付がズレる店（営業日境界）でも拾えるように、12時間以内で再検索
  $st = $pdo->prepare("
    SELECT *
    FROM line_notice_actions
    WHERE store_id=?
      AND cast_user_id=?
      AND kind IN ('late','absent','attendance_confirm')
      AND status='sent'
      AND responded_at IS NULL
      AND sent_at >= (NOW() - INTERVAL 12 HOUR)
    ORDER BY sent_at DESC, id DESC
    LIMIT 1
  ");
  $st->execute([$storeId, $castUserId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function save_notice_reply(PDO $pdo, array $actionRow, int $storeId, string $bizDate, int $castUserId, string $text, array $rawEvent, ?string $replyChoice=null): bool {
  $actionId = (int)($actionRow['id'] ?? 0);
  if ($actionId <= 0) throw new RuntimeException('notice action id not found');
  $hasReplyChoiceOnReplies = table_has_column($pdo, 'line_notice_replies', 'reply_choice');
  $hasReplyChoiceOnActions = table_has_column($pdo, 'line_notice_actions', 'reply_choice');

  // ✅ 先に「返信済みか」を確定させる（再送対策）
  $pdo->beginTransaction();
  try {
    // 行ロックして responded_at を見る
    $st = $pdo->prepare("SELECT responded_at FROM line_notice_actions WHERE id=? FOR UPDATE");
    $st->execute([$actionId]);
    $respondedAt = $st->fetchColumn();

    // すでに返信済みなら、INSERTも返信もスキップ
    if ($respondedAt !== null && (string)$respondedAt !== '') {
      $pdo->commit();
      return false; // ←今回のイベントは重複
    }

    // 1) replies へ保存
    $rawJson = json_encode($rawEvent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($hasReplyChoiceOnReplies) {
      $st = $pdo->prepare("
        INSERT INTO line_notice_replies
          (action_id, store_id, business_date, cast_user_id, message_text, reply_choice, received_at, raw_json)
        VALUES
          (?, ?, ?, ?, ?, ?, NOW(), ?)
      ");
      $st->execute([$actionId, $storeId, $bizDate, $castUserId, $text, $replyChoice, $rawJson]);
    } else {
      $st = $pdo->prepare("
        INSERT INTO line_notice_replies
          (action_id, store_id, business_date, cast_user_id, message_text, received_at, raw_json)
        VALUES
          (?, ?, ?, ?, ?, NOW(), ?)
      ");
      $st->execute([$actionId, $storeId, $bizDate, $castUserId, $text, $rawJson]);
    }

    // 2) actions に反映
    if ($hasReplyChoiceOnActions) {
      $st = $pdo->prepare("
        UPDATE line_notice_actions
        SET responded_at = NOW(),
            last_reply_text = ?,
            reply_choice = ?,
            updated_at = NOW()
        WHERE id = ?
        LIMIT 1
      ");
      $st->execute([$text, $replyChoice, $actionId]);
    } else {
      $st = $pdo->prepare("
        UPDATE line_notice_actions
        SET responded_at = NOW(),
            last_reply_text = ?,
            updated_at = NOW()
        WHERE id = ?
        LIMIT 1
      ");
      $st->execute([$text, $actionId]);
    }

    $pdo->commit();
    return true; // ←初回だけtrue
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
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
      reply_text($accessToken, $replyToken,
        "このLINEはまだシステムに登録されていません。\n"
        . "管理者に招待QRで登録してもらってください。"
      );
      continue;
    }

    $storeId = resolve_cast_store_id($pdo, $userId);
    if ($storeId <= 0) {
      reply_text($accessToken, $replyToken,
        "店舗が未設定です。\n"
        . "管理者に所属店舗を設定してもらってください。"
      );
      continue;
    }

    $store = fetch_store_geo($pdo, $storeId);
    $bizDate = business_date_for_store($store);

    // ✅ すでに状態が確定しているなら、pendingを作らず優しく案内して終了
    $today = fetch_attendance_today($pdo, $userId, $storeId, $bizDate);
    $alreadyIn  = ($today && !empty($today['clock_in']));
    $alreadyOut = ($today && !empty($today['clock_out']));

    if ($att === 'clock_in' && $alreadyIn && !$alreadyOut) {
      reply_text($accessToken, $replyToken,
        "✅ 今日はすでに出勤済みです。\n"
        . "退勤する時は「退勤」を押してください。"
      );
      continue;
    }
    if ($att === 'clock_in' && $alreadyIn && $alreadyOut) {
      reply_text($accessToken, $replyToken,
        "✅ 今日は出勤・退勤ともに記録済みです。\n"
        . "修正が必要なら管理者に連絡してください。"
      );
      continue;
    }
    if ($att === 'clock_out' && $alreadyOut) {
      reply_text($accessToken, $replyToken,
        "✅ 今日はすでに退勤済みです。\n"
        . "修正が必要なら管理者に連絡してください。"
      );
      continue;
    }
    if ($att === 'clock_out' && !$alreadyIn) {
      reply_text($accessToken, $replyToken,
        "⚠️ まだ出勤記録がありません。\n"
        . "先に「出勤」を押してから、退勤をお願いします。"
      );
      continue;
    }

    // ✅ ここまで来たら「位置情報要求」へ
    set_pending($pdo, $lineUserId, $att, $storeId);

    $label = ($att === 'clock_in') ? '出勤' : '退勤';
    reply_text(
      $accessToken,
      $replyToken,
      "📍 {$label}処理です。\n"
      . "店舗に着いたら【位置情報を送る】を押してください。",
      true
    );
    continue;
  }

// 位置情報
if ($type === 'message') {
  $msg = $ev['message'] ?? [];

  // ✅ まず「遅刻/欠勤/出勤確認通知への返信」を吸収する（textだけ対象）
  if (($msg['type'] ?? '') === 'text') {
    $text = trim((string)($msg['text'] ?? ''));

    if ($text !== '') {
      $userId = resolve_user_id_by_line($pdo, $lineUserId);
      if ($userId > 0) {
        $storeId = resolve_cast_store_id($pdo, $userId);
        if ($storeId > 0) {
          $bizDate = business_date_for_store(fetch_store_geo($pdo, $storeId));
          $actionRow = find_pending_notice_action($pdo, $storeId, $userId, $bizDate);
          if ($actionRow) {
            $actionStoreId = (int)($actionRow['store_id'] ?? $storeId);
            $actionBizDate = (string)($actionRow['business_date'] ?? $bizDate);
            $actionKind = (string)($actionRow['kind'] ?? '');
            $replyChoice = detect_notice_reply_choice($actionKind, $text);
            $saved = save_notice_reply($pdo, $actionRow, $actionStoreId, $actionBizDate, $userId, $text, $ev, $replyChoice);

            if ($saved) {
              if ($actionKind === 'attendance_confirm') {
                if ($replyChoice === 'confirm') {
                  reply_text($accessToken, $replyToken, '承知しました。出勤予定として管理画面に反映しました。');
                } elseif ($replyChoice === 'late') {
                  reply_text($accessToken, $replyToken, '承知しました。「遅れます」の返信を管理画面に反映しました。');
                } elseif ($replyChoice === 'absent') {
                  reply_text($accessToken, $replyToken, '承知しました。「休みます」の返信を管理画面に反映しました。');
                } else {
                  reply_text($accessToken, $replyToken, '承知しました。出勤確認の返信を管理画面に反映しました。');
                }
              } else {
                $kindLabel = ($actionKind === 'absent') ? '欠勤' : '遅刻';
                reply_text($accessToken, $replyToken, "承知しました。{$kindLabel}の連絡を管理画面に反映しました。");
              }
            } else {
              reply_text($accessToken, $replyToken, 'この返信はすでに受け付け済みです。');
            }
            continue;
          }
        }
      }
    }
  }

  // ✅ ここから先は “出勤/退勤用” の location だけ扱う
  if (($msg['type'] ?? '') !== 'location') {
    // location以外は何もしない（遅刻/欠勤返信は上で吸収済み）
    reply_text($accessToken, $replyToken, '未対応のメッセージです。');
    continue;
  }

  // 1) LINE→user_id
  $userId = resolve_user_id_by_line($pdo, $lineUserId);
  if ($userId <= 0) {
    reply_text($accessToken, $replyToken, "このLINEはまだ登録されていません。");
    continue;
  }

  // 2) ✅ pending を取り出す（ここが今、抜けてる）
  $pending = pop_pending($pdo, $lineUserId);
  if (!$pending) {
    reply_text($accessToken, $replyToken,
      "⚠️ まだ出勤記録がありません。\n先に「出勤」をお願いします。"
    );
    continue;
  }

  // 3) store_id / action は pending が正
  $storeId = (int)($pending['store_id'] ?? 0);
  if ($storeId <= 0) {
    // 保険：万が一 pending に無い時だけ cast所属から解決
    $storeId = resolve_cast_store_id($pdo, $userId);
  }
  if ($storeId <= 0) {
    reply_text($accessToken, $replyToken, "店舗が未設定です。管理者に確認してください。");
    continue;
  }

  $store = fetch_store_geo($pdo, $storeId);
  if (!$store || $store['lat'] === null || $store['lon'] === null) {
    reply_text($accessToken, $replyToken,
      "店舗の位置情報が未設定です。\n管理者が stores.lat/lon を設定してください。"
    );
    continue;
  }

  // 4) 距離チェック
  $lat = (float)($msg['latitude'] ?? 0);
  $lon = (float)($msg['longitude'] ?? 0);
  $dist = haversine_m((float)$store['lat'], (float)$store['lon'], $lat, $lon);
  $radius = (int)($store['radius_m'] ?? 150);

  if ($dist > $radius) {
    $m = (int)round($dist);
    // ✅ 距離NGなら pending を戻す（これ超重要：やり直せるように）
    set_pending($pdo, $lineUserId, (string)$pending['action'], $storeId);

    reply_text(
      $accessToken,
      $replyToken,
      "📍 まだ店舗から少し離れています（約{$m}m）\n"
      . "店舗の近くで、もう一度【位置情報を送る】を押してください。",
      true
    );
    continue;
  }

  // 5) 出勤/退勤確定
  $bizDate = business_date_for_store($store);
  $action = (string)($pending['action'] ?? '');

  $today = fetch_attendance_today($pdo, $userId, $storeId, $bizDate);
  $alreadyIn  = ($today && !empty($today['clock_in']));
  $alreadyOut = ($today && !empty($today['clock_out']));

  try {
    if ($action === 'clock_in') {
      if ($alreadyIn) {
        reply_text($accessToken, $replyToken,
          "✅ 今日はすでに出勤済みです。\n退勤する時は「退勤」を押してください。"
        );
        continue;
      }
      attendance_clock_in($pdo, $userId, $storeId, $bizDate, 'line');
      reply_text($accessToken, $replyToken, "✅ 出勤しました\n営業日: {$bizDate}");
      continue;
    }

    if ($action === 'clock_out') {
      if ($alreadyOut) {
        reply_text($accessToken, $replyToken,
          "✅ 今日はすでに退勤済みです。\n修正が必要なら管理者に連絡してください。"
        );
        continue;
      }
      if (!$alreadyIn) {
        reply_text($accessToken, $replyToken,
          "⚠️ まだ出勤記録がありません。\n先に「出勤」をお願いします。"
        );
        continue;
      }
      attendance_clock_out($pdo, $userId, $storeId, $bizDate, 'line');
      reply_text($accessToken, $replyToken, "✅ 退勤しました\n営業日: {$bizDate}");
      continue;
    }

    // action不明
    reply_text($accessToken, $replyToken, "不明な操作です。もう一度やり直してください。");
    continue;

  } catch (Throwable $e) {
    error_log('[line_webhook attendance] ' . $e->getMessage());
    reply_text($accessToken, $replyToken, "処理に失敗しました。\n管理者に連絡してください。");
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
      reply_text($accessToken, $replyToken,
        "店舗の位置情報が未設定です。\n管理者が stores.lat/lon を設定してください。"
      );
      continue;
    }

    $lat = (float)$msg['latitude'];
    $lon = (float)$msg['longitude'];
    $dist = haversine_m((float)$store['lat'], (float)$store['lon'], $lat, $lon);
    $radius = (int)($store['radius_m'] ?? 150);

    if ($dist > $radius) {
      $m = (int)round($dist);
      reply_text(
        $accessToken,
        $replyToken,
        "📍 まだ店舗から少し離れています（約{$m}m）\n"
        . "店舗の近くで、もう一度【位置情報を送る】を押してください。"
      );
      continue;
    }

    $bizDate = business_date_for_store($store);
    $action = (string)$pending['action'];

    // ✅ 位置情報が届いた時点でも二重押しを吸収（安全）
    $today = fetch_attendance_today($pdo, $userId, $storeId, $bizDate);
    $alreadyIn  = ($today && !empty($today['clock_in']));
    $alreadyOut = ($today && !empty($today['clock_out']));

    try {
      if ($action === 'clock_in') {
        if ($alreadyIn) {
          reply_text($accessToken, $replyToken,
            "✅ 今日はすでに出勤済みです。\n"
            . "退勤する時は「退勤」を押してください。"
          );
          continue;
        }
        attendance_clock_in($pdo, $userId, $storeId, $bizDate, 'line');
        reply_text($accessToken, $replyToken, "✅ 出勤しました\n営業日: {$bizDate}");
      } else {
        if ($alreadyOut) {
          reply_text($accessToken, $replyToken,
            "✅ 今日はすでに退勤済みです。\n修正が必要なら管理者に連絡してください。"
          );
          continue;
        }
        if (!$alreadyIn) {
          reply_text($accessToken, $replyToken,
            "⚠️ まだ出勤記録がありません。\n先に「出勤」をお願いします。"
          );
          continue;
        }
        attendance_clock_out($pdo, $userId, $storeId, $bizDate, 'line');
        reply_text($accessToken, $replyToken, "✅ 退勤しました\n営業日: {$bizDate}");
      }
    } catch (Throwable $e) {
      error_log('[line_webhook attendance] ' . $e->getMessage());
      reply_text($accessToken, $replyToken, "処理に失敗しました。\n管理者に連絡してください。");
    }
    continue;
  }

  // その他イベント
  reply_text($accessToken, $replyToken, '未対応のイベントです。');
}

http_response_code(200);
echo 'OK';
