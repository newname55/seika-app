<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/layout.php';

// attendance流儀のstore選択を使えるなら使う
$attLib = __DIR__ . '/../../app/attendance.php';
if (is_file($attLib)) require_once $attLib;

// store.php があれば読み込む（関数差分の保険）
$storeLib = __DIR__ . '/../../app/store.php';
if (is_file($storeLib)) require_once $storeLib;

require_login();
require_role(['admin','manager','super_user']); // 端末で staff/cast も使うなら追加

$pdo = db();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// layout.php のヘッダが店舗を表示するための補助
if (!function_exists('current_store_id')) {
  function current_store_id(): ?int {
    return isset($_SESSION['store_id']) ? (int)$_SESSION['store_id'] : null;
  }
}

// ------------------------------
// 1) store_id 確定（attendance流儀）
// ------------------------------
$store_id = 0;
if (function_exists('att_safe_store_id')) $store_id = (int)att_safe_store_id();
if ($store_id <= 0) $store_id = (int)($_SESSION['store_id'] ?? 0);

if ($store_id <= 0) {
  header('Location: /seika-app/public/store_select.php?next=' . urlencode($_SERVER['REQUEST_URI']));
  exit;
}
$_SESSION['store_id'] = $store_id;

// 店舗名（あれば表示）
$storeName = '#' . $store_id;
if (function_exists('att_fetch_store')) {
  $s = att_fetch_store($pdo, $store_id);
  if (is_array($s) && !empty($s['name'])) $storeName = (string)$s['name'];
} elseif (function_exists('fetch_store')) {
  $s = fetch_store($pdo, $store_id);
  if (is_array($s) && !empty($s['name'])) $storeName = (string)$s['name'];
}

// ------------------------------
// 2) GET: ticket_id / seat_id を受け取る
//    - ticket_id があれば tickets から seat_id を補完
// ------------------------------
$ticketId = (int)($_GET['ticket_id'] ?? 0);
$seatId   = (int)($_GET['seat_id'] ?? 0);

// ticket_id が来ていれば ticketチェック + seat補完
$ticketStatus = '';
if ($ticketId > 0) {
  $st = $pdo->prepare("SELECT id, store_id, seat_id, status FROM tickets WHERE id=? LIMIT 1");
  $st->execute([$ticketId]);
  $t = $st->fetch(PDO::FETCH_ASSOC);

  if (!$t) {
    // ticketが無いなら無効
    $ticketId = 0;
  } else {
    if ((int)$t['store_id'] !== $store_id) {
      // 店舗違いは無効
      $ticketId = 0;
    } else {
      $ticketStatus = (string)($t['status'] ?? '');
      if ($seatId <= 0) $seatId = (int)($t['seat_id'] ?? 0);
    }
  }
}

// ------------------------------
// 3) seat_id → order_tables.id（tableId）を解決（無ければ作る）
//    ※注文APIが tableId を要求するための互換
// ------------------------------
$tableId = 0;
if ($seatId > 0) {
  // seat_id列が無い環境だと落ちるので try で保険
  try {
    $st = $pdo->prepare("SELECT id FROM order_tables WHERE store_id=? AND seat_id=? AND is_active=1 LIMIT 1");
    $st->execute([$store_id, $seatId]);
    $tableId = (int)($st->fetchColumn() ?: 0);

    if ($tableId <= 0) {
      // 自動作成（table_no は席番号を入れておくと現場で分かりやすい）
      $ins = $pdo->prepare("
        INSERT INTO order_tables (store_id, seat_id, table_no, name, is_active)
        VALUES (?, ?, ?, ?, 1)
      ");
      $ins->execute([$store_id, $seatId, $seatId, '卓 ' . $seatId]);
      $tableId = (int)$pdo->lastInsertId();
    }
  } catch (Throwable $e) {
    // order_tablesに seat_id がまだ無い等：ここでは tableId=0 のまま
    $tableId = 0;
  }
} else {
  // 旧互換：?table=xx が来た場合
  $tableId = (int)($_GET['table'] ?? 0);
}

// ------------------------------
// 4) 注文可能判定（安全運用）
//    - tableId が解決できたら最低限OK
//    - さらに安全にするなら paid/void は停止
// ------------------------------
$canOrder = ($tableId > 0);

// ここをONにすると「会計済み伝票」から注文できなくなる（安全）
// if ($ticketId > 0 && !in_array($ticketStatus, ['open','locked'], true)) $canOrder = false;

// ------------------------------
// 5) ヘッダ右側（カートボタン）
// ------------------------------
$right_html = '
  <button id="cartBtn" class="btn primary">🛒 カート (<span id="cartCount">0</span>)</button>
';

// 戻り先
$back = '/seika-app/public/orders/dashboard_orders.php';
$back_label = '← 注文ランチャ';

// ------------------------------
// 6) 描画
// ------------------------------
render_page_start('注文');
render_header('注文', [
  'back_href'  => $back,
  'back_label' => $back_label,
  'right_html' => $right_html,
  'show_store' => true,
  'show_user'  => true,
]);
?>
<style>
  .page{ max-width:1100px; margin:0 auto; padding:14px; }
  .grid{ display:grid; gap:12px; }

  /* 上部の情報カード */
  .info{
    display:flex; gap:12px; align-items:flex-start; justify-content:space-between;
    flex-wrap:wrap;
  }
  .info .title{ font-weight:1000; font-size:18px; }
  .muted{ color:var(--muted); }
  code{ padding:2px 6px; border:1px solid var(--line); border-radius:10px; background: color-mix(in srgb, var(--cardA) 70%, transparent); }

  /* menu grid（orders.js 生成DOMを強めに上書き） */
  #categories .cat{ margin-top:12px; }
  #categories .cat-head{ display:flex; align-items:end; justify-content:space-between; gap:10px; margin:10px 0; }
  #categories .cat-title{ font-weight:1000; font-size:18px; }

  /* ✅ 3列固定（PC） */
  #categories .menu-grid{
    display:grid !important;
    grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
    gap:10px !important;
  }
  @media (max-width: 900px){
    #categories .menu-grid{ grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
  }
  @media (max-width: 520px){
    #categories .menu-grid{ grid-template-columns: 1fr !important; }
  }

  /* ✅ カードを小さめに */
  #categories .menu-item{ overflow:hidden; padding:0 !important; border-radius:14px; }
  #categories .menu-item img{
    width:100% !important;
    height:86px !important;
    object-fit:cover;
    display:block;
    background:#111;
    border-bottom:1px solid var(--line);
  }
  #categories .menu-pad{ padding:10px !important; display:flex; flex-direction:column; gap:6px !important; }
  #categories .menu-name{ font-weight:1000; font-size:14px !important; line-height:1.2; }
  #categories .menu-desc{ color:var(--muted); font-size:12px !important; line-height:1.25; min-height:0 !important; }
  #categories .row{ display:flex; align-items:center; justify-content:space-between; gap:8px; }
  #categories .badge{ font-size:11px !important; padding:3px 8px !important; border-radius:999px; border:1px solid var(--line); color:var(--muted); }
  #categories .badge.soldout{ border-color: rgba(255,92,92,.45); color:#ff8a8a; }

  /* modal */
  .modal.hidden{ display:none; }
  .modal{ position:fixed; inset:0; z-index:80; }
  .modal-bg{ position:absolute; inset:0; background:rgba(0,0,0,.55); }
  .modal-panel{
    position:absolute; right:0; top:0; height:100%;
    width:min(520px, 94vw);
    background: linear-gradient(180deg, var(--cardA), var(--cardB));
    border-left:1px solid var(--line);
    display:flex; flex-direction:column;
  }
  .modal-head{ display:flex; align-items:center; justify-content:space-between; gap:10px; padding:14px; border-bottom:1px solid var(--line); }
  .modal-title{ font-weight:1000; }
  .cart-list{ padding:14px; overflow:auto; flex:1; }
  .cart-row{
    display:grid; grid-template-columns: 1fr auto; gap:10px;
    padding:12px; border:1px solid var(--line); border-radius:14px;
    background: color-mix(in srgb, var(--cardA) 70%, transparent);
    margin-bottom:10px;
  }
  .sub{ color:var(--muted); font-size:12px; margin-top:6px; }
  .qty{ display:flex; gap:8px; align-items:center; justify-content:flex-end; flex-wrap:wrap; }
  .qty .n{ min-width:2ch; text-align:center; font-weight:900; }
  .cart-foot{ padding:14px; border-top:1px solid var(--line); display:grid; gap:10px; }
  .input{
    width:100%; min-height: var(--tap);
    padding:12px 14px;
    border-radius:14px;
    border:1px solid var(--line);
    background: color-mix(in srgb, var(--cardA) 70%, transparent);
    color: var(--txt);
    outline:none;
  }
  .msg{ color:var(--muted); font-size:13px; }
  /* ===============================
   メニュー 3×3 固定 & 画像サイズ統一
   =============================== */

/* グリッドを強制3列 */
#categories .menu-grid {
  display: grid !important;
  grid-template-columns: repeat(3, 1fr) !important;
  gap: 14px !important;
}

/* レスポンシブ */
@media (max-width: 900px) {
  #categories .menu-grid {
    grid-template-columns: repeat(2, 1fr) !important;
  }
}
@media (max-width: 520px) {
  #categories .menu-grid {
    grid-template-columns: 1fr !important;
  }
}

/* カード全体を揃える */
#categories .menu-item {
  display: flex !important;
  flex-direction: column !important;
  border-radius: 16px !important;
  overflow: hidden !important;
}

/* ✅ 画像サイズ固定（ここが重要） */
#categories .menu-item img {
  width: 100% !important;
  height: 140px !important;      /* ← ここで高さ統一 */
  object-fit: cover !important;  /* 切り抜きで揃える */
  background: #111 !important;
  display: block !important;
}

/* テキスト部分 */
#categories .menu-pad {
  padding: 12px !important;
}

#categories .menu-name {
  font-size: 15px !important;
  font-weight: 800 !important;
}

#categories .menu-desc {
  font-size: 12px !important;
  min-height: 0 !important;
}
#categories img {
  max-height: 160px !important;
  object-fit: cover !important;
}
/* =====================================================
   最終上書き：#categories 配下を強制 3列カード化
   （orders.js のクラス名が違っても効く）
   ===================================================== */

/* 1) まず「カテゴリ内の一覧」を探してグリッド化
   - .menu-grid が無い場合でも、よくある class をまとめて対象にする */
#categories .menu-grid,
#categories .menus,
#categories .items,
#categories .list,
#categories .grid {
  display: grid !important;
  grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
  gap: 14px !important;
}

/* もし上が存在しない場合に備えて
   #categories 直下の「カテゴリブロック」を壊さないようにしつつ
   その中の「直下の要素群」をグリッドにする */
#categories .cat > div:last-child {
  display: grid !important;
  grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
  gap: 14px !important;
}

/* レスポンシブ */
@media (max-width: 900px){
  #categories .menu-grid,
  #categories .menus,
  #categories .items,
  #categories .list,
  #categories .grid,
  #categories .cat > div:last-child {
    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
  }
}
@media (max-width: 520px){
  #categories .menu-grid,
  #categories .menus,
  #categories .items,
  #categories .list,
  #categories .grid,
  #categories .cat > div:last-child {
    grid-template-columns: 1fr !important;
  }
}

/* 2) 「商品カード」をカード化（よくある候補をまとめて対象） */
#categories .menu-item,
#categories .item,
#categories .card,
#categories .menu {
  border-radius: 16px !important;
  overflow: hidden !important;
  border: 1px solid var(--line) !important;
  background: linear-gradient(180deg, var(--cardA), var(--cardB)) !important;
}

/* 3) 商品カードが「横並び(row)」になってても強制で縦にする */
#categories .menu-item,
#categories .item,
#categories .menu {
  display: flex !important;
  flex-direction: column !important;
}

#categories .menu-item img,
#categories img {
  width: 100% !important;
  height: 170px !important;
  object-fit: contain !important;
  background: #fff !important;
  padding: 12px !important;
  border-bottom: 1px solid var(--line);
}

/* 5) 追加ボタンが右端に出てるタイプのレイアウトをカード内に収める */
#categories .row,
#categories .menu-row,
#categories .line {
  display: flex !important;
  justify-content: space-between !important;
  align-items: center !important;
  gap: 10px !important;
}

/* 6) 文字周りをコンパクトに */
#categories .menu-pad,
#categories .pad,
#categories .body {
  padding: 10px !important;
}
#categories .menu-name,
#categories .name {
  font-size: 14px !important;
  font-weight: 900 !important;
  line-height: 1.2 !important;
}
#categories .badge,
#categories .price {
  font-size: 11px !important;
  padding: 3px 8px !important;
  border-radius: 999px !important;
}
/* ===============================
   価格を目立たせる
   =============================== */

/* 価格テキスト（候補全部まとめて） */
#categories .badge,
#categories .price,
#categories .menu-price {
  font-size: 18px !important;
  font-weight: 900 !important;
  color: #111 !important;
  background: #ffd54f !important;   /* 少しゴールド寄り */
  padding: 6px 12px !important;
  border-radius: 12px !important;
  border: none !important;
  box-shadow: 0 2px 6px rgba(0,0,0,.15);
}

/* 円マークを強調 */
#categories .badge::after,
#categories .price::after {
  font-size: 14px;
}
/* ゆっくり脈打つ（売れるUI） */
@keyframes priceGlow {
  0%   { box-shadow: 0 0 4px rgba(255,215,0,.4); }
  50%  { box-shadow: 0 0 14px rgba(255,215,0,.9); }
  100% { box-shadow: 0 0 4px rgba(255,215,0,.4); }
}

#categories .badge,
#categories .price {
  font-size: 20px !important;
  font-weight: 900 !important;
  background: linear-gradient(135deg, #ffe082, #ffca28);
  color: #000 !important;
  animation: priceGlow 2.5s infinite ease-in-out;
}
</style>

<main class="page grid">
  <section class="card">
    <div class="info">
      <div>
        <div class="title">🛎️ 注文</div>
        <div class="muted" style="margin-top:6px; display:grid; gap:4px;">
          <div>① メニューをタップして追加</div>
          <div>② 右上の <b>カート</b> を開く</div>
          <div>③ <b>注文する</b> を押す</div>
        </div>
      </div>

      <div class="muted">
        店舗：<b><?= h($storeName) ?></b><br>
        席：<b><?= $seatId > 0 ? (int)$seatId : '未指定' ?></b><br>
        tableId：<b><?= $tableId > 0 ? (int)$tableId : '未解決' ?></b><br>
        伝票：<b><?= $ticketId > 0 ? (int)$ticketId : '未指定' ?></b><br>
        <?php if ($ticketStatus !== ''): ?>
          状態：<span class="pill"><?= h($ticketStatus) ?></span><br>
        <?php endif; ?>
        <?php if (!$canOrder): ?>
          <div style="margin-top:8px;" class="pill">注文不可（卓が未解決）</div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <?php if ($seatId <= 0 || $ticketId <= 0): ?>
    <section class="card" style="border-color: rgba(255,204,102,.35);">
      <b>伝票/席の指定が不足しています</b>
      <div class="muted" style="margin-top:6px;">
        通常は <code>?ticket_id=..&seat_id=..</code> 付きで開きます。
      </div>
    </section>
  <?php endif; ?>

  <section id="categories"></section>
</main>

<!-- Cart Modal -->
<div id="modal" class="modal hidden">
  <div class="modal-bg" id="modalBg"></div>
  <div class="modal-panel">
    <div class="modal-head">
      <div class="modal-title">カート</div>
      <button id="closeModal" class="btn">閉じる</button>
    </div>
    <div id="cartList" class="cart-list"></div>
    <div class="cart-foot">
      <input id="orderNote" class="input" placeholder="注文メモ（任意） 例: 氷少なめ" />
      <button id="submitOrder" class="btn primary" <?= $canOrder ? '' : 'disabled' ?>>注文する</button>
      <div id="msg" class="msg"></div>
    </div>
  </div>
</div>

<script>
  window.ORDERS = {
    tableId: <?= (int)$tableId ?>,
    ticketId: <?= (int)$ticketId ?>,
    seatId: <?= (int)$seatId ?>,
    ticketStatus: <?= json_encode((string)$ticketStatus, JSON_UNESCAPED_UNICODE) ?>,
    apiBase: "/seika-app/public/api/orders.php"
  };
</script>

<!-- ✅ ここが重要：毎回URLが変わるのでキャッシュされない -->
<script src="/seika-app/public/orders/assets/orders.js?v=<?= time() ?>"></script>

<?php render_page_end(); ?>