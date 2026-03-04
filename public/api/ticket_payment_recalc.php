<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';

header('Content-Type: application/json; charset=utf-8');

require_login();
if (function_exists('require_role')) {
  require_role(['admin','manager','super_user']);
}

$pdo = db();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function jexit(array $a, int $code=200): void {
  http_response_code($code);
  echo json_encode($a, JSON_UNESCAPED_UNICODE);
  exit;
}
function csrf_verify_safe(): void {
  if (function_exists('csrf_verify')) {
    $tok = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $ok = csrf_verify($tok);
    if ($ok === null) return;
    if (!$ok) jexit(['ok'=>false,'error'=>'CSRF invalid'], 403);
    return;
  }
  $tok = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
  $sess = $_SESSION['csrf_token'] ?? '';
  if (!$tok || !$sess || !hash_equals((string)$sess, (string)$tok)) {
    jexit(['ok'=>false,'error'=>'CSRF invalid'], 403);
  }
}

csrf_verify_safe();

$raw = file_get_contents('php://input') ?: '';
$j = json_decode($raw, true);
if (!is_array($j)) jexit(['ok'=>false,'error'=>'bad json'], 400);

$ticket_id = (int)($j['ticket_id'] ?? 0);
if ($ticket_id <= 0) jexit(['ok'=>false,'error'=>'ticket_id required'], 400);

$now = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');

try {
  $pdo->beginTransaction();
  $res = recalc_and_auto_paid($pdo, $ticket_id, $now);
  $pdo->commit();
  jexit(['ok'=>true,'recalc'=>$res]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  jexit(['ok'=>false,'error'=>$e->getMessage()], 500);
}

function recalc_and_auto_paid(PDO $pdo, int $ticket_id, string $now): array {
  $st = $pdo->prepare("SELECT ticket_id, total FROM ticket_settlements WHERE ticket_id=? FOR UPDATE");
  $st->execute([$ticket_id]);
  $settle = $st->fetch(PDO::FETCH_ASSOC);
  if (!$settle) return ['has_settlement'=>false];

  $total = (int)$settle['total'];

  $st = $pdo->prepare("
    SELECT
      COALESCE(SUM(CASE WHEN status='captured' THEN amount ELSE 0 END),0) AS captured_total,
      COALESCE(SUM(CASE WHEN status='refunded' THEN amount ELSE 0 END),0) AS refunded_total
    FROM ticket_payments
    WHERE ticket_id=?
  ");
  $st->execute([$ticket_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['captured_total'=>0,'refunded_total'=>0];

  $paid_total = ((int)$row['captured_total']) - ((int)$row['refunded_total']);
  $balance = $total - $paid_total;

  $st = $pdo->prepare("
    UPDATE ticket_settlements
    SET paid_total=?, balance=?,
        close_type = CASE
          WHEN ? >= total THEN 'paid'
          WHEN ? > 0 THEN 'partial'
          ELSE close_type
        END
    WHERE ticket_id=?
  ");
  $st->execute([$paid_total, $balance, $paid_total, $paid_total, $ticket_id]);

  $st = $pdo->prepare("SELECT status FROM tickets WHERE id=? FOR UPDATE");
  $st->execute([$ticket_id]);
  $t = $st->fetch(PDO::FETCH_ASSOC);
  $status = (string)($t['status'] ?? 'open');

  if ($paid_total >= $total) {
    if ($status !== 'paid') {
      $st = $pdo->prepare("UPDATE tickets SET status='paid', closed_at=COALESCE(closed_at, ?), updated_at=? WHERE id=?");
      $st->execute([$now, $now, $ticket_id]);
    } else {
      $st = $pdo->prepare("UPDATE tickets SET updated_at=? WHERE id=?");
      $st->execute([$now, $ticket_id]);
    }
    return ['has_settlement'=>true,'total'=>$total,'paid_total'=>$paid_total,'balance'=>$balance,'ticket_status'=>'paid'];
  }

  if ($status === 'paid') {
    $st = $pdo->prepare("UPDATE tickets SET status='locked', closed_at=NULL, updated_at=? WHERE id=?");
    $st->execute([$now, $ticket_id]);
    return ['has_settlement'=>true,'total'=>$total,'paid_total'=>$paid_total,'balance'=>$balance,'ticket_status'=>'locked'];
  }

  $st = $pdo->prepare("UPDATE tickets SET updated_at=? WHERE id=?");
  $st->execute([$now, $ticket_id]);

  return ['has_settlement'=>true,'total'=>$total,'paid_total'=>$paid_total,'balance'=>$balance,'ticket_status'=>$status];
}