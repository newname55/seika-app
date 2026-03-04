<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/layout.php';

require_login();
require_role(['admin','manager','super_user','staff']);

$pdo = db();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();


if (!function_exists('current_store_id')) {
  function current_store_id(): ?int {
    return isset($_SESSION['store_id']) ? (int)$_SESSION['store_id'] : null;
  }
}

$right_html = '
  <span id="statusText" class="pill">更新: -</span>
  <button id="reloadBtn" class="btn primary">更新</button>
';

render_page_start('キッチン');
render_header('キッチン', [
  'back_href'  => '/seika-app/public/orders/dashboard_orders.php',
  'back_label' => '← 注文ランチャへ',
  'right_html' => $right_html,
]);
?>
<style>
  .page{ max-width:1100px; margin:0 auto; padding:14px; }
  .stack{ display:grid; gap:12px; }
  .muted2{ color:var(--muted); font-size:13px; }

  .item-row{
    display:grid; grid-template-columns: 1fr auto; gap:10px;
    padding:12px; border:1px solid var(--line); border-radius:14px;
    background: color-mix(in srgb, var(--cardA) 70%, transparent);
  }
  .item-title{ font-weight:1000; }
  .sub{ color:var(--muted); font-size:12px; margin-top:6px; }
  .actions{ display:flex; gap:8px; align-items:center; justify-content:flex-end; flex-wrap:wrap; }
</style>

<main class="page">
  <div id="kitchen" class="stack"></div>
</main>

<script>
  window.KITCHEN = { apiBase: "/seika-app/public/api/orders.php" };
</script>
<script src="/seika-app/public/orders/assets/kitchen.js"></script>
<?php render_page_end(); ?>