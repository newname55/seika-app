<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/attendance.php';

// store.php があれば読み込む（関数シグネチャ違いでも attendance.php 側で吸収）
$storeLib = __DIR__ . '/../../app/store.php';
if (is_file($storeLib)) require_once $storeLib;

require_login();
// ※注文端末を staff でも使うなら staff を追加してOK
// require_role(['admin','manager','super_user','staff']);
require_role(['admin','manager','super_user']);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function has_role(string $role): bool {
  return isset($_SESSION['roles']) && in_array($role, $_SESSION['roles'], true);
}

$pdo = db();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// 店舗選択（attendance流儀）
$store_id = att_safe_store_id();
if ($store_id <= 0) {
  header('Location: /seika-app/public/store_select.php?next=' . urlencode($_SERVER['REQUEST_URI']));
  exit;
}

$store = att_fetch_store($pdo, $store_id);
$storeName = $store['name'] ?? ('#' . $store_id);

$storeId = (int)$store_id;
$tableId = (int)($_GET['table'] ?? 0);

// --- ランチャーリンク（存在するものだけ表示） ---
function link_item(string $title, string $desc, string $url, string $fileAbs, array $roles = []): ?array {
  if (!is_file($fileAbs)) return null;
  return [
    'title' => $title,
    'desc'  => $desc,
    'url'   => $url,
    'roles' => $roles,
  ];
}
$items = [];

// 注文（このページ）
$items[] = [
  'title' => '🛎️ 注文する（この画面）',
  'desc'  => 'メニュー画像をタップ → カート → 注文確定',
  'url'   => '/seika-app/public/orders/index.php?table=1',
  'roles' => [],
];

// キッチン
if ($x = link_item(
  '🍳 キッチン（調理/提供）',
  '新規注文を見て「調理中」「提供済」を押す',
  '/seika-app/public/orders/kitchen.php',
  __DIR__ . '/kitchen.php',
  ['admin','manager','super_user','staff']
)) $items[] = $x;

// メニュー管理
if ($x = link_item(
  '🖼️ メニュー管理（追加/編集）',
  'カテゴリ/メニュー/価格/画像/売切を管理',
  '/seika-app/public/orders/admin_menus.php',
  __DIR__ . '/admin_menus.php',
  ['admin','manager','super_user']
)) $items[] = $x;

// 店舗選択
if ($x = link_item(
  '🏬 店舗を切り替える',
  '違う店舗のメニューや注文に切り替える',
  '/seika-app/public/store_select.php?next=' . urlencode($_SERVER['REQUEST_URI']),
  __DIR__ . '/../store_select.php',
  ['admin','manager','super_user']
)) $items[] = $x;

// ダッシュボード
if ($x = link_item(
  '📊 ダッシュボード',
  '全体の入口（他の機能へ移動）',
  '/seika-app/public/dashboard.php',
  __DIR__ . '/../dashboard.php',
  ['admin','manager','super_user']
)) $items[] = $x;

// 出勤（存在すれば）
if ($x = link_item(
  '🕒 出勤（出退勤）',
  '出勤/退勤の記録を見る・入力する',
  '/seika-app/public/attendance/index.php',
  __DIR__ . '/../attendance/index.php',
  ['admin','manager','super_user','staff']
)) $items[] = $x;

// 在庫（存在すれば）
if ($x = link_item(
  '📦 在庫（商品一覧）',
  '在庫の商品マスタ/在庫管理へ',
  '/seika-app/public/stock/list.php',
  __DIR__ . '/../stock/list.php',
  ['admin','manager','super_user','staff']
)) $items[] = $x;

// イベント一覧（存在すれば / それっぽい候補を2つチェック）
$eventCandidates = [
  ['🗓️ イベント（一覧）', 'イベントの一覧を見る', '/seika-app/public/events/list.php', __DIR__ . '/../events/list.php'],
  ['🗓️ イベント（カレンダー）', 'イベントのカレンダーを見る', '/seika-app/public/calendar.php', __DIR__ . '/../calendar.php'],
];
foreach ($eventCandidates as [$t,$d,$u,$f]) {
  if ($x = link_item($t, $d, $u, $f, ['admin','manager','super_user','staff'])) { $items[] = $x; break; }
}

?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>注文</title>
  <link rel="stylesheet" href="/seika-app/public/orders/assets/orders.css">
  <style>
    .launcher-wrap{max-width:1100px;margin:0 auto;padding:1rem}
    .steps{display:grid;gap:.45rem;color:var(--muted);margin:.6rem 0 0}
    .launcher-grid{
      display:grid;
      grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
      gap:.75rem;
      margin-top:.75rem;
    }
    .launch-card{
      background:var(--card2);
      border:1px solid var(--line);
      border-radius:14px;
      padding:.85rem;
      display:flex;
      flex-direction:column;
      gap:.55rem;
      min-height:140px;
    }
    .launch-title{font-weight:900;font-size:1.05rem}
    .launch-desc{color:var(--muted);font-size:.92rem;line-height:1.35}
    .launch-foot{margin-top:auto;display:flex;gap:.5rem;align-items:center;justify-content:space-between}
    .role-badge{font-size:.75rem;padding:.12rem .45rem;border-radius:999px;border:1px solid var(--line);color:var(--muted)}
    .btnlink{display:inline-block;text-decoration:none}
    .hint{margin-top:.65rem;color:var(--muted);font-size:.9rem}
  </style>
</head>
<body>
<header class="topbar">
  <div class="title">注文</div>
  <div class="meta">
    <span><?= h($storeName) ?></span>
    <span>店舗ID: <?= (int)$storeId ?></span>
    <span>卓: <b id="tableLabel"><?= $tableId > 0 ? (int)$tableId : '未指定' ?></b></span>
  </div>
  <button id="cartBtn" class="btn primary">カート (<span id="cartCount">0</span>)</button>
</header>

<!-- ✅ ランチャー（中学生でもわかる版） -->
<section class="launcher-wrap">
  <div class="card">
    <div style="display:flex;gap:.75rem;align-items:flex-start;justify-content:space-between;flex-wrap:wrap">
      <div>
        <div style="font-weight:900;font-size:1.15rem">🧭 まずはここから（ランチャー）</div>
        <div class="steps">
          <div>① <b>メニューを作る</b> → 「メニュー管理」</div>
          <div>② <b>卓番号を決める</b> → URLに <code>?table=1</code> を付ける</div>
          <div>③ <b>注文を入れる</b> → 画像タップ → カート → 注文</div>
          <div>④ <b>キッチンで対応</b> → 「調理中」「提供済」</div>
        </div>
      </div>
      <div class="hint">
        困ったら：<code>卓が未指定</code> のときは<br>
        <b>URL末尾に</b> <code>?table=1</code> を付けてください
      </div>
    </div>

    <div class="launcher-grid">
      <?php foreach ($items as $it): ?>
        <?php
          $roles = $it['roles'] ?? [];
          $roleText = '';
          if (is_array($roles) && count($roles) > 0) {
            $roleText = '権限: ' . implode(',', $roles);
          } else {
            $roleText = 'だれでもOK';
          }
        ?>
        <div class="launch-card">
          <div class="launch-title"><?= h((string)$it['title']) ?></div>
          <div class="launch-desc"><?= h((string)$it['desc']) ?></div>
          <div class="launch-foot">
            <span class="role-badge"><?= h($roleText) ?></span>
            <a class="btn btnlink primary" href="<?= h((string)$it['url']) ?>">開く</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<main class="container">
  <?php if ($tableId <= 0): ?>
    <div class="card warn">
      <b>卓番号が未指定です</b>
      <div class="muted">URLに <code>?table=1</code> のように指定してください。</div>
    </div>
  <?php endif; ?>

  <div id="categories"></div>
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
      <button id="submitOrder" class="btn primary" <?= $tableId > 0 ? '' : 'disabled' ?>>注文する</button>
      <div id="msg" class="msg"></div>
    </div>
  </div>
</div>

<script>
  window.ORDERS = {
    tableId: <?= (int)$tableId ?>,
    apiBase: "/seika-app/public/api/orders.php",
  };
</script>
<script src="/seika-app/public/orders/assets/orders.js"></script>
</body>
</html>