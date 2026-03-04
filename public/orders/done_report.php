<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/attendance.php';
require_once __DIR__ . '/../../app/layout.php';

$storeLib = __DIR__ . '/../../app/store.php';
if (is_file($storeLib)) require_once $storeLib;

require_login();
require_role(['admin','manager','super_user','staff']);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function ticket_status_badge(string $st): array {
  $st = strtolower(trim($st));

  if ($st === 'paid') {
    return ['label' => '会計済', 'cls' => 'paid', 'icon' => '✅'];
  }

  if ($st === 'void') {
    return ['label' => '取消', 'cls' => 'void', 'icon' => '⛔'];
  }

  if ($st === 'locked') {
    return ['label' => '精算待', 'cls' => 'locked', 'icon' => '🧾'];
  }

  return ['label' => '未会計', 'cls' => 'open', 'icon' => '🟡'];
}

$pdo = db();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// 店舗選択
$store_id = function_exists('att_safe_store_id') ? (int)att_safe_store_id() : (int)($_SESSION['store_id'] ?? 0);
if ($store_id <= 0) {
  header('Location: /seika-app/public/store_select.php?next=' . urlencode($_SERVER['REQUEST_URI']));
  exit;
}
if (!function_exists('current_store_id')) {
  function current_store_id(): ?int { return isset($_SESSION['store_id']) ? (int)$_SESSION['store_id'] : null; }
}
$_SESSION['store_id'] = $store_id;

// 期間
$from = (string)($_GET['from'] ?? date('Y-m-d'));
$to   = (string)($_GET['to']   ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');

$toPlus = (new DateTime($to))->modify('+1 day')->format('Y-m-d');

$DONE_STATUS = 'done';

// ===============================
// 1) 全体合計（本数・金額）
// ===============================
$sqlAll = "
SELECT
  COALESCE(SUM(oi.qty),0) AS total_qty,
  COALESCE(SUM(oi.qty * COALESCE(m.price_ex,0)),0) AS total_amount_ex
FROM order_orders o
JOIN order_order_items oi
  ON oi.order_id = o.id AND oi.store_id = o.store_id
LEFT JOIN order_menus m
  ON m.id = oi.menu_id AND m.store_id = oi.store_id
WHERE
  o.store_id = ?
  AND o.status = ?
  AND o.created_at >= ?
  AND o.created_at < ?
";
$st = $pdo->prepare($sqlAll);
$st->execute([$store_id, $DONE_STATUS, $from . ' 00:00:00', $toPlus . ' 00:00:00']);
$all = $st->fetch(PDO::FETCH_ASSOC) ?: ['total_qty'=>0,'total_amount_ex'=>0];

$totalQty = (int)($all['total_qty'] ?? 0);
$totalAmountEx = (int)($all['total_amount_ex'] ?? 0);

// ===============================
// 2) メニュー別合計（何が何本）
// ===============================
$sqlMenu = "
SELECT
  oi.menu_id,
  COALESCE(m.name, CONCAT('menu#', oi.menu_id)) AS menu_name,
  COALESCE(m.price_ex,0) AS price_ex,
  SUM(oi.qty) AS total_qty,
  SUM(oi.qty * COALESCE(m.price_ex,0)) AS total_amount_ex
FROM order_orders o
JOIN order_order_items oi
  ON oi.order_id = o.id AND oi.store_id = o.store_id
LEFT JOIN order_menus m
  ON m.id = oi.menu_id AND m.store_id = oi.store_id
WHERE
  o.store_id = ?
  AND o.status = ?
  AND o.created_at >= ?
  AND o.created_at < ?
GROUP BY oi.menu_id, menu_name, price_ex
ORDER BY total_qty DESC, menu_name ASC
";
$st = $pdo->prepare($sqlMenu);
$st->execute([$store_id, $DONE_STATUS, $from . ' 00:00:00', $toPlus . ' 00:00:00']);
$menuTotals = $st->fetchAll(PDO::FETCH_ASSOC);
// ------------------------------
// 3) 伝票ごとの合計（本数・金額）
// ------------------------------
$sqlTicketTotals = "
SELECT
  o.ticket_id,
  tk.seat_id,
  tk.status AS ticket_status,
  SUM(oi.qty) AS total_qty,
  SUM(oi.qty * COALESCE(m.price_ex, 0)) AS total_amount_ex
FROM order_orders o
JOIN order_order_items oi
  ON oi.order_id = o.id AND oi.store_id = o.store_id
LEFT JOIN order_menus m
  ON m.id = oi.menu_id AND m.store_id = oi.store_id
LEFT JOIN tickets tk
  ON tk.id = o.ticket_id
WHERE
  o.store_id = ?
  AND o.status = ?
  AND o.created_at >= ?
  AND o.created_at < ?
  AND o.ticket_id IS NOT NULL
GROUP BY o.ticket_id, tk.seat_id, tk.status
ORDER BY total_amount_ex DESC, o.ticket_id DESC
";

$stT = $pdo->prepare($sqlTicketTotals);
$stT->execute([$store_id, $DONE_STATUS, $from . ' 00:00:00', $toPlus . ' 00:00:00']);
$ticketTotals = $stT->fetchAll(PDO::FETCH_ASSOC);
// ===============================
// 4) 卓別合計（卓ごとの本数＋金額）
// ===============================
$sqlTable = "
SELECT
  o.table_id,
  t.table_no,
  COALESCE(t.name, CONCAT('table#', o.table_id)) AS table_name,
  SUM(oi.qty) AS total_qty,
  SUM(oi.qty * COALESCE(m.price_ex,0)) AS total_amount_ex
FROM order_orders o
JOIN order_order_items oi
  ON oi.order_id = o.id AND oi.store_id = o.store_id
LEFT JOIN order_tables t
  ON t.id = o.table_id AND t.store_id = o.store_id
LEFT JOIN order_menus m
  ON m.id = oi.menu_id AND m.store_id = oi.store_id
WHERE
  o.store_id = ?
  AND o.status = ?
  AND o.created_at >= ?
  AND o.created_at < ?
GROUP BY o.table_id, t.table_no, table_name
ORDER BY total_amount_ex DESC, total_qty DESC
";
$st = $pdo->prepare($sqlTable);
$st->execute([$store_id, $DONE_STATUS, $from . ' 00:00:00', $toPlus . ' 00:00:00']);
$tableTotals = $st->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// 5) 提供済み（done）注文一覧（下に表示）
// ===============================
$limit = max(20, min(300, (int)($_GET['limit'] ?? 80)));

$sqlOrders = "
SELECT
  o.id, o.table_id, o.status, o.note, o.created_at,
  t.table_no, t.name AS table_name
FROM order_orders o
LEFT JOIN order_tables t
  ON t.id = o.table_id AND t.store_id = o.store_id
WHERE
  o.store_id = ?
  AND o.status = ?
  AND o.created_at >= ?
  AND o.created_at < ?
ORDER BY o.created_at DESC
LIMIT {$limit}
";
$st = $pdo->prepare($sqlOrders);
$st->execute([$store_id, $DONE_STATUS, $from . ' 00:00:00', $toPlus . ' 00:00:00']);
$orders = $st->fetchAll(PDO::FETCH_ASSOC);

$orderIds = [];
foreach ($orders as $o) $orderIds[] = (int)$o['id'];

$itemsByOrder = [];
if ($orderIds) {
  $in = implode(',', array_fill(0, count($orderIds), '?'));
  $params = array_merge([$store_id], $orderIds);

  $sqlItems = "
    SELECT
      oi.order_id, oi.menu_id, oi.qty, oi.note AS item_note,
      COALESCE(m.name, CONCAT('menu#', oi.menu_id)) AS menu_name
    FROM order_order_items oi
    LEFT JOIN order_menus m
      ON m.id = oi.menu_id AND m.store_id = oi.store_id
    WHERE
      oi.store_id = ?
      AND oi.order_id IN ($in)
    ORDER BY oi.order_id DESC, oi.id ASC
  ";
  $st2 = $pdo->prepare($sqlItems);
  $st2->execute($params);
  foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $oid = (int)$r['order_id'];
    $itemsByOrder[$oid][] = $r;
  }
}

$right_html = '
  <a class="btn" href="/seika-app/public/orders/kitchen.php">🍳 キッチン</a>
  <a class="btn" href="/seika-app/public/orders/index.php?table=1">🛎️ 注文</a>
';

render_page_start('提供済みレポート');
render_header('提供済みレポート', [
  'back_href'  => '/seika-app/public/orders/dashboard_orders.php',
  'back_label' => '← 注文ランチャーへ',
  'right_html' => $right_html,
  'show_store' => true,
  'show_user'  => true,
]);
?>
<style>
  .page{ max-width:1100px; margin:0 auto; padding:14px; display:grid; gap:12px; }
  .filters{ display:flex; gap:10px; align-items:end; flex-wrap:wrap; }
  .field{ display:grid; gap:6px; }
  .label{ color:var(--muted); font-size:12px; font-weight:900; }
  .input{
    width:100%; min-height: var(--tap);
    padding:12px 14px;
    border-radius:14px;
    border:1px solid var(--line);
    background: color-mix(in srgb, var(--cardA) 70%, transparent);
    color: var(--txt);
    outline:none;
  }
  .kpi{ display:flex; gap:10px; flex-wrap:wrap; margin-top:10px; }
  .pill{
      display:inline-flex;
      align-items:center;
      gap:6px;
      border:1px solid var(--line);
      padding:6px 10px;
      border-radius:999px;
      color:var(--muted);
      font-size:12px;
    }
  .pill.open{   background: rgba(255, 209, 102, .95); color:#1a1203; }
  .pill.paid{   background: rgba( 57, 217, 138, .95); color:#04130c; }
  .pill.locked{ background: rgba(140, 180, 255, .95); color:#061125; }
  .pill.void{   background: rgba(255,  92,  92, .95); color:#1a0505; }
  .list{ display:grid; gap:10px; margin-top:12px; }
  .row{
    padding:12px;
    border:1px solid var(--line);
    border-radius:14px;
    background: color-mix(in srgb, var(--cardA) 70%, transparent);
    display:grid;
    gap:8px;
  }
  .title{ font-weight:1000; }
  .muted2{ color:var(--muted); font-size:12px; line-height:1.35; }
  .two{ display:grid; grid-template-columns: 1fr auto; gap:10px; align-items:center; }
  .table{ width:100%; border-collapse:separate; border-spacing:0 8px; }
  .table th{ text-align:left; color:var(--muted); font-size:12px; padding:0 10px 6px 10px; }
  .table td{
    padding:10px;
    border:1px solid var(--line);
    border-radius:12px;
    background: color-mix(in srgb, var(--cardA) 70%, transparent);
  }
  .td-right{ text-align:right; white-space:nowrap; }
  .items{ display:grid; gap:6px; margin-top:4px; }
  .item{ display:flex; justify-content:space-between; gap:10px; }
  /* ===== テーブル見た目を「表」に寄せる（カード感を消す） ===== */
.table{
  border-collapse: separate;
  border-spacing: 0;            /* ← 0 8px を潰す */
  background: color-mix(in srgb, var(--cardA) 70%, transparent);
  border: 1px solid var(--line);
  border-radius: 14px;
  overflow: hidden;
}

/* ヘッダ */
.table thead th{
  padding: 12px 14px;
  background: rgba(0,0,0,.02);
  border-bottom: 1px solid var(--line);
}

/* セル */
.table tbody td{
  padding: 12px 14px;
  border: 0;                    /* ← 1セルずつ枠をやめる */
  border-bottom: 1px solid var(--line);
  border-radius: 0;             /* ← 角丸を消す */
  background: transparent;      /* ← カード背景を消す */
}

/* 最終行の下線消す */
.table tbody tr:last-child td{
  border-bottom: 0;
}

/* 行hover（表っぽい強調） */
.table tbody tr:hover td{
  background: rgba(0,0,0,.02);
}

/* リンクをボタンっぽくしない */
.table a{
  color: inherit;
  font-weight: 900;
  text-decoration: underline;
  text-underline-offset: 3px;
}
.table a:hover{
  text-decoration-thickness: 2px;
}
</style>

<main class="page">

  <section class="card">
    <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;">
      <div>
        <div style="font-weight:1000; font-size:18px;">✅ 提供済み（done）レポート</div>
        <div class="muted" style="margin-top:6px;">期間内の完了注文から集計します</div>
      </div>
    </div>

    <form class="filters" method="get" style="margin-top:12px;">
      <div class="field">
        <div class="label">開始日</div>
        <input class="input" type="date" name="from" value="<?= h($from) ?>">
      </div>
      <div class="field">
        <div class="label">終了日</div>
        <input class="input" type="date" name="to" value="<?= h($to) ?>">
      </div>
      <div class="field">
        <div class="label">一覧件数</div>
        <input class="input" type="number" name="limit" value="<?= (int)$limit ?>" min="20" max="300">
      </div>
      <button class="btn primary" type="submit">更新</button>
      <a class="btn" href="/seika-app/public/orders/done_report.php">今日</a>
      <?php
        $show_unpaid = (int)($_GET['unpaid'] ?? 0); // 1=未会計表示
        ?>
        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:10px;">
          <button type="submit"
                  name="unpaid" value="0"
                  style="padding:10px 14px; border-radius:12px; font-weight:900;
                        border:1px solid var(--line); background:var(--card); color:var(--txt);">
            ✅ 提供済み（done）集計
          </button>

          <button type="submit"
                  name="unpaid" value="1"
                  style="padding:10px 14px; border-radius:12px; font-weight:900;
                        border:1px solid rgba(239,68,68,.35);
                        background:rgba(239,68,68,.10); color:#b91c1c;">
            💰 未会計を表示
          </button>
        </div>
    </form>

    <div class="kpi">
      <span class="pill">合計本数：<b style="color:var(--txt);"><?= (int)$totalQty ?></b></span>
      <span class="pill">合計金額（税別）：<b style="color:var(--txt);">¥<?= number_format($totalAmountEx) ?></b></span>
      <div>状態：
        <?php if ($show_unpaid): ?>
          <span class="pill st-open">未会計（open/locked）</span>
        <?php else: ?>
          <span class="pill st-paid">done</span>
        <?php endif; ?>
      </div>
    </div>
  </section>
  <!-- 🧾 伝票ごとの合計（本数・金額） -->
  <section class="card">
    <div class="two">
      <div>
        <div class="title">🧾 伝票ごとの合計（本数・金額）</div>
        <div class="muted2">金額の大きい順（done注文のみ集計）</div>
      </div>
      <div class="pill">件数 <?= count($ticketTotals ?? []) ?></div>
    </div>

    <?php if (empty($ticketTotals)): ?>
      <div class="muted" style="margin-top:10px;">データがありません。</div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>伝票</th>
            <th>卓</th>
            <th>状態</th>
            <th class="td-right">本数</th>
            <th class="td-right">金額（税別）</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ticketTotals as $r): ?>
            <?php
              $tid = (int)($r['ticket_id'] ?? 0);
              $seat = (int)($r['seat_id'] ?? 0);
              $stt = (string)($r['ticket_status'] ?? '');
            ?>
            <tr>
              <td>
                <?php if ($tid > 0): ?>
                  <a
                    href="/seika-app/public/orders/ticket_orders_summary.php?ticket_id=<?= (int)$r['ticket_id'] ?>">
                    伝票 #<?= (int)$r['ticket_id'] ?>
                  </a>
                <?php else: ?>
                  -
                <?php endif; ?>
              </td>
              <td><?= $seat > 0 ? ('卓' . $seat) : '-' ?></td>
              <td>
                <?php if ($stt !== ''): ?>
                  <?php
                    $cls = 'open';
                    $label = '未会計';
                    $icon = '🟡';

                    if ($stt === 'paid') {
                      $cls = 'paid';
                      $label = '会計済';
                      $icon = '✅';
                    } elseif ($stt === 'locked') {
                      $cls = 'locked';
                      $label = '精算待';
                      $icon = '🧾';
                    }
                  ?>
                  <span class="pill <?= h($cls) ?>">
                    <?= $icon ?> <?= h($label) ?>
                  </span>
                <?php else: ?>
                  -
                <?php endif; ?>
                </td>
              <td class="td-right"><b><?= (int)($r['total_qty'] ?? 0) ?></b></td>
              <td class="td-right">¥<?= number_format((int)($r['total_amount_ex'] ?? 0)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="two">
      <div>
        <div class="title">📦 何が何本（メニュー別合計）</div>
        <div class="muted2">本数の多い順。金額は税別（price_ex × qty）</div>
      </div>
      <div class="pill">件数 <?= count($menuTotals) ?></div>
    </div>

    <?php if (!$menuTotals): ?>
      <div class="muted" style="margin-top:10px;">データがありません。</div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>メニュー</th>
            <th class="td-right">単価</th>
            <th class="td-right">本数</th>
            <th class="td-right">金額（税別）</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($menuTotals as $r): ?>
            <tr>
              <td><?= h((string)$r['menu_name']) ?></td>
              <td class="td-right">¥<?= number_format((int)$r['price_ex']) ?></td>
              <td class="td-right"><b><?= (int)$r['total_qty'] ?></b></td>
              <td class="td-right">¥<?= number_format((int)$r['total_amount_ex']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="two">
      <div>
        <div class="title">🪑 卓ごとの合計（本数・金額）</div>
        <div class="muted2">金額の大きい順</div>
      </div>
      <div class="pill">件数 <?= count($tableTotals) ?></div>
    </div>

    <?php if (!$tableTotals): ?>
      <div class="muted" style="margin-top:10px;">データがありません。</div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>卓</th>
            <th class="td-right">本数</th>
            <th class="td-right">金額（税別）</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tableTotals as $r): ?>
            <?php
              $tn = (int)($r['table_no'] ?? 0);
              $label = $tn > 0 ? ('卓' . $tn) : (string)($r['table_name'] ?? ('table#' . (int)$r['table_id']));
            ?>
            <tr>
              <td><?= h($label) ?></td>
              <td class="td-right"><b><?= (int)$r['total_qty'] ?></b></td>
              <td class="td-right">¥<?= number_format((int)$r['total_amount_ex']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="two">
      <div>
        <div class="title">📜 提供済み（done）注文一覧</div>
        <div class="muted2">下の一覧は注文単位。明細付き</div>
      </div>
      <div class="pill">表示 <?= count($orders) ?> 件</div>
    </div>

    <?php if (!$orders): ?>
      <div class="muted" style="margin-top:10px;">この期間の完了注文はありません。</div>
    <?php else: ?>
      <div class="list">
        <?php foreach ($orders as $o): ?>
          <?php
            $oid = (int)$o['id'];
            $tn = (int)($o['table_no'] ?? 0);
            $tableLabel = $tn > 0 ? ('卓' . $tn) : ('table_id:' . (int)$o['table_id']);
            $at = (string)($o['created_at'] ?? '');
            $items = $itemsByOrder[$oid] ?? [];
          ?>
          <div class="row">
            <div class="two">
              <div class="title"><?= h($tableLabel) ?>｜注文 #<?= (int)$oid ?></div>
              <div class="pill">完了 <?= h($at) ?></div>
            </div>

            <?php if ($items): ?>
              <div class="items">
                <?php foreach ($items as $it): ?>
                  <?php
                    $name = (string)($it['menu_name'] ?? ('menu#' . (int)$it['menu_id']));
                    $qty  = (int)($it['qty'] ?? 0);
                  ?>
                  <div class="item">
                    <div><?= h($name) ?></div>
                    <div class="pill">x<?= (int)$qty ?></div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="muted2">明細が見つかりません</div>
            <?php endif; ?>

            <?php if ((string)($o['note'] ?? '') !== ''): ?>
              <div class="muted2">注文メモ：<?= h((string)$o['note']) ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

</main>

<?php render_page_end(); ?>