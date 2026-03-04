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

function time_to_min(string $hhmm): int {
  if (!preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $m)) return 0;
  $h = (int)$m[1]; $min = (int)$m[2];
  $h = max(0, min(23, $h));
  $min = max(0, min(59, $min));
  return $h * 60 + $min;
}

/**
 * 単価ルール（現行）
 * 20:00〜20:29開始 = 6000
 * 20:30以降開始 = 7000
 */
function unit_price_by_start(string $start_time): int {
  // 20:00〜20:29 だけ 6000、それ以外は 7000（0時台も7000）
  $m = time_to_min($start_time);
  $m2000 = time_to_min('20:00');
  $m2030 = time_to_min('20:30');

  if ($m >= $m2000 && $m < $m2030) return 6000;
  return 7000;
}

/**
 * payload から「指名ユニーク数」を拾う（set.customers[*].shimei のキー）
 */
function unique_shimei_count_from_set(array $set): int {
  $uniq = [];
  $customers = $set['customers'] ?? null;
  if (!is_array($customers)) return 0;

  foreach ($customers as $custNo => $cust) {
    if (!is_array($cust)) continue;
    $mode = (string)($cust['mode'] ?? '');
    if ($mode !== 'shimei') continue;
    $sh = $cust['shimei'] ?? null;
    if (!is_array($sh)) continue;
    foreach ($sh as $castId => $kind) {
      $cid = (int)$castId;
      if ($cid > 0) $uniq[$cid] = 1;
    }
  }
  return count($uniq);
}

/**
 * サーバ側で bill を安定計算（プレビューと一致させる）
 * - ドリンクは amount を最優先（←ズレの主因を解消）
 * - tax は round（JS Math.round と合わせる）
 */
function compute_bill_from_payload(array $payload): array {
  $discount = (int)($payload['discount'] ?? 0);
  if ($discount < 0) $discount = 0;

  $sets = $payload['sets'] ?? [];
  if (!is_array($sets)) $sets = [];

  $set_total_ex    = 0;
  $vip_total_ex    = 0;
  $shimei_total_ex = 0;
  $drink_total_ex  = 0;

  $payload_start = (string)($payload['start_time'] ?? '20:00');
  if ($payload_start === '') $payload_start = '20:00';

  foreach ($sets as $set) {
    if (!is_array($set)) continue;

    $guest_people = (int)($set['guest_people'] ?? 0);
    $guest_people = max(0, $guest_people);

    $started_at = (string)($set['started_at'] ?? $payload_start);
    if ($started_at === '') $started_at = $payload_start;

    $unit = unit_price_by_start($started_at);

    $shimei_unique = unique_shimei_count_from_set($set);
    $charge_people = max($guest_people, $shimei_unique);

    // セット料金
    // ★レディース人数（0〜guest_peopleにクランプ）
    $ladies_people = (int)($set['ladies_people'] ?? 0);
    $ladies_people = max(0, min($guest_people, $ladies_people));

    // セット料金（通常単価×(課金人数-レディース) + レディース単価×レディース）
    $ladies_unit = 3500;

    // charge_people は従来通り max(来店人数, 指名ユニーク数)
    $normal_people = max(0, $charge_people - $ladies_people);

    $set_fee = ($unit * $normal_people) + ($ladies_unit * $ladies_people);
    $set_total_ex += $set_fee;

    // VIP（デフォルト：1セット固定 +10000）
    // もし将来「人数×」にしたければ payloadで vip_per_person=true を送れば対応
    $vip = (bool)($set['vip'] ?? false);
    if ($vip) {
      $vip_per_person = (bool)($set['vip_per_person'] ?? false);
      $vip_fee = 10000;
      $vip_total_ex += $vip_per_person ? ($vip_fee * $charge_people) : $vip_fee;
    }

    // 指名料（指名ユニーク数ベース / kindで単価）
    $kind = (string)($set['kind'] ?? 'normal50');
    $eff_kind = $kind;

    // 互換：同伴パックで指名0なら通常扱い
    if ($eff_kind === 'pack_douhan' && $shimei_unique === 0) $eff_kind = 'normal50';

    $shimei_per = 0;
    if ($eff_kind === 'half25') $shimei_per = 500;
    else if ($eff_kind === 'normal50') $shimei_per = 1000;
    else if ($eff_kind === 'pack_douhan') $shimei_per = 0;

    if ($shimei_unique > 0 && $shimei_per > 0) {
      $shimei_total_ex += $shimei_per * $shimei_unique;
    }

    // ドリンク（最重要：amount を優先）
    $drinks = $set['drinks'] ?? null;
    if (is_array($drinks)) {
      foreach ($drinks as $d) {
        if (!is_array($d)) continue;

        // ✅フロント互換：amount が来る
        $amount = (int)($d['amount'] ?? 0);
        if ($amount > 0) {
          $drink_total_ex += $amount;
          continue;
        }

        // 互換：price_ex/qty 形式も拾う
        $price = (int)($d['price_ex'] ?? $d['price'] ?? 0);
        $qty   = (int)($d['qty'] ?? 1);
        if ($price > 0 && $qty > 0) $drink_total_ex += $price * $qty;
      }
    }
  }

  $subtotal_ex = $set_total_ex + $vip_total_ex + $shimei_total_ex + $drink_total_ex - $discount;
  if ($subtotal_ex < 0) $subtotal_ex = 0;

  // ✅ JS(Math.round) と合わせる
  $tax = (int)round($subtotal_ex * 0.10);
  $total = $subtotal_ex + $tax;

  return [
    'subtotal_ex'  => $subtotal_ex,
    'tax'          => $tax,
    'total'        => $total,
    'discount'     => $discount,
    'set_total'    => $set_total_ex,
    'vip_total'    => $vip_total_ex,
    'shimei_total' => $shimei_total_ex,
    'drink_total'  => $drink_total_ex,
  ];
}

try {
  require_login();

  $in = json_input();
  $payload = $in['payload'] ?? null;

  // 互換： {payload_json:"..."} で来てもOK
  if (!is_array($payload) && isset($in['payload_json']) && is_string($in['payload_json'])) {
    $tmp = json_decode($in['payload_json'], true);
    if (is_array($tmp)) $payload = $tmp;
  }

  if (!is_array($payload)) json_out(['ok'=>false,'error'=>'invalid params: payload required'], 400);

  $bill = compute_bill_from_payload($payload);

  json_out([
    'ok'   => true,
    'bill' => $bill,
  ]);
} catch (Throwable $e) {
  json_out(['ok'=>false,'error'=>'server error: '.$e->getMessage()], 500);
}