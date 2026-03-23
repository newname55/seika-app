<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/db.php';

$secret = conf('CRON_SECRET');
if ($secret === '') { http_response_code(500); exit('CRON_SECRET missing'); }

// 簡易認証（外部から叩かれないように）
$given = (string)($_GET['secret'] ?? '');
if (!hash_equals($secret, $given)) {
  http_response_code(403);
  header('Content-Type: text/plain; charset=utf-8');
  exit('Forbidden');
}

$accessToken = conf('LINE_MSG_CHANNEL_ACCESS_TOKEN');
if ($accessToken === '') { http_response_code(500); exit('LINE token missing'); }

$pdo = db();

function line_push_text(string $accessToken, string $to, string $text): array {
  $ch = curl_init('https://api.line.me/v2/bot/message/push');
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'Authorization: Bearer ' . $accessToken,
    ],
    CURLOPT_POSTFIELDS => json_encode([
      'to' => $to,
      'messages' => [[ 'type' => 'text', 'text' => $text ]],
    ], JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
  ]);
  $res = (string)curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return [$code, $res];
}

$tz = new DateTimeZone('Asia/Tokyo');
$now = new DateTimeImmutable('now', $tz);

// まず「昨日の営業日」を出す：店舗ごとに business_day_start が違う可能性があるので
// ここでは「各店舗で now を business_date_for_store に通して、その-1日」を対象にする。
// ※簡略化：今は全店同じ切替（06:00）前提で“昨日の日付”として運用するのが現実的
$targetBizDate = $now->modify('-1 day')->format('Y-m-d');

$st = $pdo->prepare("
  SELECT
    a.user_id, a.store_id, a.business_date,
    u.display_name,
    s.name AS store_name,
    ui.provider_user_id AS line_user_id
  FROM attendances a
  JOIN users u ON u.id = a.user_id
  JOIN stores s ON s.id = a.store_id
  JOIN user_identities ui
    ON ui.user_id = a.user_id AND ui.provider='line' AND ui.is_active=1
  WHERE a.business_date = ?
    AND a.clock_in IS NOT NULL
    AND (a.clock_out IS NULL OR a.clock_out = '')
");
$st->execute([$targetBizDate]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$sent = 0;
$fail = 0;

foreach ($rows as $r) {
  $name = (string)$r['display_name'];
  $store = (string)$r['store_name'];
  $to = (string)$r['line_user_id'];

  if ($to === '') continue;

  $text =
    "⏰ 退勤の記録がまだ無いみたいです\n"
    . "おつかれさま！もし退勤し忘れてたら、キャスト画面から「退勤」をお願いします。\n"
    . "（{$store} / {$targetBizDate} / {$name}）";

  [$code, $res] = line_push_text($accessToken, $to, $text);
  if ($code >= 200 && $code < 300) $sent++;
  else { $fail++; error_log('[clockout_remind] failed code=' . $code . ' res=' . substr($res, 0, 200)); }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
  'ok' => true,
  'target_business_date' => $targetBizDate,
  'sent' => $sent,
  'failed' => $fail,
  'candidates' => count($rows),
], JSON_UNESCAPED_UNICODE);
