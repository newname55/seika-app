<?php
declare(strict_types=1);

/**
 * 純粋な計算だけを置く（API出力・exit禁止）
 * ticket_calc.php から必要な関数群をここへ移植する
 *
 * 期待する返り値例:
 * [
 *   'subtotal_ex_tax'=>int,
 *   'tax'=>int,
 *   'total'=>int,
 *   'discount'=>int,
 *   'set_total'=>int,
 *   'vip_total'=>int,
 *   'shimei_total'=>int,
 *   'drink_total'=>int,
 *   // ...必要なら明細も
 * ]
 */

function ticket_payload_hash(array $payload): string {
  // JSONを安定化させて sha256
  // (キー順序を再帰的にソートしてからエンコード)
  $normalized = ticket_normalize_for_hash($payload);
  $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  return hash('sha256', $json ?: '');
}

function ticket_normalize_for_hash($v) {
  if (is_array($v)) {
    // 連想配列かリストか判定
    $isList = array_keys($v) === range(0, count($v) - 1);
    if ($isList) {
      return array_map('ticket_normalize_for_hash', $v);
    }
    ksort($v);
    $out = [];
    foreach ($v as $k => $vv) $out[$k] = ticket_normalize_for_hash($vv);
    return $out;
  }
  return $v;
}

/**
 * ここに、貼ってくれた ticket_calc.php 内の calc_bill(...) をそのまま移す
 * ※ 関数名/引数/返却は今のAPIに合わせてOK
 */
function calc_bill(array $payload): array {
  // TODO: あなたが貼ってくれた ticket_calc.php の calc_bill を丸ごとここへ移植
  // 例:
  // return [
  //   'subtotal_ex_tax' => ...,
  //   'tax' => ...,
  //   'total' => ...,
  //   'discount' => ...,
  //   'set_total' => ...,
  //   'vip_total' => ...,
  //   'shimei_total' => ...,
  //   'drink_total' => ...,
  //   'rows' => [...], // 任意
  // ];
  throw new RuntimeException('calc_bill not implemented in lib');
}