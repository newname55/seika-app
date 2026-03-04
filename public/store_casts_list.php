<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/repo_casts.php';

require_login();
require_role(['admin','manager','super_user']);

$pdo = db();

/* ===== helpers (二重定義を避ける) ===== */
if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('has_role')) {
  function has_role(string $role): bool { return isset($_SESSION['roles']) && in_array($role, $_SESSION['roles'], true); }
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$storeId = (int)($_GET['store_id'] ?? 0);
if ($storeId <= 0) $storeId = (int)($_SESSION['store_id'] ?? 0);
if ($storeId <= 0) {
  header('Location: /seika-app/public/store_select.php?next=' . urlencode($_SERVER['REQUEST_URI']));
  exit;
}
$_SESSION['store_id'] = $storeId;

/* ===== 店舗一覧（既存の関数がある想定） ===== */
$stores = [];
try {
  $st = $pdo->query("SELECT id, name FROM stores ORDER BY id ASC");
  $stores = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $stores = [];
}

$filter = (string)($_GET['filter'] ?? 'all');
if (!in_array($filter, ['all','line_unlinked','active_only'], true)) $filter = 'all';

$casts = repo_fetch_casts($pdo, $storeId, $filter);

/* =========================
   ✅ cast_profiles.note を取得してマップ化
   ========================= */
$noteMap = []; // [user_id] => note
try {
  $ids = [];
  foreach ($casts as $c) $ids[] = (int)($c['id'] ?? 0);
  $ids = array_values(array_unique(array_filter($ids, fn($x)=>$x>0)));

  if ($ids) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT user_id, note
            FROM cast_profiles
            WHERE store_id=?
              AND user_id IN ($ph)";
    $st = $pdo->prepare($sql);
    $st->execute(array_merge([$storeId], $ids));
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
      $uid = (int)$r['user_id'];
      $note = trim((string)($r['note'] ?? ''));
      if ($note !== '') $noteMap[$uid] = $note;
    }
  }
} catch (Throwable $e) {
  // メモ機能が無くても落とさない
  $noteMap = [];
}

/* ===== 店舗名 ===== */
$storeName = '';
foreach ($stores as $s) {
  if ((int)$s['id'] === $storeId) { $storeName = (string)$s['name']; break; }
}

render_page_start('キャスト一覧');
render_header('キャスト一覧',[
  'back_href'=>'/seika-app/public/dashboard.php',
  'back_label'=>'← ダッシュボード'
]);
?>
<div class="page">
  <div class="admin-wrap">

    <div class="rowTop">
      <div>
        <div class="title">👥 キャスト一覧（閲覧）</div>
        <div class="muted" style="margin-top:4px;">
          店舗：<b><?= h($storeName) ?> (#<?= (int)$storeId ?>)</b>
          / 表示：<b><?= h($filter) ?></b>
        </div>
      </div>

      <div class="rowBtns">
        <a class="btn" href="?store_id=<?= (int)$storeId ?>&filter=all">全員</a>
        <a class="btn" href="?store_id=<?= (int)$storeId ?>&filter=line_unlinked">LINE未連携</a>
        <a class="btn" href="?store_id=<?= (int)$storeId ?>&filter=active_only">在籍のみ</a>
      </div>
    </div>

    <div class="card" style="padding:14px; margin-top:12px;">
      <table class="tbl">
        <thead>
          <tr>
            <th style="width:90px">店番</th>
            <th>名前</th>
            <th style="width:110px">雇用</th>
            <th style="width:120px">基本開始</th>
            <th style="width:90px">LINE</th>
            <th style="width:90px">在籍</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($casts as $c): ?>
            <?php
              $uid = (int)($c['id'] ?? 0);

              $tag = trim((string)($c['shop_tag'] ?? ''));
              $tagLabel = ($tag !== '') ? $tag : (string)$uid; // shop_tag未設定の間はIDで保険

              $etype = (string)($c['employment_type'] ?? 'part_time');
              $etypeLabel = ($etype === 'regular') ? 'レギュラー' : 'バイト';

              $dst = $c['default_start_time'] ? substr((string)$c['default_start_time'],0,5) : '-';
              $hasLine = ((int)($c['has_line'] ?? 0) === 1);
              $active  = ((int)($c['is_active'] ?? 0) === 1);

              $note = $noteMap[$uid] ?? '';
            ?>
            <tr>
              <td class="mono"><b><?= h($tagLabel) ?></b></td>
              <td>
                <?php
                  $uid  = (int)($c['id'] ?? 0);
                  $note = (string)($noteMap[$uid] ?? '');
                ?>
                <b><?= h((string)$c['display_name']) ?></b>

                <?php if ($note !== ''): ?>
                  <span class="memoWrap">
                    <span class="memoIcon">📝</span>
                    <span class="memoTip"><?= nl2br(h($note)) ?></span>
                  </span>
                <?php endif; ?>

                <div class="muted">id: <?= (int)$c['id'] ?> / login: <?= h((string)$c['login_id']) ?></div>
              </td>
              <td><?= h($etypeLabel) ?></td>
              <td class="mono"><?= h($dst) ?></td>
              <td>
                <?php if ($hasLine): ?>
                  <span class="badge ok">OK</span>
                <?php else: ?>
                  <span class="badge ng js-line-qr" role="button" data-user-id="<?= (int)$c['id'] ?>">未連携</span>
                  <div class="qrbox" style="display:none"></div>
                <?php endif; ?>
              </td>
              <td><?= $active ? '<span class="badge ok">在籍</span>' : '<span class="badge off">停止</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div class="muted" style="margin-top:10px;">
        ※ 店番は <b>cast_profiles.shop_tag</b> を優先表示します（列が無い/空の間は user_id を表示）。<br>
        ※ 📝 はメモあり（PCはホバー、スマホはタップで表示）
      </div>
    </div>

  </div>
</div>

<style>
.rowTop{ display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap; }
.rowBtns{ display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.title{ font-weight:1000; font-size:18px; line-height:1.2; }
.btn{
  display:inline-flex; align-items:center; gap:6px;
  padding:10px 14px; border-radius:12px;
  border:1px solid var(--line);
  background:var(--cardA); color:inherit;
  text-decoration:none; cursor:pointer;
}
.muted{ opacity:.75; font-size:12px; }
.mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }

.tbl{ width:100%; border-collapse:separate; border-spacing:0; }
.tbl th, .tbl td{ padding:10px 12px; border-bottom:1px solid var(--line); vertical-align:top; }
.tbl th{ text-align:left; font-size:12px; opacity:.8; }
.badge{
  display:inline-flex; align-items:center; justify-content:center;
  padding:3px 10px; border-radius:999px; font-size:12px;
  border:1px solid var(--line);
  background: var(--cardB);
}
.badge.ok{ border-color: rgba(34,197,94,.35); background: rgba(34,197,94,.10); }
.badge.ng{ border-color: rgba(239,68,68,.35); background: rgba(239,68,68,.10); }
.badge.off{ border-color: rgba(148,163,184,.35); background: rgba(148,163,184,.10); }

/* ✅ メモ（PCホバー用ツールチップ） */
.noteIcon{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  margin-left:8px;
  font-size:14px;
  cursor: help;
  position: relative;
  user-select:none;
}
.noteIcon:hover::after{
  content: attr(data-note);
  position:absolute;
  right:0;
  top:22px;
  max-width: 360px;
  white-space: pre-wrap;
  background: rgba(15,23,42,.92);
  color:#fff;
  padding:8px 10px;
  border-radius: 12px;
  font-size: 12px;
  line-height: 1.4;
  box-shadow: 0 12px 30px rgba(0,0,0,.25);
  z-index: 50;
}
.noteIcon:hover::before{
  content:"";
  position:absolute;
  right:12px;
  top:16px;
  border:7px solid transparent;
  border-bottom-color: rgba(15,23,42,.92);
  z-index: 51;
}
/* ===== メモ hover ===== */
.memoWrap{ position:relative; display:inline-block; margin-left:8px; }
.memoIcon{
  display:inline-flex; align-items:center; justify-content:center;
  width:22px; height:22px; border-radius:999px;
  border:1px solid rgba(0,0,0,.12);
  background:rgba(255,255,255,.9);
  cursor:help;
  font-size:13px;
}
.memoTip{
  display:none;
  position:absolute;
  right:0;
  top:26px;
  z-index:50;
  width:260px;
  max-width:70vw;
  padding:10px 12px;
  border-radius:12px;
  border:1px solid rgba(0,0,0,.12);
  background:#fff;
  box-shadow:0 12px 30px rgba(0,0,0,.12);
  color:inherit;
  font-size:12px;
  line-height:1.4;
}
.memoWrap:hover .memoTip{ display:block; }

/* ===== QR（クリック表示） ===== */
.qrbox{
  margin-top:8px;
  padding:10px;
  border-radius:12px;
  border:1px solid rgba(0,0,0,.10);
  background:rgba(255,255,255,.9);
  text-align:center;
}
.qrbox img{ width:130px; height:130px; display:block; margin:0 auto; }
.js-line-qr{ cursor:pointer; user-select:none; }
</style>

<script>
/* ✅ スマホ/タブレットは hover が効きにくいので、タップで表示 */
(function(){
  const isTouch = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);
  if(!isTouch) return;

  document.querySelectorAll('.noteIcon').forEach(el=>{
    el.addEventListener('click', ()=>{
      const note = el.getAttribute('data-note') || '';
      if(note) alert(note);
    });
    el.addEventListener('keydown', (e)=>{
      if(e.key === 'Enter' || e.key === ' '){
        e.preventDefault();
        const note = el.getAttribute('data-note') || '';
        if(note) alert(note);
      }
    });
  });
})();

document.querySelectorAll('.js-line-qr').forEach(el=>{
  el.addEventListener('click', ()=>{
    const td = el.closest('td');
    const box = td ? td.querySelector('.qrbox') : null;
    const userId = el.dataset.userId;

    if(!box || !userId) return;

    // トグル（開いてたら閉じる）
    if (box.dataset.open === '1'){
      box.style.display = 'none';
      box.dataset.open = '0';
      box.innerHTML = '';
      return;
    }

    // ★連携開始URL（あなたの環境のURLに合わせてOK）
    const linkUrl =
      location.origin + '/seika-app/public/line/link_start.php?user_id=' + encodeURIComponent(userId);

    // 軽い外部QR生成（クリックした人だけ）
    const qrImg =
      'https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=' + encodeURIComponent(linkUrl);

    box.innerHTML = `
      <div style="font-weight:900;font-size:12px;opacity:.85;margin-bottom:6px;">LINE連携QR</div>
      <img src="${qrImg}" alt="LINE連携QR">
      <div style="font-size:12px;opacity:.75;margin-top:6px;">読み取って連携してください</div>
    `;
    box.style.display = 'block';
    box.dataset.open = '1';
  });
});
</script>

<?php render_page_end(); ?>