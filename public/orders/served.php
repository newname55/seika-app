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

// フィルタ
$from = (string)($_GET['from'] ?? date('Y-m-d'));
$to   = (string)($_GET['to']   ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');

$toPlus = (new DateTime($to))->modify('+1 day')->format('Y-m-d');
$limit  = max(20, min(300, (int)($_GET['limit'] ?? 100)));

$DONE_STATUS = 'done';

// done 注文一覧（★tickets を join して伝票番号を取る）
$sql = "
SELECT
  o.id, o.store_id, o.table_id, o.ticket_id, o.status, o.note, o.created_at,
  t.table_no, t.name AS table_name,
  tk.ticket_no, tk.paper_ticket_no
FROM order_orders o
LEFT JOIN order_tables t
  ON t.id = o.table_id AND t.store_id = o.store_id
LEFT JOIN tickets tk
  ON tk.id = o.ticket_id AND tk.store_id = o.store_id
WHERE
  o.store_id = ?
  AND o.status = ?
  AND o.created_at >= ?
  AND o.created_at < ?
ORDER BY o.created_at DESC
LIMIT {$limit}
";

$st = $pdo->prepare($sql);
$st->execute([$store_id, $DONE_STATUS, $from . ' 00:00:00', $toPlus . ' 00:00:00']);
$orders = $st->fetchAll(PDO::FETCH_ASSOC);

// 注文ごとの明細をまとめて取る（1回で）
$orderIds = [];
foreach ($orders as $o) $orderIds[] = (int)$o['id'];

$itemsByOrder = [];
if ($orderIds) {
  $in = implode(',', array_fill(0, count($orderIds), '?'));
  $params = array_merge([$store_id], $orderIds);

  $sql2 = "
  SELECT
    oi.order_id, oi.menu_id, oi.qty, oi.note AS item_note,
    m.name AS menu_name
  FROM order_order_items oi
  LEFT JOIN order_menus m
    ON m.id = oi.menu_id AND m.store_id = oi.store_id
  WHERE
    oi.store_id = ?
    AND oi.order_id IN ($in)
  ORDER BY oi.order_id DESC, oi.id ASC
  ";
  $st2 = $pdo->prepare($sql2);
  $st2->execute($params);
  foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $oid = (int)$r['order_id'];
    if (!isset($itemsByOrder[$oid])) $itemsByOrder[$oid] = [];
    $itemsByOrder[$oid][] = $r;
  }
}

$right_html = '
  <a class="btn" href="/seika-app/public/orders/dashboard_orders.php">🍳 注文ランチャへ</a>
  <a class="btn" href="">🛎️ 注文</a>
';

render_page_start('提供済み（完了）');
render_header('提供済み（完了）', [
  'back_href'  => '/seika-app/public/orders/kitchen.php',
  'back_label' => '← キッチン',
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
  .list{ display:grid; gap:10px; }
  .row{
    padding:12px;
    border:1px solid var(--line);
    border-radius:14px;
    background: color-mix(in srgb, var(--cardA) 70%, transparent);
    display:grid;
    gap:8px;
  }
  .top{ display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; }
  .title{ font-weight:1000; }
  .muted2{ color:var(--muted); font-size:12px; line-height:1.35; }
  .items{ display:grid; gap:6px; margin-top:4px; }
  .item{ display:flex; justify-content:space-between; gap:10px; }
  .pill{ display:inline-flex; align-items:center; gap:6px; border:1px solid var(--line); padding:4px 10px; border-radius:999px; color:var(--muted); font-size:12px; }

  .ticket-pill{
    display:inline-flex; align-items:center; gap:6px;
    border:1px solid var(--line);
    padding:6px 10px;
    border-radius:999px;
    width:fit-content;
    font-size:12px;
    color:var(--muted);
  }
  .ticket-pill a{ color:var(--txt); font-weight:1000; text-decoration:underline; }
  .ticket-pill a:hover{ opacity:.85; }
</style>

<main class="page">
  <section class="card">
    <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;">
      <div>
        <div style="font-weight:1000; font-size:18px;">✅ 提供済み（完了）注文</div>
        <div class="muted" style="margin-top:6px;">
          状態：<b><?= h($DONE_STATUS) ?></b> ／ 件数：<b><?= count($orders) ?></b>
        </div>
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
        <div class="label">表示件数</div>
        <input class="input" type="number" name="limit" value="<?= (int)$limit ?>" min="20" max="300">
      </div>
      <button class="btn primary" type="submit">絞り込み</button>
      <a class="btn" href="/seika-app/public/orders/served.php">今日</a>
    </form>
  </section>

  <section class="card">
    <?php if (!$orders): ?>
      <div class="muted">この期間の提供済み（完了）注文はありません。</div>
    <?php else: ?>
      <div class="list">
        <?php foreach ($orders as $o): ?>
          <?php
            $oid = (int)($o['id'] ?? 0);

            $tableNo = (int)($o['table_no'] ?? 0);
            $tableLabel = $tableNo > 0 ? ('卓' . $tableNo) : ('table_id:' . (int)($o['table_id'] ?? 0));

            $at = (string)($o['created_at'] ?? '');
            $items = $itemsByOrder[$oid] ?? [];

            // 伝票（クリックで会計へ）
            $tid = (int)($o['ticket_id'] ?? 0);
            $tno = (int)($o['ticket_no'] ?? 0);
            $ptn = (string)($o['paper_ticket_no'] ?? '');

            $ticketText =
              $tno > 0 ? ('伝票 ' . $tno) :
              ($ptn !== '' ? ('伝票 ' . $ptn) :
              ($tid > 0 ? ('伝票 #' . $tid) : '伝票 -'));
          ?>

          <div class="row">
            <div class="top">
              <div class="title"><?= h($tableLabel) ?>｜注文 #<?= (int)$oid ?></div>
              <div class="pill">完了 <?= h($at) ?></div>
            </div>

            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">

              <!-- 今までの会計リンク（そのまま残す） -->
              <a class="pill"
                href="/seika-app/public/cashier/cashier.php?ticket_id=<?= $tid ?>">
                伝票 #<?= $tid ?>
              </a>

              <!-- 追加：注文集計ページ -->
              <a class="pill"
                style="border-color: rgba(120,200,255,.4);"
                href="/seika-app/public/orders/ticket_orders_summary.php?ticket_id=<?= $tid ?>">
                オーダー明細
              </a>

            </div>

            <?php if ($items): ?>
              <div class="items">
                <?php foreach ($items as $it): ?>
                  <?php
                    $name = (string)($it['menu_name'] ?? ('menu#' . (int)($it['menu_id'] ?? 0)));
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