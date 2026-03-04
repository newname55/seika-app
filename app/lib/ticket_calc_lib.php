<?php
declare(strict_types=1);

/**
 * ticket_calc_lib.php
 * - cashier.php の payload をサーバ側で確定計算する共通ライブラリ
 * - API(tickect_calc) / ticket_close(locked) / 分析処理 で再利用する
 *
 * 方針:
 * - 基本は「税込(total)」で確定・保存（現場の会計一致）
 * - キャスト分析は「税別(subtotal_ex_tax)」を基準にできるよう両方保持
 * - 税は 10% / 四捨五入（JS computePreview と合わせる）
 */

function tc_time_to_min(string $hhmm): int {
  $p = explode(':', $hhmm);
  $h = (int)($p[0] ?? 0);
  $m = (int)($p[1] ?? 0);
  return $h * 60 + $m;
}

function tc_normal50_unit_by_start(string $hhmm): int {
  // 20:00-20:29開始 = 6000 / 20:30以降 = 7000
  return (tc_time_to_min($hhmm) < (20 * 60 + 30)) ? 6000 : 7000;
}

function tc_round_tax(int $subtotal_ex, float $tax_rate = 0.10): int {
  // JS の Math.round 相当（四捨五入）
  return (int)round($subtotal_ex * $tax_rate, 0, PHP_ROUND_HALF_UP);
}

function tc_payload_hash(array $payload): string {
  // JSONとして同値なら同じhashになるように「それっぽく」安定化
  // （厳密なcanonicalが必要になったら後で強化でOK）
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  return hash('sha256', $json ?: '');
}

/**
 * cashier payload から、確定用の集計結果を返す
 *
 * return:
 * [
 *   'grand' => [
 *     'subtotal_ex_tax'=>int, 'tax'=>int, 'total'=>int, 'discount'=>int,
 *     'set_total'=>int,'vip_total'=>int,'shimei_total'=>int,'drink_total'=>int,
 *   ],
 *   'rows' => [...セット別...],
 *   'cast' => [...キャスト別...], // 将来の分析用（今は最低限）
 * ]
 */
function tc_calc_from_payload(array $payload): array {
  $tax_rate = 0.10;

  $discount = (int)($payload['discount'] ?? 0);
  if ($discount < 0) $discount = 0;

  $sets = $payload['sets'] ?? [];
  if (!is_array($sets)) $sets = [];

  $setTotal = 0;
  $vipTotal = 0;
  $shimeiTotal = 0;
  $drinkTotal = 0;

  $cast = [
    'shimei_fee'  => [],
    'drink_sales' => [],
    'pt'          => [],
  ];

  $rows = [];

  foreach ($sets as $idx => $s) {
    if (!is_array($s)) $s = [];

    $kind = (string)($s['kind'] ?? 'normal50');
    if (!in_array($kind, ['normal50','half25','pack_douhan'], true)) $kind = 'normal50';

    $guest = (int)($s['guest_people'] ?? 0);
    if ($guest < 0) $guest = 0;

    $started_at = (string)($s['started_at'] ?? ($payload['start_time'] ?? '20:00'));
    if ($started_at === '') $started_at = '20:00';

    $vip = !empty($s['vip']);

    // --- 指名の抽出（customers.mode='shimei' / customers[*].shimei の map）
    $customers = $s['customers'] ?? [];
    if (!is_array($customers)) $customers = [];

    $shMap = []; // no => hon/jounai（hon優先）
    for ($c=1; $c <= $guest; $c++) {
      $co = $customers[(string)$c] ?? null;
      if (!is_array($co)) continue;
      $mode = (string)($co['mode'] ?? 'free');
      if ($mode !== 'shimei') continue;

      $m = $co['shimei'] ?? [];
      if (!is_array($m)) continue;

      foreach ($m as $no => $knd) {
        $no_i = (int)$no;
        if ($no_i < 1 || $no_i >= 900) continue;
        $knd = ((string)$knd === 'hon') ? 'hon' : 'jounai';
        if (($shMap[(string)$no_i] ?? null) === 'hon') continue; // hon優先
        $shMap[(string)$no_i] = $knd;
      }
    }
    $shimeiList = array_map('intval', array_keys($shMap));
    sort($shimeiList);
    $shimeiCount = count($shimeiList);

    // pack_douhan で指名0なら normal扱い
    $effKind = $kind;
    if ($effKind === 'pack_douhan' && $shimeiCount === 0) $effKind = 'normal50';

    // 課金人数 = max(来店人数, 指名人数)
    $charge = max($guest, $shimeiCount);

    // セット単価
    if ($effKind === 'pack_douhan') {
      $unit = 13000;
    } elseif ($effKind === 'half25') {
      $unit = 3500; // 7000の半額固定扱い（あなたのUIに合わせ）
    } else {
      $unit = tc_normal50_unit_by_start($started_at);
    }

    $setFee = $unit * $charge;

    // VIP
    $vipFee = $vip ? 10000 : 0;

    // 指名料（pack_douhanは内包扱い＝追加なし）
    $shimeiFee = 0;
    if ($shimeiCount > 0 && $effKind !== 'pack_douhan') {
      $per = ($effKind === 'half25') ? 500 : 1000;
      $shimeiFee = $per * $shimeiCount;

      // キャスト按分（均等配分）
      $each = (int)floor($shimeiFee / $shimeiCount);
      foreach ($shimeiList as $no) {
        $k = (string)$no;
        $cast['shimei_fee'][$k] = (int)($cast['shimei_fee'][$k] ?? 0) + $each;
      }
    }

    // ドリンク
    $drinkSum = 0;
    $drinks = $s['drinks'] ?? [];
    if (!is_array($drinks)) $drinks = [];

    // freeの店番解決: customers[c].free[first/second/third]
    $resolveFreeToCast = function(int $custNo, string $role) use ($customers): int {
      $co = $customers[(string)$custNo] ?? null;
      if (!is_array($co)) return 0;
      $mode = (string)($co['mode'] ?? 'free');
      if ($mode !== 'free') return 0;
      $fr = $co['free'] ?? null;
      if (!is_array($fr)) return 0;
      $v = (int)($fr[$role] ?? 0);
      return ($v >= 1 && $v < 900) ? $v : 0;
    };

    foreach ($drinks as $d) {
      if (!is_array($d)) continue;
      $amount = (int)($d['amount'] ?? 0);
      if ($amount <= 0) continue;
      $drinkSum += $amount;

      $payer_type = (string)($d['payer_type'] ?? '');
      $payer_id   = (string)($d['payer_id'] ?? '');

      if ($payer_type === 'shimei') {
        $no = (int)$payer_id;
        if ($no >= 1 && $no < 900) {
          $k = (string)$no;
          $cast['drink_sales'][$k] = (int)($cast['drink_sales'][$k] ?? 0) + $amount;
        }
      } elseif ($payer_type === 'free') {
        // payer_id = "custNo:role"
        $parts = explode(':', $payer_id);
        $custNo = (int)($parts[0] ?? 0);
        $role   = (string)($parts[1] ?? '');
        if ($custNo >= 1 && in_array($role, ['first','second','third'], true)) {
          $no = $resolveFreeToCast($custNo, $role);
          if ($no) {
            $k = (string)$no;
            $cast['drink_sales'][$k] = (int)($cast['drink_sales'][$k] ?? 0) + $amount;
          }
        }
      }
    }

    $sub = $setFee + $vipFee + $shimeiFee + $drinkSum;

    $setTotal += $setFee;
    $vipTotal += $vipFee;
    $shimeiTotal += $shimeiFee;
    $drinkTotal += $drinkSum;

    $rows[] = [
      'no' => $idx + 1,
      'kind' => $effKind,
      'unit' => $unit,
      'started_at' => $started_at,
      'guest' => $guest,
      'shimei_count' => $shimeiCount,
      'charge' => $charge,
      'vip' => $vip ? 1 : 0,
      'set_fee' => $setFee,
      'vip_fee' => $vipFee,
      'shimei_fee' => $shimeiFee,
      'drink_sum' => $drinkSum,
      'sub' => $sub,
    ];
  }

  $subtotal_ex = max(0, $setTotal + $vipTotal + $shimeiTotal + $drinkTotal - $discount);
  $tax = tc_round_tax($subtotal_ex, $tax_rate);
  $total = $subtotal_ex + $tax;

  return [
    'grand' => [
      'subtotal_ex_tax' => $subtotal_ex,
      'tax' => $tax,
      'total' => $total,
      'discount' => $discount,
      'set_total' => $setTotal,
      'vip_total' => $vipTotal,
      'shimei_total' => $shimeiTotal,
      'drink_total' => $drinkTotal,
    ],
    'rows' => $rows,
    'cast' => $cast,
  ];
}