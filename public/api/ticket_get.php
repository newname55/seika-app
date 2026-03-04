<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';

require_login();
$pdo = db();

header('Content-Type: application/json; charset=utf-8');

function json_out(array $a, int $code = 200): void {
  http_response_code($code);
  echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$ticket_id = (int)($_GET['ticket_id'] ?? ($_GET['id'] ?? 0));
if ($ticket_id <= 0) {
  json_out(['ok' => false, 'error' => 'invalid params: ticket_id required'], 400);
}

try {
  $st = $pdo->prepare("SELECT id, store_id, business_date, status, totals_snapshot FROM tickets WHERE id = ? LIMIT 1");
  $st->execute([$ticket_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    json_out(['ok' => false, 'error' => 'ticket not found'], 404);
  }

  $snapshot = null;
  $payload  = null;

  $ts = (string)($row['totals_snapshot'] ?? '');
  if ($ts !== '') {
    $snapshot = json_decode($ts, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($snapshot)) {
      $payload = $snapshot['payload'] ?? null;
    }
  }

  // まだ保存されてなくて totals_snapshot が空でも、初期化できる形にする
  if (!is_array($payload)) {
    $payload = [
      'start_time' => '20:00',
      'discount'   => 0,
      'sets'       => [],
      'history'    => [],
    ];
  }

  json_out([
    'ok' => true,
    'ticket' => [
      'ticket_id'      => (int)$row['id'],
      'store_id'       => (int)$row['store_id'],
      'business_date'  => (string)$row['business_date'],
      'status'         => (string)($row['status'] ?? 'open'),
    ],
    'payload' => $payload,
  ]);

} catch (Throwable $e) {
  json_out(['ok' => false, 'error' => 'server error: ' . $e->getMessage()], 500);
}
$out = [
  'ok' => true,

  // 👇 これを必ず返す
  'ticket' => [
    'id'        => (int)$t['id'],
    'store_id'  => (int)$t['store_id'],
    'status'    => (string)$t['status'],
    'opened_at' => (string)$t['opened_at'],
    'locked_at' => (string)($t['locked_at'] ?? ''),
    'closed_at' => (string)($t['closed_at'] ?? ''),
  ],

  'payload' => $payload,
  'bill'    => $bill,
];

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;