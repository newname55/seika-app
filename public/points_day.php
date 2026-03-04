<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';

require_login();
require_role(['admin','manager','super_user']); // 入力は管理側だけ

require_once __DIR__ . '/../app/repo_points.php';
require_once __DIR__ . '/../app/service_points.php';

$pdo = db();

/* ---- helpers (redeclare防止) ---- */
if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('has_role')) {
  function has_role(string $role): bool {
    return isset($_SESSION['roles']) && in_array($role, $_SESSION['roles'], true);
  }
}
if (!function_exists('current_user_id_safe')) {
  function current_user_id_safe(): int {
    return function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
  }
}
if (!function_exists('csrf_token')) {
  function csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['_csrf'];
  }
}
if (!function_exists('csrf_verify')) {
  function csrf_verify(?string $token): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $ok = is_string($token) && $token !== '' && isset($_SESSION['_csrf']) && hash_equals((string)$_SESSION['_csrf'], $token);
    if (!$ok) { http_response_code(403); exit('csrf'); }
  }
}

$userId  = current_user_id_safe();
$isSuper = has_role('super_user');

$stores = repo_points_allowed_stores($pdo, $userId, $isSuper);
if (!$stores) { http_response_code(400); exit('店舗がありません（user_roles に store_id が必要です）'); }

$storeId = (int)($_GET['store_id'] ?? $_POST['store_id'] ?? 0);
if ($storeId <= 0) $storeId = (int)$stores[0]['id'];

$allowedIds = array_map(fn($s)=>(int)$s['id'], $stores);
if (!in_array($storeId, $allowedIds, true)) $storeId = (int)$stores[0]['id'];

/* business_date */
$bizDate = (string)($_GET['business_date'] ?? $_POST['business_date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bizDate)) {
  $bizDate = (new DateTime('today', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
}

$msg = '';
$err = '';

$casts = repo_points_casts_for_store($pdo, $storeId);

/* ---- save ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'save') {
  try {
    csrf_verify($_POST['csrf_token'] ?? null);

    // ここでキャスト一覧を正として保存（存在しないuidは無視される）
    service_points_save_day(
      $pdo,
      $storeId,
      $bizDate,
      $userId,
      $casts,
      [
        'douhan' => (array)($_POST['douhan'] ?? []),
        'shimei' => (array)($_POST['shimei'] ?? []),
        'douhan_note' => (array)($_POST['douhan_note'] ?? []),
        'shimei_note' => (array)($_POST['shimei_note'] ?? []),
      ]
    );

    $msg = '保存しました';
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$dayMap = repo_points_day_map($pdo, $storeId, $bizDate);

$st = $pdo->prepare("SELECT name FROM stores WHERE id=? LIMIT 1");
$st->execute([$storeId]);
$storeName = (string)($st->fetchColumn() ?: ('#'.$storeId));

render_page_start('ポイント（日別）');
render_header('ポイント（日別）',[
  'back_href'=>'/seika-app/public/dashboard.php',
  'back_label'=>'← ダッシュボード'
]);
?>
<div class="page"><div class="admin-wrap">

  <div class="topRow">
    <div>
      <div class="title">⭐ ポイント（日別一括入力）</div>
      <div class="muted">同伴/指名を「その日の合計」で入力（あとで半月合計にする）</div>
    </div>
    <div class="muted">
      店舗：<b><?= h($storeName) ?></b> (#<?= (int)$storeId ?>)
      / 日付：<b><?= h($bizDate) ?></b>
    </div>
  </div>

  <?php if ($err): ?><div class="card" style="border-color:#ef4444"><?= h($err) ?></div><?php endif; ?>
  <?php if ($msg): ?><div class="card" style="border-color:#22c55e"><?= h($msg) ?></div><?php endif; ?>

  <form method="get" class="searchRow">
    <label class="muted">店舗</label>
    <select name="store_id" class="sel">
      <?php foreach ($stores as $s): ?>
        <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id']===$storeId)?'selected':'' ?>>
          <?= h((string)$s['name']) ?> (#<?= (int)$s['id'] ?>)
        </option>
      <?php endforeach; ?>
    </select>

    <label class="muted">日付</label>
    <input class="sel" type="date" name="business_date" value="<?= h($bizDate) ?>">

    <button class="btn">表示</button>

    <a class="btn ghost" href="/seika-app/public/points_terms.php?store_id=<?= (int)$storeId ?>">半月集計へ</a>
  </form>

  <div class="card" style="margin-top:12px;">
    <div class="cardTitle">入力</div>

    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
      <input type="hidden" name="business_date" value="<?= h($bizDate) ?>">

        <?php
        // No順（shop_tag優先、無ければuser_id）
        usort($casts, function($a, $b){
          $ta = trim((string)($a['shop_tag'] ?? ''));
          $tb = trim((string)($b['shop_tag'] ?? ''));
          $na = ($ta !== '') ? (int)preg_replace('/\D+/', '', $ta) : (int)($a['user_id'] ?? 0);
          $nb = ($tb !== '') ? (int)preg_replace('/\D+/', '', $tb) : (int)($b['user_id'] ?? 0);
          return $na <=> $nb;
        });

        // 2列に割る（左列：5→10→16→…）
        $N = count($casts);
        $half = (int)ceil($N / 2);
        $left  = array_slice($casts, 0, $half);
        $right = array_slice($casts, $half);

        $renderRow = function(array $c, int $i) use ($dayMap){
        $uid = (int)$c['user_id'];
        $tag = trim((string)($c['shop_tag'] ?? ''));
        $tagShow = ($tag !== '') ? $tag : (string)$uid;

        $m   = $dayMap[$uid] ?? ['douhan'=>0.0,'shimei'=>0.0];
        $dou = (int)round((float)($m['douhan'] ?? 0), 0);
        $shi = (string)number_format((float)($m['shimei'] ?? 0), 1, '.', '');

        $alt = ($i % 2 === 0) ? 'alt0' : 'alt1';

        ob_start(); ?>
        <div class="tr <?= h($alt) ?>">
          <div class="tname">No,<?= h($tagShow) ?> <?= h((string)$c['display_name']) ?></div>

          <div>
            <input class="inp js-nav js-douhan"
              name="douhan[<?= (int)$uid ?>]"
              value="<?= h((string)$dou) ?>"
              inputmode="numeric">
          </div>

          <div>
            <input class="inp js-nav js-shimei"
              name="shimei[<?= (int)$uid ?>]"
              value="<?= h($shi) ?>"
              inputmode="decimal">
          </div>
        </div>
        <?php return ob_get_clean();
      };
        ?>
        <div class="cols2">
          <div class="col">
            <div class="th">
              <div>キャスト</div>
               <div style="text-align:right;">同伴</div>
                <div style="text-align:right;">指名</div>
            </div>

            <?php foreach ($left as $i => $c): ?>
              <?= $renderRow($c, $i) ?>
            <?php endforeach; ?>
          </div>

          <div class="col">
            <div class="th">
              <div>キャスト</div>
              <div style="text-align:right;">同伴</div>
              <div style="text-align:right;">指名</div>
            </div>

            <?php foreach ($right as $i => $c): ?>
              <?= $renderRow($c, $i + $half) ?>
            <?php endforeach; ?>
          </div>
        </div>

      <div class="footRow">
        <button class="btn primary">💾 保存</button>
        <span class="muted">※空/不正な入力は 0 扱いになります</span>
      </div>
    </form>
  </div>
 </div>
</div>

<style>
.topRow{display:flex;justify-content:space-between;gap:12px;align-items:flex-end;flex-wrap:wrap}
.title{font-weight:1000;font-size:20px}
.card{padding:14px;border:1px solid var(--line);border-radius:14px;background:var(--cardA)}
.cardTitle{font-weight:900;margin-bottom:10px}
.searchRow{margin-top:10px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.sel{padding:10px 12px;border-radius:12px;border:1px solid var(--line);background:var(--cardA);color:inherit}
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 14px;border-radius:12px;border:1px solid var(--line);background:var(--cardA);color:inherit;text-decoration:none;cursor:pointer}
.btn.primary{background:rgba(59,130,246,.18);border-color:rgba(59,130,246,.35)}
.btn.ghost{background:transparent}
.muted{opacity:.75;font-size:12px}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;font-size:12px}

.tblWrap{overflow:auto;border:1px solid rgba(255,255,255,.10);border-radius:12px}
.tbl{width:100%;border-collapse:collapse;min-width:980px}
.tbl th,.tbl td{padding:10px;border-bottom:1px solid rgba(255,255,255,.08);vertical-align:top;white-space:nowrap}
.tbl thead th{position:sticky;top:0;background:var(--cardA);z-index:1}

/* 入力共通 */
.inp{
  width:100%;
  min-width:120px;
  padding:10px 10px;
  border-radius:10px;
  border:1px solid rgba(255,255,255,.14);
  background:rgba(255,255,255,.04);
  color:inherit;
}
.footRow{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:12px}
.badge{display:inline-block;font-size:11px;padding:2px 8px;border-radius:999px;border:1px solid rgba(255,255,255,.18)}
.badge.off{background:rgba(148,163,184,.14);border-color:rgba(148,163,184,.25)}

/* =========================
   表っぽい2列（左/右）＋ 交互色
   ※ $renderRow が <div class="tr alt0|alt1"> を出す前提
========================= */

.cols2{
  display:grid;
  grid-template-columns: 1fr 1fr;
  gap:10px;
  align-items:start;
}
@media (max-width:900px){
  .cols2{ grid-template-columns:1fr; }
}

.col{display:grid;gap:0}

/* 見出し（各列の上） */
.th{
  font-size:12px;
  font-weight:1000;
  opacity:.85;
  padding:8px 12px;
  border:1px solid var(--line);
  border-radius:14px;
  background: color-mix(in srgb, var(--cardA) 70%, transparent);
  display:grid;
  grid-template-columns: 1.2fr 1fr 1fr;
  gap:8px;
  margin-bottom:8px;
}

/* 1行（表の行） */
.tr{
  display:grid;
  grid-template-columns: 1.2fr 1fr 1fr; /* キャスト | 同伴 | 指名 */
  gap:8px;
  align-items:center;
  padding:10px 12px;
  border-left:1px solid var(--line);
  border-right:1px solid var(--line);
  border-bottom:1px solid var(--line);
  background: var(--cardA);
}
.col .tr:first-of-type{
  border-top:1px solid var(--line);
  border-top-left-radius:14px;
  border-top-right-radius:14px;
}
.col .tr:last-child{
  border-bottom-left-radius:14px;
  border-bottom-right-radius:14px;
}

/* 交互色（ミス防止） */
.tr.alt0{ background: color-mix(in srgb, var(--cardA) 92%, transparent); }
.tr.alt1{ background: color-mix(in srgb, var(--cardA) 78%, transparent); }

/* 左セル：No,5 なつき */
.tname{
  font-weight:1000;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
}

/* 入力セル */
.tcell{
  display:flex;
  align-items:center;
  justify-content:flex-end;
  gap:6px;
}
.tcell .lbl{
  font-size:12px;
  font-weight:1000;
  opacity:.75;
  white-space:nowrap;
}

/* この表用に入力幅を細く */
.tcell input.inp{
  min-width:0;
  width:70px;
  min-height:38px;
  padding:6px 8px;
  border-radius:10px;
  text-align:right;
}
@media (max-width:420px){
  .tcell input.inp{ width:62px; }
}

/* フォーカス行を強調（ミス防止） */
.tr:focus-within{
  outline:2px solid rgba(59,130,246,.35);
  outline-offset:-2px;
  filter:brightness(1.05);
}
/* 強制：tr方式を最優先で効かせる */
.cols2{display:grid !important;grid-template-columns:1fr 1fr !important;gap:10px !important;align-items:start !important;}
@media (max-width:900px){.cols2{grid-template-columns:1fr !important;}}

.col{display:grid !important;gap:0 !important;}

.th{
  display:grid !important;
  grid-template-columns:1.2fr 1fr 1fr !important;
  gap:8px !important;
}



.tr{ border-left: 4px solid color-mix(in srgb, var(--line) 60%, transparent) !important; }
.tr.alt0{ border-left-color: color-mix(in srgb, var(--line) 85%, transparent) !important; }
.tr.alt1{ border-left-color: color-mix(in srgb, var(--line) 55%, transparent) !important; }

.tcell{display:flex !important;justify-content:flex-end !important;align-items:center !important;gap:6px !important;}
.tcell input.inp{width:70px !important;min-width:0 !important;min-height:38px !important;text-align:right !important;}
/* 表っぽさを強める（Lightでも必ず見える） */
.th{
  background: color-mix(in srgb, var(--cardA) 85%, var(--line) 15%) !important;
  border-color: color-mix(in srgb, var(--line) 70%, transparent) !important;
}

.tr{
  border-left: 1px solid color-mix(in srgb, var(--line) 70%, transparent) !important;
  border-right:1px solid color-mix(in srgb, var(--line) 70%, transparent) !important;
  border-bottom:1px solid color-mix(in srgb, var(--line) 60%, transparent) !important;
  box-shadow: inset 0 1px 0 color-mix(in srgb, var(--line) 45%, transparent) !important;
}

/* 交互色：transparent混ぜると差が出ないのでlineを混ぜる */
.tr.alt0{ background: color-mix(in srgb, var(--cardA) 92%, var(--line) 8%) !important; }
.tr.alt1{ background: color-mix(in srgb, var(--cardA) 82%, var(--line) 18%) !important; }

/* 入力を“セル”っぽく */
.tr input.inp{
  width: 88px !important;      /* 100%をやめる */
  min-width: 88px !important;
  max-width: 110px !important;
  padding: 6px 8px !important;
  border: 1px solid color-mix(in srgb, var(--line) 85%, transparent) !important;
  background: color-mix(in srgb, var(--cardA) 70%, transparent) !important;
  border-radius: 10px !important;
  text-align: right !important;
}

/* 入力中の行を強調（事故防止） */
.tr:focus-within{
  outline: 2px solid rgba(59,130,246,.35) !important;
  outline-offset: -2px !important;
}
</style>
<script>
(function(){
  function getCell(row, col){
    return document.querySelector('input.js-nav[data-row="'+row+'"][data-col="'+col+'"]');
  }

  function focusCell(row, col){
    const el = getCell(row, col);
    if(!el) return false;
    if(el.disabled || el.offsetParent === null) return false;
    el.focus();
    if(typeof el.select === 'function') el.select();
    return true;
  }

  document.addEventListener('keydown', function(e){
    const el = e.target;
    if(!(el instanceof HTMLInputElement)) return;
    if(!el.classList.contains('js-nav')) return;

    // 日本語IME変換中の事故防止
    if(e.isComposing) return;

    const row = Number(el.dataset.row || '0');
    const col = String(el.dataset.col || '');

    // 上下：同じ列で移動
    if(e.key === 'ArrowDown'){
      e.preventDefault();
      focusCell(row + 1, col);
      return;
    }
    if(e.key === 'ArrowUp'){
      e.preventDefault();
      if(row > 0) focusCell(row - 1, col);
      return;
    }

    // 左右：同じ行で douhan <-> shimei
    if(e.key === 'ArrowLeft'){
      if(col === 'shimei'){
        e.preventDefault();
        focusCell(row, 'douhan');
      }
      return;
    }
    if(e.key === 'ArrowRight'){
      if(col === 'douhan'){
        e.preventDefault();
        focusCell(row, 'shimei');
      }
      return;
    }

    // おまけ：Enterで下へ（要らなければ消してOK）
    // if(e.key === 'Enter'){
    //   e.preventDefault();
    //   focusCell(row + 1, col);
    // }
  }, true);
})();
</script>
<?php render_page_end(); ?>