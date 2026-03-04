<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';

if (!function_exists('json_out')) {
  function json_out(array $a, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }
}
if (!function_exists('json_input')) {
  function json_input(): array {
    $raw = file_get_contents('php://input') ?: '';
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
  }
}

try {
  require_login();
  $pdo = db();

  $in = json_input();
  $storeId  = (int)($in['store_id'] ?? 0);
  $ticketId = (int)($in['ticket_id'] ?? 0);
  $phrase   = (string)($in['phrase'] ?? '');

  if ($ticketId <= 0) json_out(['ok'=>false,'error'=>'invalid params: ticket_id required'], 400);
  if ($phrase !== 'CLOSE') json_out(['ok'=>false,'error'=>'invalid phrase'], 400);

  // ticket存在 & storeチェック（storeId無指定でもOK）
  $st = $pdo->prepare("SELECT id, store_id, status, totals_snapshot FROM tickets WHERE id=? LIMIT 1");
  $st->execute([$ticketId]);
  $t = $st->fetch(PDO::FETCH_ASSOC);
  if (!$t) json_out(['ok'=>false,'error'=>'ticket not found'], 404);

  $realStoreId = (int)$t['store_id'];
  if ($storeId > 0 && $storeId !== $realStoreId) json_out(['ok'=>false,'error'=>'store mismatch'], 400);
  $storeId = $realStoreId;

  if (in_array((string)$t['status'], ['paid','void'], true)) {
    json_out(['ok'=>false,'error'=>'already closed'], 400);
  }

  // totals_snapshot は「最後に保存されたもの」をそのまま保持（ズレを起こさない）
  $snapshotJson = (string)($t['totals_snapshot'] ?? '');
  if ($snapshotJson === '') $snapshotJson = json_encode(['note'=>'no snapshot'], JSON_UNESCAPED_UNICODE);

  $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');

  $pdo->beginTransaction();

  $upd = $pdo->prepare("
    UPDATE tickets
       SET status='paid',
           closed_at=?,
           totals_snapshot=?,
           updated_at=?
     WHERE id=? AND store_id=?
     LIMIT 1
  ");
  $upd->execute([$now, $snapshotJson, $now, $ticketId, $storeId]);

  $pdo->commit();

  json_out([
    'ok' => true,
    'ticket_id' => $ticketId,
    'store_id' => $storeId,
    'status' => 'paid',
    'closed_at' => $now,
  ]);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  json_out(['ok'=>false,'error'=>'server error: '.$e->getMessage()], 500);
}