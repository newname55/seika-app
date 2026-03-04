<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/layout.php';

require_login();
if (function_exists('require_role')) {
  require_role(['admin','manager','staff','super_user']);
}

$isEmbedded = ($_GET['embed'] ?? $_POST['embed'] ?? '') === '1';

$pdo = db();
$ticket_id = (int)($_GET['ticket_id'] ?? 0);
if ($ticket_id <= 0) { http_response_code(400); echo "ticket_id required"; exit; }

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/** 既存プロジェクト互換 */
function current_user_id_safe(): int {
  if (function_exists('current_user_id')) return (int)current_user_id();
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  return (int)($_SESSION['user_id'] ?? 0);
}

/** totals_snapshot から請求合計（税込）を取る（あなたの現行JSON構造に対応） */
function read_total_yen_from_snapshot(?string $json): int {
  $json = (string)$json;
  if ($json === '') return 0;
  $j = json_decode($json, true);
  if (!is_array($j)) return 0;

  // 現行: { payload:..., bill:{total:...}, saved_at..., saved_by... }
  if (isset($j['bill']) && is_array($j['bill']) && isset($j['bill']['total'])) {
    return (int)$j['bill']['total'];
  }
  // 予備: { totals:{total:...} } など
  if (isset($j['totals']) && is_array($j['totals']) && isset($j['totals']['total'])) {
    return (int)$j['totals']['total'];
  }
  if (isset($j['total'])) return (int)$j['total'];

  return 0;
}

/** 有効入金合計（capturedかつis_void=0のみ。refundedは差し引き） */
function calc_paid_total(PDO $pdo, int $ticket_id): int {
  $st = $pdo->prepare("
    SELECT
      COALESCE(SUM(CASE WHEN status='captured' AND is_void=0 THEN amount ELSE 0 END),0)
      - COALESCE(SUM(CASE WHEN status='refunded' AND is_void=0 THEN amount ELSE 0 END),0) AS paid_total
    FROM ticket_payments
    WHERE ticket_id=?
  ");
  $st->execute([$ticket_id]);
  return (int)($st->fetchColumn() ?: 0);
}

/** payer_group別の集計（capturedかつis_void=0のみ） */
function calc_paid_by_group(PDO $pdo, int $ticket_id): array {
  $st = $pdo->prepare("
    SELECT
      COALESCE(NULLIF(TRIM(payer_group),''), '（未指定）') AS grp,
      SUM(amount) AS amt
    FROM ticket_payments
    WHERE ticket_id=?
      AND status='captured'
      AND is_void=0
    GROUP BY COALESCE(NULLIF(TRIM(payer_group),''), '（未指定）')
    ORDER BY grp
  ");
  $st->execute([$ticket_id]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $out = [];
  foreach ($rows as $r) {
    $out[(string)$r['grp']] = (int)$r['amt'];
  }
  return $out;
}

/**
 * paid⇄open 自動判定
 * - 合計が取れない(0)ならステータスは触らない（途中入金のため）
 * - paid になっていて、取消などで不足したら open に戻す
 * - 逆に満額以上なら paid にする
 */
function reconcile_ticket_status(PDO $pdo, int $ticket_id, int $total_yen, int $paid_total): void {
  if ($total_yen <= 0) return;

  $st = $pdo->prepare("SELECT status FROM tickets WHERE id=? FOR UPDATE");
  $st->execute([$ticket_id]);
  $cur = (string)($st->fetchColumn() ?: '');

  if ($cur === 'void') return;

  if ($paid_total >= $total_yen) {
    if ($cur !== 'paid') {
      $pdo->prepare("
        UPDATE tickets
           SET status='paid',
               closed_at=COALESCE(closed_at, NOW()),
               updated_at=NOW()
         WHERE id=? AND status<>'void'
      ")->execute([$ticket_id]);
    }
  } else {
    // 不足なら open に戻す（paid → open）
    if ($cur === 'paid') {
      $pdo->prepare("
        UPDATE tickets
           SET status='open',
               closed_at=NULL,
               updated_at=NOW()
         WHERE id=? AND status='paid'
      ")->execute([$ticket_id]);
    }
  }
}

$err = null;

/* =========================
   POST actions
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $action = (string)($_POST['action'] ?? 'add');

    $pdo->beginTransaction();

    // ticket lock + 取得
    $st = $pdo->prepare("
      SELECT id, store_id, business_date, barcode_value, status, totals_snapshot
      FROM tickets
      WHERE id=?
      FOR UPDATE
    ");
    $st->execute([$ticket_id]);
    $ticket = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) throw new RuntimeException('ticket not found');
    if ((string)$ticket['status'] === 'void') throw new RuntimeException('ticket is void');

    $total_yen = read_total_yen_from_snapshot((string)($ticket['totals_snapshot'] ?? ''));

    if ($action === 'add') {
      $method = (string)($_POST['method'] ?? 'cash');
      $amount = (int)($_POST['amount'] ?? 0);
      $payer_group = trim((string)($_POST['payer_group'] ?? ''));
      $note = trim((string)($_POST['note'] ?? ''));
      $paid_at = trim((string)($_POST['paid_at'] ?? ''));
      if ($paid_at === '') $paid_at = date('Y-m-d H:i:s');

      if ($amount <= 0) throw new RuntimeException('金額は 1以上で入力してください');

      $allowed = ['cash','card','qr','transfer','invoice','other'];
      if (!in_array($method, $allowed, true)) throw new RuntimeException('methodが不正です');

      // insert（created_by 必須 / updated_atあり）
      $pdo->prepare("
        INSERT INTO ticket_payments
          (ticket_id, method, amount, payer_group, status, is_void, external_ref, note, paid_at, created_by, created_at, updated_at)
        VALUES
          (?, ?, ?, NULLIF(?,''), 'captured', 0, NULL, NULLIF(?,''), ?, ?, NOW(), NOW())
      ")->execute([
        $ticket_id,
        $method,
        $amount,
        $payer_group,
        $note,
        $paid_at,
        current_user_id_safe(),
      ]);

    } elseif ($action === 'void') {
      $pid = (int)($_POST['payment_id'] ?? 0);
      if ($pid <= 0) throw new RuntimeException('payment_id required');

      // 対象がこのticketのものか確認して void
      $st = $pdo->prepare("SELECT id, is_void, status FROM ticket_payments WHERE id=? AND ticket_id=? FOR UPDATE");
      $st->execute([$pid, $ticket_id]);
      $p = $st->fetch(PDO::FETCH_ASSOC);
      if (!$p) throw new RuntimeException('payment not found');

      if ((int)$p['is_void'] === 1 || (string)$p['status'] === 'void') {
        // 既に取消なら何もしない
      } else {
        $pdo->prepare("
          UPDATE ticket_payments
             SET is_void=1,
                 status='void',
                 updated_at=NOW()
           WHERE id=? AND ticket_id=?
        ")->execute([$pid, $ticket_id]);
      }

    } elseif ($action === 'unvoid') {
      $pid = (int)($_POST['payment_id'] ?? 0);
      if ($pid <= 0) throw new RuntimeException('payment_id required');

      $st = $pdo->prepare("SELECT id, is_void, status FROM ticket_payments WHERE id=? AND ticket_id=? FOR UPDATE");
      $st->execute([$pid, $ticket_id]);
      $p = $st->fetch(PDO::FETCH_ASSOC);
      if (!$p) throw new RuntimeException('payment not found');

      // 復活（capturedへ戻す）
      $pdo->prepare("
        UPDATE ticket_payments
           SET is_void=0,
               status='captured',
               updated_at=NOW()
         WHERE id=? AND ticket_id=?
      ")->execute([$pid, $ticket_id]);

    } else {
      throw new RuntimeException('unknown action');
    }

    // paid⇄open 自動判定
    $paid_total = calc_paid_total($pdo, $ticket_id);
    reconcile_ticket_status($pdo, $ticket_id, $total_yen, $paid_total);

    $pdo->commit();

    if (!$isEmbedded) {
        header("Location: payments.php?ticket_id=".$ticket_id);
        exit;
    }

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $err = $e->getMessage();
  }
}

/* =========================
   load view data
========================= */
$st = $pdo->prepare("
  SELECT id, store_id, business_date, ticket_no, barcode_value, status, opened_at, locked_at, closed_at, totals_snapshot
  FROM tickets WHERE id=?
");
$st->execute([$ticket_id]);
$ticket = $st->fetch(PDO::FETCH_ASSOC);
if (!$ticket) { http_response_code(404); echo "ticket not found"; exit; }

$total_yen = read_total_yen_from_snapshot((string)($ticket['totals_snapshot'] ?? ''));
$paid_total = calc_paid_total($pdo, $ticket_id);
$balance = max(0, $total_yen - $paid_total);
$change  = max(0, $paid_total - $total_yen);

$by_group = calc_paid_by_group($pdo, $ticket_id);

$st = $pdo->prepare("
  SELECT id, method, amount, payer_group, status, is_void, note, paid_at, created_at, updated_at
  FROM ticket_payments
  WHERE ticket_id=?
  ORDER BY paid_at DESC, id DESC
");
$st->execute([$ticket_id]);
$payments = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

// UIラベル
function method_label(string $m): string {
  return match($m){
    'cash' => '現金',
    'card' => 'カード',
    'qr' => 'QR',
    'transfer' => '振込',
    'invoice' => '請求',
    'other' => 'その他',
    default => $m,
  };
}
function status_pill(string $status, int $is_void): array {
  if ($is_void === 1 || $status === 'void') return ['取消', 'pill-void'];
  return match($status){
    'captured' => ['入金', 'pill-cap'],
    'pending' => ['保留', 'pill-pend'],
    'refunded' => ['返金', 'pill-ref'],
    default => [$status, 'pill'],
  };
}
function ticket_status_label(string $s): array {
  return match($s){
    'open' => ['未精算', 't-open'],
    'locked' => ['確定', 't-locked'],
    'paid' => ['入金完了', 't-paid'],
    'void' => ['取消', 't-void'],
    default => [$s, 't-open'],
  };
}

[$tlabel, $tcls] = ticket_status_label((string)$ticket['status']);

?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>入金（Ticket #<?= (int)$ticket_id ?>）</title>
<style>
  :root{
    --bg:#0b1020;
    --card:#121a33;
    --card2:#0f1730;
    --txt:#e8ecff;
    --muted:#aab3d6;
    --line:rgba(255,255,255,.12);
    --ok:#39d98a;
    --warn:#ffcc66;
    --bad:#ff6b6b;
    --blue:#2b6bff;
  }
  body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:var(--bg);color:var(--txt);margin:0}
  .wrap{max-width:1200px;margin:0 auto;padding:16px}
  .top{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start;justify-content:space-between}
  .card{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:14px}
  .muted{color:var(--muted);font-size:12px}
  .key{font-weight:900}
  .grid{display:grid;grid-template-columns:1.15fr .85fr;gap:12px}
  @media (max-width: 980px){
    .grid{grid-template-columns:1fr}
  }

  input,select,textarea{
    background:var(--card2);color:var(--txt);
    border:1px solid rgba(255,255,255,.15);
    border-radius:12px;padding:12px;font-size:16px;
  }
  input[type="number"]{font-size:22px;font-weight:900}
  .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
  .btn{
    border:0;border-radius:12px;
    padding:10px 12px;font-weight:900;
    cursor:pointer;user-select:none;
  }
  .b-ok{background:var(--ok);color:#07110a}
  .b-blue{background:var(--blue);color:#fff}
  .b-warn{background:var(--warn);color:#121212}
  .b-ghost{background:transparent;border:1px solid rgba(255,255,255,.18);color:var(--txt)}
  .b-bad{background:var(--bad);color:#180606}

  .pill{
    display:inline-flex;align-items:center;gap:6px;
    border:1px solid rgba(255,255,255,.18);
    border-radius:999px;padding:4px 10px;font-size:12px;
    background:rgba(255,255,255,.06);
  }
  .pill-cap{border-color:rgba(57,217,138,.5);background:rgba(57,217,138,.12)}
  .pill-void{border-color:rgba(255,107,107,.55);background:rgba(255,107,107,.12)}
  .pill-pend{border-color:rgba(255,204,102,.55);background:rgba(255,204,102,.12)}
  .pill-ref{border-color:rgba(43,107,255,.55);background:rgba(43,107,255,.12)}

  .t-open{border-color:rgba(43,107,255,.55);background:rgba(43,107,255,.12)}
  .t-locked{border-color:rgba(255,204,102,.55);background:rgba(255,204,102,.12)}
  .t-paid{border-color:rgba(57,217,138,.55);background:rgba(57,217,138,.12)}
  .t-void{border-color:rgba(255,107,107,.55);background:rgba(255,107,107,.12)}

  table{width:100%;border-collapse:collapse}
  th,td{border-bottom:1px solid rgba(255,255,255,.10);padding:10px;text-align:left;vertical-align:top}
  th{color:var(--muted);font-size:12px;white-space:nowrap}
  .mono{font-variant-numeric:tabular-nums}
  .right{text-align:right}
  .small{font-size:12px}
  .amt{font-weight:900;font-size:18px}
  .sectionTitle{font-weight:900;font-size:16px;margin:0 0 8px 0}
  .kpi{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
  @media (max-width: 520px){ .kpi{grid-template-columns:1fr} }
  .kpi .box{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.10);border-radius:14px;padding:12px}
  .quick{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
  .quick button{padding:12px;border-radius:12px}
</style>
</head>
<body>
<div class="wrap">

  <div class="top">
    <div>
      <div class="muted">入金</div>
      <div class="key" style="font-size:20px;">
        🧾 伝票 #<?= (int)$ticket_id ?>
        <span class="pill <?= h($tcls) ?>"><?= h($tlabel) ?></span>
      </div>
      <div class="muted">
        営業日 <?= h((string)$ticket['business_date']) ?> /
        barcode <?= h((string)$ticket['barcode_value']) ?>
      </div>
    </div>
    <div class="row">
      <button class="btn b-blue" type="button"
        onclick="location.href='/seika-app/public/cashier/cashier.php?ticket_id=<?= (int)$ticket_id ?>&embed=1'">
        ← 伝票に戻る
      </button>
      <button class="btn b-warn" onclick="location.href='/seika-app/public/cashier/index.php?store_id=<?= (int)$ticket['store_id'] ?>'">会計一覧</button>
    </div>
  </div>

  <?php if ($err): ?>
    <div class="card" style="border-color:var(--bad);margin-top:12px">
      <div class="key">エラー</div>
      <div><?= h($err) ?></div>
    </div>
  <?php endif; ?>

  <div class="grid" style="margin-top:12px">

    <!-- left -->
    <div class="card">
      <div class="sectionTitle">入金サマリ</div>

      <div class="kpi">
        <div class="box">
          <div class="muted">請求合計（税込）</div>
          <div class="amt mono"><?= number_format($total_yen) ?> 円</div>
          <div class="muted small">※ totals_snapshot(bill.total) から取得</div>
        </div>
        <div class="box">
          <div class="muted">入金合計（captured）</div>
          <div class="amt mono"><?= number_format($paid_total) ?> 円</div>
          <div class="muted small">※ 取消(is_void=1)は除外</div>
        </div>
        <div class="box">
          <div class="muted">残額 / お釣り</div>
          <div class="amt mono"><?= number_format($balance) ?> 円</div>
          <div class="muted small">お釣り <?= number_format($change) ?> 円</div>
        </div>
      </div>

      <div style="height:10px"></div>

      <div class="card" style="background:rgba(255,255,255,.03);border-color:rgba(255,255,255,.10)">
        <div class="sectionTitle" style="margin-bottom:6px">+ 入金追加</div>

        <form method="post"
      action="payments.php?ticket_id=<?= (int)$ticket_id ?>&embed=<?= $isEmbedded ? '1' : '0' ?>"
      class="row">
          <input type="hidden" name="action" value="add">

          <div style="flex:0 0 160px;min-width:160px">
            <div class="muted">方法</div>
            <select name="method" required style="width:100%">
              <option value="cash">現金</option>
              <option value="card">カード</option>
              <option value="qr">QR</option>
              <option value="transfer">振込</option>
              <option value="invoice">請求</option>
              <option value="other">その他</option>
            </select>
          </div>

          <div style="flex:1 1 240px;min-width:240px">
            <div class="muted">金額</div>
            <input id="amount" type="number" name="amount" min="1" inputmode="numeric" placeholder="0" required style="width:100%">
          </div>

          <div style="flex:1 1 200px;min-width:200px">
            <div class="muted">payer_group（割り勘）</div>
            <input type="text" name="payer_group" placeholder="例: A / B / 客1" style="width:100%">
          </div>

          <div style="flex:2 1 260px;min-width:260px">
            <div class="muted">メモ</div>
            <input type="text" name="note" placeholder="任意" style="width:100%">
          </div>

          <div style="flex:1 1 240px;min-width:240px">
            <div class="muted">paid_at（空なら今）</div>
            <input type="text" name="paid_at" placeholder="YYYY-mm-dd HH:ii:ss" style="width:100%">
          </div>

          <div style="flex:0 0 120px;min-width:120px;align-self:flex-end">
            <button class="btn b-ok" type="submit" style="width:100%">追加</button>
          </div>
        </form>

        <div style="height:10px"></div>

        <div class="muted">クイック入力（よく使う額）</div>
        <div class="quick" style="margin-top:8px">
          <button class="btn b-ghost" type="button" onclick="setAmt(10000)">1万円</button>
          <button class="btn b-ghost" type="button" onclick="setAmt(20000)">2万円</button>
          <button class="btn b-ghost" type="button" onclick="setAmt(50000)">5万円</button>
        </div>
      </div>

      <div style="height:12px"></div>

      <div class="sectionTitle">割り勘集計（payer_group）</div>
      <?php if (!$by_group): ?>
        <div class="muted">まだありません</div>
      <?php else: ?>
        <table>
          <thead><tr><th>payer_group</th><th class="right">合計</th></tr></thead>
          <tbody>
            <?php foreach ($by_group as $grp => $amt): ?>
              <tr>
                <td><?= h($grp) ?></td>
                <td class="right key mono"><?= number_format($amt) ?> 円</td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

    </div>

    <!-- right -->
    <div class="card">
      <div class="sectionTitle">入金履歴</div>

      <?php if (!$payments): ?>
        <div class="muted">まだありません</div>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>日時</th>
              <th>状態</th>
              <th>方法</th>
              <th class="right">金額</th>
              <th>payer_group</th>
              <th>メモ</th>
              <th class="right">操作</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($payments as $p): ?>
              <?php
                $pid = (int)$p['id'];
                $is_void = (int)$p['is_void'];
                $status = (string)$p['status'];
                [$plabel, $pcls] = status_pill($status, $is_void);
              ?>
              <tr>
                <td class="mono">
                  <?= h((string)$p['paid_at']) ?>
                  <div class="muted small">#<?= $pid ?></div>
                </td>
                <td><span class="pill <?= h($pcls) ?>"><?= h($plabel) ?></span></td>
                <td><?= h(method_label((string)$p['method'])) ?></td>
                <td class="right key mono"><?= number_format((int)$p['amount']) ?></td>
                <td><?= h((string)($p['payer_group'] ?? '')) ?></td>
                <td><?= h((string)($p['note'] ?? '')) ?></td>
                <td class="right">
                  <?php if ($is_void === 0 && $status !== 'void'): ?>
                    <form method="post"
      action="payments.php?ticket_id=<?= (int)$ticket_id ?>&embed=<?= $isEmbedded ? '1' : '0' ?>"
      class="row">
                      <input type="hidden" name="action" value="void">
                      <input type="hidden" name="payment_id" value="<?= $pid ?>">
                      <button class="btn b-bad" type="submit" onclick="return confirm('この入金を取消しますか？')">取消</button>
                    </form>
                  <?php else: ?>
                    <form method="post"
      action="payments.php?ticket_id=<?= (int)$ticket_id ?>&embed=<?= $isEmbedded ? '1' : '0' ?>"
      class="row">
                      <input type="hidden" name="action" value="unvoid">
                      <input type="hidden" name="payment_id" value="<?= $pid ?>">
                      <button class="btn b-ghost" type="submit" onclick="return confirm('この入金を復活しますか？')">復活</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <div class="muted" style="margin-top:10px">
        ※取消は is_void=1 + status='void' にします。取消後に不足が出たら tickets.status を paid→open に自動で戻します。
      </div>
    </div>

  </div><!-- grid -->

</div>

<script>
  if (window.top !== window.self) {
  console.log('embedded mode');
}
function setAmt(v){
  const el = document.getElementById('amount');
  if (!el) return;
  el.value = String(v);
  el.focus();
  el.select?.();
}
</script>

</body>
</html>