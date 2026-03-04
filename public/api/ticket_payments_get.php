<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';

require_login();
header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$ticket_id = (int)($_GET['ticket_id'] ?? 0);

if($ticket_id <= 0){
  echo json_encode(['ok'=>false,'error'=>'invalid_ticket']);
  exit;
}

$st = $pdo->prepare("SELECT * FROM tickets WHERE id=?");
$st->execute([$ticket_id]);
$ticket = $st->fetch(PDO::FETCH_ASSOC);

if(!$ticket){
  echo json_encode(['ok'=>false,'error'=>'ticket_not_found']);
  exit;
}

$st = $pdo->prepare("SELECT * FROM ticket_settlements WHERE ticket_id=?");
$st->execute([$ticket_id]);
$settlement = $st->fetch(PDO::FETCH_ASSOC);

if(!$settlement){
  echo json_encode(['ok'=>false,'error'=>'not_locked']);
  exit;
}

$st = $pdo->prepare("SELECT * FROM ticket_payments WHERE ticket_id=? ORDER BY paid_at");
$st->execute([$ticket_id]);
$payments = $st->fetchAll(PDO::FETCH_ASSOC);

$paid_total = 0;
foreach($payments as $p){
  if($p['status']==='captured'){
    $paid_total += (int)$p['amount'];
  } elseif($p['status']==='refunded'){
    $paid_total -= (int)$p['amount'];
  }
}

$balance = (int)$settlement['total'] - $paid_total;

echo json_encode([
  'ok'=>true,
  'ticket'=>$ticket,
  'settlement'=>$settlement,
  'payments'=>$payments,
  'paid_total'=>$paid_total,
  'balance'=>$balance
]);