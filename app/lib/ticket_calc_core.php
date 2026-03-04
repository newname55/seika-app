<?php
declare(strict_types=1);

/**
 * 入力payloadから会計計算を行う
 * @param array $payload ticket_inputs.inputs のJSONをdecodeしたもの
 * @return array 計算結果
 */
function compute_ticket_totals(array $payload): array
{
    $subtotal_ex_tax = 0;
    $tax_rate = 0.10; // 必要ならDBから取得に変更可能

    $set_total = 0;
    $vip_total = 0;
    $shimei_total = 0;
    $drink_total = 0;
    $discount = 0;

    // ---- セット
    foreach (($payload['sets'] ?? []) as $row) {
        $set_total += (int)$row['price'];
    }

    // ---- VIP
    foreach (($payload['vip'] ?? []) as $row) {
        $vip_total += (int)$row['price'];
    }

    // ---- 指名
    foreach (($payload['shimei'] ?? []) as $row) {
        $shimei_total += (int)$row['price'];
    }

    // ---- ドリンク
    foreach (($payload['drinks'] ?? []) as $row) {
        $drink_total += ((int)$row['price'] * (int)$row['qty']);
    }

    // ---- 割引
    $discount = (int)($payload['discount'] ?? 0);

    $subtotal_ex_tax =
        $set_total +
        $vip_total +
        $shimei_total +
        $drink_total -
        $discount;

    if ($subtotal_ex_tax < 0) {
        $subtotal_ex_tax = 0;
    }

    $tax = (int)floor($subtotal_ex_tax * $tax_rate);
    $total = $subtotal_ex_tax + $tax;

    return [
        'subtotal_ex_tax' => $subtotal_ex_tax,
        'tax'             => $tax,
        'total'           => $total,
        'discount'        => $discount,
        'set_total'       => $set_total,
        'vip_total'       => $vip_total,
        'shimei_total'    => $shimei_total,
        'drink_total'     => $drink_total,
    ];
}