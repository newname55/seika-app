<?php
declare(strict_types=1);

$APP = realpath(__DIR__ . '/../app') ?: realpath(__DIR__ . '/../../app');
if (!$APP) { http_response_code(500); exit('APP dir not found'); }

require_once $APP . '/auth.php';
require_once $APP . '/db.php';
require_once $APP . '/layout.php';

require_login();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/** CSRF（既存があればそれを使う / 無ければ簡易） */
function csrf_token_local(): string {
  if (function_exists('csrf_token')) return (string)csrf_token();
  if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
  return (string)$_SESSION['csrf_token'];
}

/** store_id を安全に決める */
function resolve_store_id(): int {
  $sid = 0;
  if (function_exists('current_store_id')) {
    try { $sid = (int)current_store_id(); } catch (Throwable $e) {}
  }
  if ($sid <= 0) $sid = (int)($_GET['store_id'] ?? 0);
  if ($sid <= 0) $sid = (int)($_SESSION['store_id'] ?? 0);
  return $sid;
}

$pdo = db();
$storeId = resolve_store_id();
if ($storeId <= 0) {
  header('Location: /seika-app/public/store_select.php?next=' . urlencode('/seika-app/public/cast_punch.php'));
  exit;
}
$_SESSION['store_id'] = $storeId;

$st = $pdo->prepare("SELECT name FROM stores WHERE id=? LIMIT 1");
$st->execute([$storeId]);
$storeName = (string)($st->fetchColumn() ?: ('#'.$storeId));

render_page_start('出勤管理');
render_header('出勤管理', [
  'back_href' => '/seika-app/public/dashboard.php',
  'back_label' => '← ダッシュボード',
]);
?>
<div class="page">
  <div class="card" style="max-width:880px;margin:0 auto;">
    <div style="font-weight:1000;font-size:18px;">📍 出勤 / 退勤</div>
    <div class="muted" style="margin-top:6px;">店舗：<b><?= h($storeName) ?></b></div>

    <div class="muted" style="margin-top:10px; line-height:1.6;">
      ・この画面のボタンを押すと、あなたのLINEに「位置情報を送る」ボタンが届きます。<br>
      ・LINE側で「位置情報を送る」を押して完了です（10分以内）。
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;">
      <button class="btn primary" type="button" onclick="req('in')">✅ 出勤</button>
      <button class="btn" type="button" onclick="req('out')">🟦 退勤</button>
    </div>

    <div id="msg" class="muted" style="margin-top:12px;"></div>

    <input type="hidden" id="csrf" value="<?= h(csrf_token_local()) ?>">
    <input type="hidden" id="store_id" value="<?= (int)$storeId ?>">
  </div>
</div>

<style>
.card{padding:14px;border:1px solid var(--line);border-radius:14px;background:var(--cardA)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;
  padding:12px 16px;border-radius:12px;border:1px solid var(--line);
  background:var(--cardA);color:inherit;cursor:pointer;font-weight:900;
}
.btn.primary{background:rgba(59,130,246,.18);border-color:rgba(59,130,246,.35)}
.muted{opacity:.75;font-size:13px}
</style>

<script>
async function req(action){
  const msg = document.getElementById('msg');
  msg.textContent = 'LINEへ「位置情報を送る」を送信中…';

  const body = new URLSearchParams();
  body.set('csrf_token', document.getElementById('csrf').value);
  body.set('store_id', document.getElementById('store_id').value);
  body.set('action', action);

  const res = await fetch('/seika-app/public/api/attendance_geo_request.php', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: body.toString()
  });

  const text = await res.text();
  if (!res.ok){
    msg.textContent = '失敗: ' + text;
    return;
  }
  msg.textContent = 'OK！あなたのLINEに「位置情報を送る」が届きます。LINEで押して完了してね。';
}
</script>

<?php render_page_end(); ?>