<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $a, int $code=200): void {
  http_response_code($code);
  echo json_encode($a, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

function ensure_manager_role(): void {
  if (function_exists('require_role')) {
    require_role(['manager','admin','super_user']);
  }
}

function i($v): int { return (int)round((float)$v); }

/**
 * totals_snapshot から「自動明細」を組み立てる（最小版）
 * - セット(set)
 * - 指名(shimei)
 * - 割引(discount)
 * - 税(tax)
 *
 * ※ ドリンクは今の totals_snapshot に入ってない前提なので、ここでは作らない
 */
function build_items_from_snapshot(array $snap): array {
  $bill = $snap['bill'] ?? null;
  if (!is_array($bill)) return [];

  $items = [];
  $line  = 1;

  $set = $bill['set'] ?? [];
  if (is_array($set)) {
    $set_fee = i($set['set_fee'] ?? 0);            // 税別
    $unit    = i($set['unit_price'] ?? 0);
    $cp      = i($set['charge_people'] ?? 0);

    if ($set_fee > 0) {
      $label = 'セット料金';
      if ($unit > 0 && $cp > 0) $label .= "（{$unit}×{$cp}）";

      $tax = (int)floor($set_fee * 0.10);
      $in  = $set_fee + $tax;

      $items[] = [
        'line_no' => $line++,
        'type' => 'set',
        'label' => $label,
        'unit_price' => $in,  // このテーブルは税込運用に寄せる（シンプル優先）
        'qty' => 1,
        'amount' => $in,
        'source_key' => 'set',
        'meta' => [
          'amount_ex' => $set_fee,
          'tax_amount' => $tax,
          'amount_in' => $in,
          'tax_rate' => 10,
        ],
      ];
    }

    $discount = i($set['discount'] ?? 0); // 税別割引として扱う
    if ($discount > 0) {
      $ex  = -$discount;
      $tax = (int)floor($ex * 0.10);      // マイナス税
      $in  = $ex + $tax;

      $items[] = [
        'line_no' => $line++,
        'type' => 'discount',
        'label' => '割引',
        'unit_price' => $in,
        'qty' => 1,
        'amount' => $in,
        'source_key' => 'discount',
        'meta' => [
          'amount_ex' => $ex,
          'tax_amount' => $tax,
          'amount_in' => $in,
          'tax_rate' => 10,
        ],
      ];
    }
  }

  $shimei_fee = i($bill['shimei_fee'] ?? 0); // 税別
  $shimei_hon = i($bill['shimei_hon'] ?? 0);

  if ($shimei_fee > 0) {
    $label = '指名料';
    if ($shimei_hon > 0) $label .= "（{$shimei_hon}本）";

    $tax = (int)floor($shimei_fee * 0.10);
    $in  = $shimei_fee + $tax;

    $items[] = [
      'line_no' => $line++,
      'type' => 'shimei',
      'label' => $label,
      'unit_price' => $in,
      'qty' => 1,
      'amount' => $in,
      'source_key' => 'shimei',
      'meta' => [
        'amount_ex' => $shimei_fee,
        'tax_amount' => $tax,
        'amount_in' => $in,
        'tax_rate' => 10,
      ],
    ];
  }

  $tax_total = i($bill['tax'] ?? 0);
  if ($tax_total !== 0) {
    // 税行（amount=税額）として1行作る
    $items[] = [
      'line_no' => $line++,
      'type' => 'tax',
      'label' => '消費税',
      'unit_price' => $tax_total,
      'qty' => 1,
      'amount' => $tax_total,
      'source_key' => 'tax',
      'meta' => [
        'tax_amount' => $tax_total,
      ],
    ];
  }

  return $items;
}

try {
  require_login();
  ensure_manager_role();

  $raw = file_get_contents('php://input') ?: '';
  $j = json_decode($raw, true);
  if (!is_array($j)) $j = [];

  $storeId  = (int)($j['store_id'] ?? 0);
  $ticketId = (int)($j['ticket_id'] ?? 0);
  $phrase   = (string)($j['phrase'] ?? '');

  if ($phrase !== 'LOCK') out(['ok'=>false,'error'=>'bad phrase'], 400);
  if ($storeId <= 0 || $ticketId <= 0) out(['ok'=>false,'error'=>'invalid params','got'=>['store_id'=>$storeId,'ticket_id'=>$ticketId]], 400);

  $pdo = db();
  $pdo->beginTransaction();

  $st = $pdo->prepare("SELECT id, store_id, status, totals_snapshot FROM tickets WHERE id=? AND store_id=? FOR UPDATE");
  $st->execute([$ticketId, $storeId]);
  $t = $st->fetch(PDO::FETCH_ASSOC);

  if (!$t) {
    $pdo->rollBack();
    out(['ok'=>false,'error'=>'ticket not found'], 404);
  }

  $status = (string)($t['status'] ?? 'open');
  if ($status === 'locked') { $pdo->commit(); out(['ok'=>true,'status'=>'locked','msg'=>'already locked']); }
  if ($status === 'paid' || $status === 'closed') { $pdo->commit(); out(['ok'=>true,'status'=>$status,'msg'=>'already paid']); }

  $snapJson = (string)($t['totals_snapshot'] ?? '');
  if ($snapJson === '') {
    $pdo->rollBack();
    out(['ok'=>false,'error'=>'snapshot empty. 先に「保存（DB）」して totals_snapshot を作ってからLOCKしてね'], 409);
  }

  $snap = json_decode($snapJson, true);
  if (!is_array($snap)) {
    $pdo->rollBack();
    out(['ok'=>false,'error'=>'snapshot json broken'], 409);
  }

  $items = build_items_from_snapshot($snap);
  if (!$items) {
    $pdo->rollBack();
    out(['ok'=>false,'error'=>'no items generated from snapshot'], 409);
  }

  // 自動明細だけ削除（手入力は残す運用）
  $del = $pdo->prepare("DELETE FROM ticket_items WHERE store_id=? AND ticket_id=? AND is_auto=1");
  $del->execute([$storeId, $ticketId]);

  // INSERT（created_at は必ず入れる：現テーブルが NOT NULL で default無しの可能性に備える）
  $ins = $pdo->prepare("
    INSERT INTO ticket_items
      (store_id, ticket_id, line_no, type, label, unit_price, qty, amount, meta, is_auto, source_key, created_at)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())
  ");

  foreach ($items as $it) {
    $meta = json_encode(($it['meta'] ?? []), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $ins->execute([
      $storeId,
      $ticketId,
      (int)$it['line_no'],
      (string)$it['type'],
      (string)$it['label'],
      (int)$it['unit_price'],
      (int)$it['qty'],
      (int)$it['amount'],
      $meta,
      (string)($it['source_key'] ?? ''),
    ]);
  }

  $now = date('Y-m-d H:i:s');
  $upd = $pdo->prepare("UPDATE tickets SET status='locked', locked_at=?, updated_at=? WHERE id=? AND store_id=?");
  $upd->execute([$now, $now, $ticketId, $storeId]);

  $pdo->commit();
  out(['ok'=>true,'status'=>'locked','locked_at'=>$now,'items_count'=>count($items)]);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  out(['ok'=>false,'error'=>$e->getMessage()], 500);
}