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
function unit_price_by_start(string $start_time): int {
  // 20:00〜20:29 だけ 6000、それ以外は 7000（0時台も7000）
  $m = time_to_min($start_time);
  $m2000 = time_to_min('20:00');
  $m2030 = time_to_min('20:30');

  if ($m >= $m2000 && $m < $m2030) return 6000;
  return 7000;
}
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

  $set_no = 0;
  foreach ($sets as $set) {
    $set_no++;
    if (!is_array($set)) continue;

    $guest_people = (int)($set['guest_people'] ?? 0);
    $guest_people = max(0, $guest_people);

    $started_at = (string)($set['started_at'] ?? $payload_start);
    if ($started_at === '') $started_at = $payload_start;

    // =========================
    // kind（preview と同じルールに寄せる）
    // =========================
    $kind = (string)($set['kind'] ?? 'normal50');
    if (!in_array($kind, ['normal50','half25','pack_douhan'], true)) $kind = 'normal50';

    // 2セット目以降は同伴パック禁止 → normal50へ
    if ($set_no >= 2 && $kind === 'pack_douhan') $kind = 'normal50';

    // 同伴パックは 21:30以降開始なら通常へ（previewの FORCE_NORMAL_AFTER 相当）
    $force_normal_after = '21:30';
    if ($set_no === 1 && $kind === 'pack_douhan') {
      if (time_to_min($started_at) >= time_to_min($force_normal_after)) $kind = 'normal50';
    }

    // =========================
    // 指名ユニーク / 課金人数（従来通り）
    // =========================
    $shimei_unique = unique_shimei_count_from_set($set);
    $charge_people = max($guest_people, $shimei_unique);

    // =========================
    // セット単価（preview と同じ）
    // =========================
    if ($kind === 'half25') {
      $unit = 3500;
    } elseif ($kind === 'pack_douhan') {
      $unit = 13000;
    } else {
      // normal50 は開始時刻で 6000/7000
      $unit = unit_price_by_start($started_at);
    }

    // =========================
    // レディース人数（0〜guest_peopleにクランプ）
    // ※ DB保存のpayloadは「各setに ladies_people」を持たせる前提
    // =========================
    $ladies_people = (int)($set['ladies_people'] ?? 0);
    $ladies_people = max(0, min($guest_people, $ladies_people));

    // レディース単価（要件どおり固定 3500）
    $ladies_unit = 3500;

    // charge_people は従来通り max(来店人数, 指名ユニーク数)
    $normal_people = max(0, $charge_people - $ladies_people);

    // セット料金：通常単価×(課金人数-レディース) + レディース単価×レディース
    $set_fee = ($unit * $normal_people) + ($ladies_unit * $ladies_people);
    $set_total_ex += $set_fee;

    // =========================
    // VIP（既存のまま）
    // =========================
    $vip = (bool)($set['vip'] ?? false);
    if ($vip) {
      $vip_per_person = (bool)($set['vip_per_person'] ?? false);
      $vip_fee = 10000;
      $vip_total_ex += $vip_per_person ? ($vip_fee * $charge_people) : $vip_fee;
    }


    // 指名料
    $kind = (string)($set['kind'] ?? 'normal50');
    $eff_kind = $kind;
    if ($eff_kind === 'pack_douhan' && $shimei_unique === 0) $eff_kind = 'normal50';

    $shimei_per = 0;
    if ($eff_kind === 'half25') $shimei_per = 500;
    else if ($eff_kind === 'normal50') $shimei_per = 1000;
    else if ($eff_kind === 'pack_douhan') $shimei_per = 0;

    if ($shimei_unique > 0 && $shimei_per > 0) {
      $shimei_total_ex += $shimei_per * $shimei_unique;
    }

    // ✅ドリンク（amount優先）
    $drinks = $set['drinks'] ?? null;
    if (is_array($drinks)) {
      foreach ($drinks as $d) {
        if (!is_array($d)) continue;

        $amount = (int)($d['amount'] ?? 0);
        if ($amount > 0) {
          $drink_total_ex += $amount;
          continue;
        }

        $price = (int)($d['price_ex'] ?? $d['price'] ?? 0);
        $qty   = (int)($d['qty'] ?? 1);
        if ($price > 0 && $qty > 0) $drink_total_ex += $price * $qty;
      }
    }
  }

  $subtotal_ex = $set_total_ex + $vip_total_ex + $shimei_total_ex + $drink_total_ex - $discount;
  if ($subtotal_ex < 0) $subtotal_ex = 0;

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

function safe_json($v): string {
  return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

try {
  require_login();
  $pdo = db();

  $in = json_input();

  $ticket_id = (int)($in['ticket_id'] ?? 0);
  $payload   = $in['payload'] ?? null;

  if ($ticket_id <= 0) json_out(['ok'=>false,'error'=>'invalid params: ticket_id required'], 400);
  if (!is_array($payload)) json_out(['ok'=>false,'error'=>'invalid params: payload required'], 400);

  // billは来てもOK（プレビュー結果を送る運用）／来なくてもサーバで再計算して保存（ズレない）
  $bill_in = $in['bill'] ?? null;
  $bill = is_array($bill_in) ? $bill_in : null;

  // サーバで確定計算（常に計算して、最終はこれを保存）
  // ※フロントbillと比較してズレ検出したければ、ここで差分ログも可能
  $bill_server = compute_bill_from_payload($payload);

  $uid = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
  if ($uid <= 0) $uid = 1;
  // =========================
  // seat_id（代表席）を受け取る
  // - payload直下に seat_id を送る想定
  // - 0/未指定は NULL にする
  // =========================
  $seat_id_in = (int)($in['seat_id'] ?? 0);
  $seat_id = ($seat_id_in > 0) ? $seat_id_in : null;
    // top-level seat_id が無い場合は payload.sets[*].seat_id から拾う（代表席）
  if ($seat_id === null) {
    $sets = $payload['sets'] ?? null;
    if (is_array($sets)) {
      foreach ($sets as $s) {
        if (!is_array($s)) continue;
        $sid = (int)($s['seat_id'] ?? 0);
        if ($sid > 0) { $seat_id = $sid; break; }
      }
    }
  }
  // ticket存在チェック
  $st = $pdo->prepare("SELECT id, store_id, status FROM tickets WHERE id=? LIMIT 1");
  $st->execute([$ticket_id]);
  $t = $st->fetch(PDO::FETCH_ASSOC);
  if (!$t) json_out(['ok'=>false,'error'=>'ticket not found'], 404);

  // 会計済みロックを厳密にするならここで弾く（必要ならON）
  // if ((string)($t['status'] ?? '') === 'paid') json_out(['ok'=>false,'error'=>'ticket is paid'], 409);

  $snapshot = [
    'payload' => $payload,
    'bill'    => [
      'subtotal_ex'  => (int)$bill_server['subtotal_ex'],
      'tax'          => (int)$bill_server['tax'],
      'total'        => (int)$bill_server['total'],
      'discount'     => (int)$bill_server['discount'],
      'set_total'    => (int)$bill_server['set_total'],
      'vip_total'    => (int)$bill_server['vip_total'],
      'shimei_total' => (int)$bill_server['shimei_total'],
      'drink_total'  => (int)$bill_server['drink_total'],
    ],
    'client_bill' => is_array($bill) ? $bill : null, // 参考として保存（いらなければ消してOK）
    'saved_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s'),
    'saved_by' => $uid,
  ];

  $pdo->beginTransaction();

  // =========================
  // seat_id（現在席）更新 + 席移動ログ（安全運用）
  // =========================
  try {
    // seat_id カラムがまだ無い環境でも落とさない（導入途中の保険）
    $st = $pdo->prepare("SELECT store_id, seat_id FROM tickets WHERE id=? LIMIT 1 FOR UPDATE");
    $st->execute([$ticket_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('ticket not found');

    $store_id_db = (int)($row['store_id'] ?? 0);
    $beforeSeat  = isset($row['seat_id']) ? (int)$row['seat_id'] : null;
    $afterSeat   = $seat_id; // null or int

    $b = ($beforeSeat && $beforeSeat > 0) ? $beforeSeat : null;
    $a = ($afterSeat  && $afterSeat  > 0) ? (int)$afterSeat : null;

    if (($b ?? 0) !== ($a ?? 0)) {

      // ★移動先が無い場合はログを書かない（安全）
      if ($a !== null) {
        $ins = $pdo->prepare("
          INSERT INTO ticket_seat_moves
            (store_id, ticket_id, from_seat_id, to_seat_id, moved_by, reason, moved_at)
          VALUES
            (?, ?, ?, ?, ?, ?, NOW())
        ");
        $ins->execute([
          $store_id_db,
          $ticket_id,
          $b,
          $a,
          $uid,
          'ticket_save'
        ]);
      }

      // tickets の現在席を更新
      $updSeat = $pdo->prepare("UPDATE tickets SET seat_id=?, updated_at=NOW() WHERE id=? LIMIT 1");
      $updSeat->execute([$a, $ticket_id]);
    }
  } catch (Throwable $e) {
    // seat_id導入前などはここで落とさずスキップ（ただし本番では error_log 推奨）
    // error_log('[ticket_save][seat] '.$e->getMessage());
  }
  // ticket_inputs があれば保存（無ければスキップ）
  try {
    $sql = "
      INSERT INTO ticket_inputs (ticket_id, inputs, updated_at)
      VALUES (?, ?, NOW())
      ON DUPLICATE KEY UPDATE inputs=VALUES(inputs), updated_at=NOW()
    ";
    $ins = $pdo->prepare($sql);
    $ins->execute([$ticket_id, safe_json($payload)]);
  } catch (Throwable $e) {
    // テーブル無い/権限等は無視して続行
  }

  // tickets.totals_snapshot に保存（あなたの現行前提）
  $upd = $pdo->prepare("UPDATE tickets SET totals_snapshot=?, updated_at=NOW() WHERE id=? LIMIT 1");
  $upd->execute([safe_json($snapshot), $ticket_id]);

  // もし tickets に合計フィールドがあるなら更新（存在しなければ無視）
  try {
    $upd2 = $pdo->prepare("
      UPDATE tickets
      SET
        subtotal_ex = ?,
        tax = ?,
        total = ?
      WHERE id = ?
      LIMIT 1
    ");
    $upd2->execute([(int)$bill_server['subtotal_ex'], (int)$bill_server['tax'], (int)$bill_server['total'], $ticket_id]);
  } catch (Throwable $e) {
    // カラム無い等は無視
  }

  $pdo->commit();

  json_out([
    'ok' => true,
    'ticket_id' => $ticket_id,
    'bill' => $snapshot['bill'],
  ]);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  error_log('[ticket_save] '.$e->getMessage());
  json_out(['ok'=>false,'error'=>'server error: '.$e->getMessage()], 500);
}