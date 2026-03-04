<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/layout.php';

$attLib = __DIR__ . '/../../app/attendance.php';
if (is_file($attLib)) require_once $attLib;

$storeLib = __DIR__ . '/../../app/store.php';
if (is_file($storeLib)) require_once $storeLib;

require_login();
require_role(['admin','manager','super_user','staff']); // 現場端末で staff も見るなら入れる

$pdo = db();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function yen(int $n): string { return number_format($n); }

// layout.php が店舗を表示するための補助
if (!function_exists('current_store_id')) {
  function current_store_id(): ?int {
    return isset($_SESSION['store_id']) ? (int)$_SESSION['store_id'] : null;
  }
}

// ------------------------------
// store_id 確定
// ------------------------------
$store_id = 0;
if (function_exists('att_safe_store_id')) $store_id = (int)att_safe_store_id();
if ($store_id <= 0) $store_id = (int)($_SESSION['store_id'] ?? 0);

if ($store_id <= 0) {
  header('Location: /seika-app/public/store_select.php?next=' . urlencode($_SERVER['REQUEST_URI']));
  exit;
}
$_SESSION['store_id'] = $store_id;

// 店舗名
$storeName = '#' . $store_id;
if (function_exists('att_fetch_store')) {
  $s = att_fetch_store($pdo, $store_id);
  if (is_array($s) && !empty($s['name'])) $storeName = (string)$s['name'];
} elseif (function_exists('fetch_store')) {
  $s = fetch_store($pdo, $store_id);
  if (is_array($s) && !empty($s['name'])) $storeName = (string)$s['name'];
}

// ------------------------------
// ticket_id 必須
// ------------------------------
$ticket_id = (int)($_GET['ticket_id'] ?? 0);
if ($ticket_id <= 0) {
  http_response_code(400);
  render_page_start('注文集計（伝票）');
  render_header('注文集計（伝票）', [
    'back_href'  => '/seika-app/public/orders/dashboard_orders.php',
    'back_label' => '← 注文ランチャ',
    'show_store' => true,
    'show_user'  => true,
  ]);
  echo '<main class="page"><section class="card" style="padding:14px;">ticket_id が必要です</section></main>';
  render_page_end();
  exit;
}

// tickets から seat_id など補助情報（無くてもOK）
$seat_id = 0;
$ticket_status = '';
$st = $pdo->prepare("SELECT id, store_id, seat_id, status, updated_at FROM tickets WHERE id=? LIMIT 1");
$st->execute([$ticket_id]);
$t = $st->fetch(PDO::FETCH_ASSOC);
if ($t && (int)$t['store_id'] === $store_id) {
  $seat_id = (int)($t['seat_id'] ?? 0);
  $ticket_status = (string)($t['status'] ?? '');
} else {
  // 店舗違い or ticket無い
  $seat_id = 0;
  $ticket_status = '';
}
// status 表示変換（現場向け）
function ticket_status_label(string $st): string {
  $st = strtolower(trim($st));
  return match ($st) {
    'open'   => '未精算',
    'locked' => '精算待ち',
    'paid'   => '入金完了',
    'void'   => '取消',
    default  => ($st === '' ? '—' : $st),
  };
}
function ticket_status_class(string $st): string {
  $st = strtolower(trim($st));
  return match ($st) {
    'open'   => 'st-open',
    'locked' => 'st-locked',
    'paid'   => 'st-paid',
    'void'   => 'st-void',
    default  => 'st-other',
  };
}

$ticket_status_raw  = (string)($ticket_status ?? '');
$ticket_status_text = ticket_status_label($ticket_status_raw);
$ticket_status_cls  = ticket_status_class($ticket_status_raw);
// ------------------------------
// 1) 伝票に紐づく注文一覧（ヘッダ用）
// ------------------------------
// canceled は除外（必要なら含める運用に変えてOK）
$sqlOrders = "
  SELECT id, table_id, ticket_id, status, note, created_at
  FROM order_orders
  WHERE store_id = ?
    AND ticket_id = ?
    AND status <> 'canceled'
  ORDER BY created_at DESC, id DESC
";
$st = $pdo->prepare($sqlOrders);
$st->execute([$store_id, $ticket_id]);
$orders = $st->fetchAll(PDO::FETCH_ASSOC);

// 注文ID一覧
$orderIds = [];
foreach ($orders as $o) $orderIds[] = (int)$o['id'];

// ------------------------------
// 2) 伝票内 明細（メニュー別集計）
// ------------------------------
$summary = [];
$grandTotal = 0;

if ($orderIds) {
  $in = implode(',', array_fill(0, count($orderIds), '?'));
  $params = array_merge([$store_id], $orderIds);

  // item_status が canceled のものは除外（運用に応じて）
  $sqlSum = "
    SELECT
      oi.menu_id,
      COALESCE(m.name, CONCAT('menu#', oi.menu_id)) AS menu_name,
      COALESCE(m.price_ex, 0) AS price_ex,
      SUM(oi.qty) AS qty_sum,
      SUM(oi.qty * COALESCE(m.price_ex, 0)) AS amount_sum
    FROM order_order_items oi
    LEFT JOIN order_menus m
      ON m.store_id = oi.store_id AND m.id = oi.menu_id
    WHERE oi.store_id = ?
      AND oi.order_id IN ($in)
      AND oi.item_status <> 'canceled'
    GROUP BY oi.menu_id, menu_name, price_ex
    ORDER BY amount_sum DESC, qty_sum DESC, menu_name ASC
  ";
  $st = $pdo->prepare($sqlSum);
  $st->execute($params);
  $summary = $st->fetchAll(PDO::FETCH_ASSOC);

  foreach ($summary as $r) $grandTotal += (int)($r['amount_sum'] ?? 0);
}

// ------------------------------
// 3) 注文履歴（時系列で“何を出したか”確認用）
// ------------------------------
$itemsByOrder = [];
if ($orderIds) {
  $in = implode(',', array_fill(0, count($orderIds), '?'));
  $params = array_merge([$store_id], $orderIds);

  $sqlItems = "
    SELECT
      oi.order_id, oi.menu_id, oi.qty, oi.item_status, oi.note AS item_note,
      COALESCE(m.name, CONCAT('menu#', oi.menu_id)) AS menu_name,
      COALESCE(m.price_ex, 0) AS price_ex
    FROM order_order_items oi
    LEFT JOIN order_menus m
      ON m.store_id = oi.store_id AND m.id = oi.menu_id
    WHERE oi.store_id = ?
      AND oi.order_id IN ($in)
    ORDER BY oi.order_id DESC, oi.id ASC
  ";
  $st = $pdo->prepare($sqlItems);
  $st->execute($params);

  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $oid = (int)$r['order_id'];
    if (!isset($itemsByOrder[$oid])) $itemsByOrder[$oid] = [];
    $itemsByOrder[$oid][] = $r;
  }
}

// ------------------------------
// 描画
// ------------------------------
render_page_start('伝票 注文一覧');
render_header('伝票 注文一覧', [
  'back_href'  => ($seat_id > 0 ? "/seika-app/public/orders/index.php?ticket_id={$ticket_id}&seat_id={$seat_id}" : "/seika-app/public/orders/dashboard_orders.php"),
  'back_label' => '← 戻る',
  'right_html' => '<a class="btn" href="/seika-app/public/orders/kitchen.php">🍳 キッチン</a>',
  'show_store' => true,
  'show_user'  => true,
]);
?>
<style>
  .page{ max-width:1100px; margin:0 auto; padding:14px; display:grid; gap:12px; }
  .row{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; justify-content:space-between; }
  .muted{ color:var(--muted); }
  .pill{ display:inline-flex; align-items:center; gap:6px; border:1px solid var(--line); padding:6px 10px; border-radius:999px; color:var(--muted); font-size:12px; }
  .bigTotal{
    font-weight:1000;
    font-size:22px;
    padding:10px 14px;
    border-radius:16px;
    border:1px solid var(--line);
    background: color-mix(in srgb, var(--cardA) 75%, transparent);
    display:inline-flex;
    align-items:center;
    gap:10px;
  }
  table{ width:100%; border-collapse:collapse; }
  th, td{ padding:10px 8px; border-bottom:1px solid var(--line); text-align:left; }
  th{ color:var(--muted); font-size:12px; font-weight:900; }
  td.num{ text-align:right; font-variant-numeric: tabular-nums; }
  .mini{ font-size:12px; color:var(--muted); }
  .orderCard{
    border:1px solid var(--line);
    border-radius:14px;
    padding:12px;
    background: color-mix(in srgb, var(--cardA) 70%, transparent);
    display:grid;
    gap:8px;
  }
  .orderHead{ display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; }
  .orderTitle{ font-weight:1000; }
  .itemLine{ display:flex; justify-content:space-between; gap:10px; }
  .statusTag{ font-size:11px; padding:4px 10px; border-radius:999px; border:1px solid var(--line); color:var(--muted); }
  .statusTag.new{ border-color: rgba(255,204,102,.4); }
  .statusTag.cooking{ border-color: rgba(120,200,255,.4); }
  .statusTag.served{ border-color: rgba(90,220,150,.4); }
  .statusTag.canceled{ border-color: rgba(255,92,92,.45); color:#ff8a8a; }
  /* 状態pill（現場向け） */
.pill{
  display:inline-flex;
  align-items:center;
  gap:6px;
  padding:4px 10px;
  border-radius:999px;
  font-weight:900;
  font-size:12px;
  border:1px solid var(--line);
  background: color-mix(in srgb, var(--card) 70%, transparent);
}

/* open=未精算 */
.pill.st-open{
  background: rgba(37, 99, 235, .12);
  border-color: rgba(37, 99, 235, .35);
  color: #1d4ed8;
}

/* locked=精算待ち */
.pill.st-locked{
  background: rgba(245, 158, 11, .14);
  border-color: rgba(245, 158, 11, .40);
  color: #92400e;
}

/* paid=入金完了 */
.pill.st-paid{
  background: rgba(16, 185, 129, .14);
  border-color: rgba(16, 185, 129, .35);
  color: #065f46;
}

/* void=取消 */
.pill.st-void{
  background: rgba(239, 68, 68, .12);
  border-color: rgba(239, 68, 68, .35);
  color: #b91c1c;
}

/* その他 */
.pill.st-other{
  opacity:.8;
}
</style>

<main class="page">
  <section class="card" style="padding:14px;">
    <div class="row">
      <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap;">

        <!-- 左側：伝票情報 -->
        <div>
          <div style="font-weight:1000; font-size:18px;">
            🧾 伝票 <?= (int)$ticket_id ?> の注文集計
          </div>

          <div class="muted" style="margin-top:6px; display:grid; gap:4px;">
            <div>店舗：<b><?= h($storeName) ?></b></div>
            <div>席：<b><?= $seat_id > 0 ? (int)$seat_id : '未設定' ?></b></div>

            <?php if ($ticket_status_raw !== ''): ?>
              <div>
                伝票状態：
                <span class="pill <?= h($ticket_status_cls) ?>">
                  <?= h($ticket_status_text) ?>
                </span>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- 右側：会計ボタン（条件付き） -->
        <div style="margin-top:4px;">

          <?php
          // 権限判定（manager以上）
          $isManagerPlus = false;
          if (function_exists('has_role')) {
            $isManagerPlus = has_role('manager') || has_role('admin') || has_role('super_user');
          } else {
            if (session_status() !== PHP_SESSION_ACTIVE) session_start();
            $roles = $_SESSION['roles'] ?? [];
            if (is_string($roles)) $roles = [$roles];
            $isManagerPlus =
              in_array('manager', $roles, true) ||
              in_array('admin', $roles, true) ||
              in_array('super_user', $roles, true);
          }

          $store_id  = (int)($_GET['store_id'] ?? 1);
          $ticket_id = (int)($_GET['ticket_id'] ?? 0);

          if ($isManagerPlus) {

            if ($ticket_status === 'open') {

              $href = "/seika-app/public/cashier/cashier.php?store_id={$store_id}&ticket_id={$ticket_id}";
              ?>

              <a href="<?= h($href) ?>"
                target="_blank"
                rel="noopener"
                style="display:inline-flex;
                        align-items:center;
                        gap:8px;
                        padding:10px 14px;
                        border-radius:10px;
                        background:#2563eb;
                        color:#fff;
                        text-decoration:none;
                        font-weight:900;">
                💰 会計へ（別タブ）
              </a>

            <?php } elseif ($ticket_status === 'paid') { ?>

              <span style="display:inline-flex;
                          align-items:center;
                          gap:8px;
                          padding:10px 14px;
                          border-radius:10px;
                          background:rgba(16,185,129,.14);
                          border:1px solid rgba(16,185,129,.35);
                          color:#065f46;
                          font-weight:900;">
                ✅ 精算済み
              </span>

            <?php } ?>
          <?php } ?>

        </div>

      </div>
      <div class="bigTotal">合計（注文分） ￥<?= h(yen($grandTotal)) ?></div>
    </div>
    <div class="mini" style="margin-top:10px;">
      ※これは「注文（ドリンク/フード）」の集計です。セット料金・部屋代・サービス料など会計側とはまだ連動しません。
    </div>
  </section>

  <section class="card" style="padding:14px;">
    <div style="font-weight:1000; margin-bottom:8px;">📌 メニュー別 集計</div>
    <?php if (!$summary): ?>
      <div class="muted">この伝票に紐づく注文がまだありません（ticket_id が入っていない可能性も含む）</div>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>メニュー</th>
            <th class="num">単価</th>
            <th class="num">本数</th>
            <th class="num">金額</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($summary as $r): ?>
            <?php
              $name = (string)($r['menu_name'] ?? '');
              $price = (int)($r['price_ex'] ?? 0);
              $qty = (int)($r['qty_sum'] ?? 0);
              $amt = (int)($r['amount_sum'] ?? 0);
            ?>
            <tr>
              <td><?= h($name) ?></td>
              <td class="num">￥<?= h(yen($price)) ?></td>
              <td class="num"><?= (int)$qty ?></td>
              <td class="num"><b>￥<?= h(yen($amt)) ?></b></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div class="row" style="margin-top:12px;">
        <div class="muted">行数：<b><?= count($summary) ?></b></div>
        <div class="bigTotal">合計 ￥<?= h(yen($grandTotal)) ?></div>
      </div>
    <?php endif; ?>
  </section>

  <section class="card" style="padding:14px;">
    <div style="font-weight:1000; margin-bottom:8px;">🕒 注文履歴（時系列）</div>
    <?php if (!$orders): ?>
      <div class="muted">注文履歴がありません</div>
    <?php else: ?>
      <div style="display:grid; gap:10px;">
        <?php foreach ($orders as $o): ?>
          <?php
            $oid = (int)$o['id'];
            $at = (string)($o['created_at'] ?? '');
            $stt = (string)($o['status'] ?? '');
            $items = $itemsByOrder[$oid] ?? [];
          ?>
          <div class="orderCard">
            <div class="orderHead">
              <div class="orderTitle">注文 #<?= $oid ?></div>
              <div class="pill"><?= h($at) ?> / <?= h($stt) ?></div>
            </div>

            <?php if ($items): ?>
              <div style="display:grid; gap:6px;">
                <?php foreach ($items as $it): ?>
                  <?php
                    $name = (string)($it['menu_name'] ?? '');
                    $qty = (int)($it['qty'] ?? 0);
                    $price = (int)($it['price_ex'] ?? 0);
                    $status = (string)($it['item_status'] ?? '');
                    $line = $price * $qty;
                  ?>
                  <div class="itemLine">
                    <div>
                      <?= h($name) ?>
                      <span class="mini">（￥<?= h(yen($price)) ?> × <?= (int)$qty ?>）</span>
                      <?php if ((string)($it['item_note'] ?? '') !== ''): ?>
                        <div class="mini">メモ：<?= h((string)$it['item_note']) ?></div>
                      <?php endif; ?>
                    </div>
                    <div style="display:flex; gap:8px; align-items:center;">
                      <span class="statusTag <?= h($status) ?>"><?= h($status) ?></span>
                      <b>￥<?= h(yen($line)) ?></b>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="muted">明細が見つかりません</div>
            <?php endif; ?>

            <?php if ((string)($o['note'] ?? '') !== ''): ?>
              <div class="mini">注文メモ：<?= h((string)$o['note']) ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</main>

<?php render_page_end(); ?>