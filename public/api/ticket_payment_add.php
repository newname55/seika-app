<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';

require_login();
if (function_exists('require_role')) {
  require_role(['admin','manager','super_user']);
}

function jexit(array $a, int $code=200): void {
  http_response_code($code);
  echo json_encode($a, JSON_UNESCAPED_UNICODE);
  exit;
}
function json_input(): array {
  $raw = trim((string)file_get_contents('php://input'));
  if ($raw === '') return [];
  $j = json_decode($raw, true);
  if (!is_array($j)) jexit(['ok'=>false,'error'=>'Invalid JSON'], 400);
  return $j;
}
function current_user_id_safe(): int {
  if (function_exists('current_user_id')) return (int)current_user_id();
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  return (int)($_SESSION['user_id'] ?? 0);
}

function paid_total_for_ticket(PDO $pdo, int $ticketId): int {
  $st = $pdo->prepare("
    SELECT
      COALESCE(SUM(CASE WHEN status='captured' THEN amount ELSE 0 END),0)
      - COALESCE(SUM(CASE WHEN status='refunded' THEN amount ELSE 0 END),0) AS paid_total
    FROM ticket_payments
    WHERE ticket_id=?
  ");
  $st->execute([$ticketId]);
  return (int)($st->fetchColumn() ?: 0);
}

try {
  $in = json_input();

  $ticketId = (int)($in['ticket_id'] ?? 0);
  if ($ticketId <= 0) jexit(['ok'=>false,'error'=>'ticket_id required'], 400);

  $method = (string)($in['method'] ?? '');
  $amount = (int)($in['amount'] ?? 0);
  $paidAt = (string)($in['paid_at'] ?? '');
  $note   = (string)($in['note'] ?? null);
  $payerGroup = isset($in['payer_group']) ? (string)$in['payer_group'] : null;

  if (!in_array($method, ['cash','card','qr','transfer','invoice','other'], true)) {
    jexit(['ok'=>false,'error'=>'invalid method'], 400);
  }
  if ($amount <= 0) jexit(['ok'=>false,'error'=>'amount must be > 0'], 400);

  $actor = current_user_id_safe();
  if ($actor <= 0) jexit(['ok'=>false,'error'=>'not logged in'], 401);

  $pdo = db();
  $pdo->beginTransaction();

  $st = $pdo->prepare("SELECT id, status FROM tickets WHERE id=? FOR UPDATE");
  $st->execute([$ticketId]);
  $t = $st->fetch(PDO::FETCH_ASSOC);
  if (!$t) { $pdo->rollBack(); jexit(['ok'=>false,'error'=>'ticket not found'], 404); }
  if ((string)$t['status'] === 'void') { $pdo->rollBack(); jexit(['ok'=>false,'error'=>'ticket is void'], 400); }

  // paid_at 未指定なら NOW
  $paidAtSql = ($paidAt !== '') ? $paidAt : date('Y-m-d H:i:s');

  $ins = $pdo->prepare("
    INSERT INTO ticket_payments(
      ticket_id, method, amount, payer_group, status, external_ref, note,
      paid_at, created_by, created_at
    ) VALUES (
      ?, ?, ?, ?, 'captured', NULL, ?,
      ?, ?, NOW()
    )
  ");
  $ins->execute([$ticketId, $method, $amount, $payerGroup, $note, $paidAtSql, $actor]);

  // settlement がある（=locked済み）なら paid 判定＆balance更新
  $st = $pdo->prepare("SELECT total FROM ticket_settlements WHERE ticket_id=? FOR UPDATE");
  $st->execute([$ticketId]);
  $settle = $st->fetch(PDO::FETCH_ASSOC);

  $paidTotal = paid_total_for_ticket($pdo, $ticketId);

  $newStatus = (string)$t['status'];
  $total = 0;
  $balance = 0;

  if ($settle) {
    $total = (int)$settle['total'];
    $balance = max(0, $total - $paidTotal);

    $up = $pdo->prepare("UPDATE ticket_settlements SET paid_total=?, balance=? WHERE ticket_id=?");
    $up->execute([$paidTotal, $balance, $ticketId]);

    if ($total > 0 && $paidTotal >= $total) {
      $up = $pdo->prepare("
        UPDATE tickets
        SET status='paid',
            closed_at=COALESCE(closed_at, NOW()),
            updated_at=NOW()
        WHERE id=?
      ");
      $up->execute([$ticketId]);
      $newStatus = 'paid';
    }
  }

  // 監査
  $aud = $pdo->prepare("INSERT INTO ticket_audits(ticket_id, action, detail, actor_user_id, created_at) VALUES(?,?,?,?,NOW())");
  $aud->execute([$ticketId, 'payment_add', json_encode([
    'method'=>$method,'amount'=>$amount,'paid_total'=>$paidTotal,'status'=>$newStatus
  ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $actor]);

  $pdo->commit();

  jexit([
    'ok'=>true,
    'ticket_id'=>$ticketId,
    'status'=>$newStatus,
    'paid_total'=>$paidTotal,
    'total'=>$total,
    'balance'=>$balance,
  ]);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  jexit(['ok'=>false,'error'=>$e->getMessage()], 500);
}