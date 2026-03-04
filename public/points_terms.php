<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/repo_points.php';
require_once __DIR__ . '/../app/service_points.php';

require_login();
require_role(['admin','manager','super_user']); // 集計は管理側だけ

$pdo = db();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

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

$userId  = current_user_id_safe();
$isSuper = has_role('super_user');

$stores = repo_points_allowed_stores($pdo, $userId, $isSuper);
if (!$stores) { http_response_code(400); exit('店舗がありません'); }

$storeId = (int)($_GET['store_id'] ?? 0);
if ($storeId <= 0) $storeId = (int)$stores[0]['id'];

$allowedIds = array_map(fn($s)=>(int)$s['id'], $stores);
if (!in_array($storeId, $allowedIds, true)) $storeId = (int)$stores[0]['id'];

$tz = new DateTimeZone('Asia/Tokyo');
$now = new DateTime('now', $tz);

$ym = (string)($_GET['ym'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
  $ym = $now->format('Y-m');
}

$term = (string)($_GET['term'] ?? '');
if (!in_array($term, ['first','second'], true)) {
  // 今日が16日以降なら後半をデフォルトにする
  $day = (int)$now->format('j');
  $term = ($day >= 16) ? 'second' : 'first';
}

// 紙の「本日」＝指定日の合計（shimei/douhan）
$businessDate = (string)($_GET['business_date'] ?? (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate)) {
  $businessDate = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
}

[$fromYmd, $toYmd] = service_points_term_range($ym, $term);

// 本日が期間外なら期間内に寄せる
if ($businessDate < $fromYmd) $businessDate = $fromYmd;
if ($businessDate > $toYmd)   $businessDate = $toYmd;

// 店名
$st = $pdo->prepare("SELECT name FROM stores WHERE id=? LIMIT 1");
$st->execute([$storeId]);
$storeName = (string)($st->fetchColumn() ?: ('#'.$storeId));

// キャスト一覧（0も表示）
$casts = repo_points_casts_for_store($pdo, $storeId);

// 期間合計
$termRows = repo_points_term_summary($pdo, $storeId, $fromYmd, $toYmd);
$termMap = []; // [user_id] => ['douhan_sum'=>..,'shimei_sum'=>..]
$sumDouhan = 0.0;
$sumShimei = 0.0;
foreach ($termRows as $r) {
  $uid = (int)$r['user_id'];
  $uid = (int)($r['user_id'] ?? $r['id'] ?? 0);
  if ($uid > 0) $termMap[$uid] = $r;
  $sumDouhan += (float)($r['douhan_sum'] ?? 0);
  $sumShimei += (float)($r['shimei_sum'] ?? 0);
}

// 本日（1日）
$dayMap = repo_points_day_map($pdo, $storeId, $businessDate); // [uid][type] => value

// 休み(K): cast_shift_plans.is_off=1 のみをK表示
$offMap = [];
try {
  $st = $pdo->prepare("SELECT user_id FROM cast_shift_plans WHERE store_id=? AND business_date=? AND status='planned' AND is_off=1");
  $st->execute([$storeId, $businessDate]);
  foreach ($st->fetchAll(PDO::FETCH_COLUMN, 0) as $uid) {
    $offMap[(int)$uid] = true;
  }
} catch (Throwable $e) {
  // テーブル未作成などでも落とさない
}

render_page_start('ポイント（半月）');
render_header('ポイント（半月）',[
  'back_href'=>'/seika-app/public/dashboard.php',
  'back_label'=>'← ダッシュボード'
]);

$all = $casts; // 既存キャスト配列
$half = (int)ceil(count($all) / 2);

$left  = array_slice($all, 0, $half);
$right = array_slice($all, $half);

$maxRows = max(count($left), count($right));
?>

<div class="page"><div class="admin-wrap">

  <div class="topRow">
    <div>
      <div class="title">📊 ポイント（半月）</div>
      <div class="muted">前半：1〜15 / 後半：16〜末（固定）</div>
    </div>
    <div class="muted">
      <?php
$printUrl = sprintf(
  'points_terms_print.php?store_id=%d&business_date=%s&ym=%s&term=%s',
  $storeId,
  urlencode((string)$businessDate),
  urlencode((string)$ym),
  urlencode((string)$term)
);
?>
<a href="<?= h($printUrl) ?>" target="_blank" class="btn">🖨 印刷</a>
    </div>
  </div>

  <form method="get" class="searchRow">
    <label class="muted">店舗</label>
    <select name="store_id" class="sel">
      <?php foreach ($stores as $s): ?>
        <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id']===$storeId)?'selected':'' ?>>
          <?= h((string)$s['name']) ?> (#<?= (int)$s['id'] ?>)
        </option>
      <?php endforeach; ?>
    </select>

    <label class="muted">月</label>
    <input class="sel" type="month" name="ym" value="<?= h($ym) ?>">

    <label class="muted">期</label>
    <select class="sel" name="term">
      <option value="first" <?= $term==='first'?'selected':'' ?>>前半（1〜15）</option>
      <option value="second" <?= $term==='second'?'selected':'' ?>>後半（16〜末）</option>
    </select>

    <label class="muted">本日</label>
    <input class="sel" type="date" name="business_date" value="<?= h($businessDate) ?>">

    <button class="btn">表示</button>
    <a class="btn ghost" href="/seika-app/public/points_day.php?store_id=<?= (int)$storeId ?>&business_date=<?= h($businessDate) ?>">日別入力へ</a>
  </form>
  
  <div class="kpi">
    <div class="k">期間<br><b class="mono"><?= h($fromYmd) ?> 〜 <?= h($toYmd) ?></b></div>
    <div class="k">同伴 合計<br><b><?= h(number_format($sumDouhan, 2)) ?></b></div>
    <div class="k">指名 合計<br><b><?= h(rtrim(rtrim(number_format((float)$sumShimei,1,'.',''),'0'),'.')) ?></b></div>
  </div>

<div class="listWrap">
  <table class="compactTbl">
    <thead>
      <tr>
        <th>店番</th>
        <th>名前</th>
        <th>指名</th>
        <th>合計</th>
        <th>同伴</th>
        <th>合計</th>

        <th class="sep"></th>

        <th>店番</th>
        <th>名前</th>
        <th>同伴</th>
        <th>合計</th>
        <th>指名</th>
        <th>合計</th>
      </tr>
    </thead>
    <tbody>
<?php for ($i = 0; $i < $maxRows; $i++): ?>
  <?php
    $L = $left[$i]  ?? null;
    $R = $right[$i] ?? null;
  ?>
  <tr>
    <?php
    // ===== LEFT SIDE =====
    if ($L):
      $uid = (int)($L['user_id'] ?? $L['id'] ?? 0);
      $tag = trim((string)($L['shop_tag'] ?? ''));
      $tagShow = $tag !== '' ? $tag : $uid;

      $t = $termMap[$uid] ?? ['douhan_sum'=>0,'shimei_sum'=>0];
      $daySh = (float)($dayMap[$uid]['shimei'] ?? 0);
      $dayDo = (float)($dayMap[$uid]['douhan'] ?? 0);
    ?>
      <td class="mono"><?= h($tagShow) ?></td>
      <td class="name"><?= h($L['display_name']) ?></td>
      <td class="num"><?= (int)round($dayDo,0) ?></td>
      <td class="num"><?= (int)round((float)$t['douhan_sum'],0) ?></td>
      <td class="num"><?= number_format((float)$daySh, 1, '.', '') ?></td>
      <td class="num"><?= number_format((float)$t['shimei_sum'], 1, '.', '') ?></td>
    <?php else: ?>
      <td colspan="6"></td>
    <?php endif; ?>

    <td class="sep"></td>

    <?php
    // ===== RIGHT SIDE =====
    if ($R):
      $uid = (int)($R['user_id'] ?? $R['id'] ?? 0);
      $tag = trim((string)($R['shop_tag'] ?? ''));
      $tagShow = $tag !== '' ? $tag : $uid;

      $t = $termMap[$uid] ?? ['douhan_sum'=>0,'shimei_sum'=>0];
      $daySh = (float)($dayMap[$uid]['shimei'] ?? 0);
      $dayDo = (float)($dayMap[$uid]['douhan'] ?? 0);
    ?>
      <td class="mono"><?= h($tagShow) ?></td>
      <td class="name"><?= h($R['display_name']) ?></td>
      <td class="num"><?= (int)round($daySh,0) ?></td>
      <td class="num"><?= (int)round((float)$t['shimei_sum'],0) ?></td>
      <td class="num"><?= (int)round($dayDo,0) ?></td>
      <td class="num"><?= (int)round((float)$t['douhan_sum'],0) ?></td>
    <?php else: ?>
      <td colspan="6"></td>
    <?php endif; ?>
  </tr>
<?php endfor; ?>
    </tbody>
  </table>
</div>

</div></div>

<style>
/* ===============================
   共通
=============================== */
.topRow{
  display:flex;
  justify-content:space-between;
  gap:12px;
  align-items:flex-end;
  flex-wrap:wrap;
}

.title{
  font-weight:1000;
  font-size:22px;
}

.card{
  padding:16px;
  border:1px solid var(--line);
  border-radius:14px;
  background:var(--cardA);
}

.cardTitle{
  font-weight:1000;
  font-size:18px;
  margin-bottom:12px;
}

.searchRow{
  margin-top:10px;
  display:flex;
  gap:10px;
  align-items:center;
  flex-wrap:wrap;
}

.sel{
  padding:10px 14px;
  border-radius:12px;
  border:1px solid var(--line);
  background:var(--cardA);
  color:inherit;
  font-size:14px;
}

.btn{
  display:inline-flex;
  align-items:center;
  gap:6px;
  padding:10px 16px;
  border-radius:12px;
  border:1px solid var(--line);
  background:var(--cardA);
  color:inherit;
  text-decoration:none;
  cursor:pointer;
  font-weight:700;
}

.btn.ghost{ background:transparent; }

.muted{
  opacity:.75;
  font-size:13px;
}

.mono{
  font-family: ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;
}

/* ===============================
   KPI
=============================== */
.kpi{
  margin-top:14px;
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(240px,1fr));
  gap:12px;
}

.k{
  padding:14px;
  border:1px solid var(--line);
  border-radius:14px;
  background:var(--cardA);
  text-align:center;
  font-size:15px;
}

/* ===============================
   一覧テーブル（2面表示）
=============================== */
/* 横スクロール禁止 */
.listWrap{
  margin-top:12px;
  overflow:hidden;
}

/* テーブル：紙っぽく罫線 */
.compactTbl{
  width:100%;
  border-collapse:collapse;     /* 罫線を一本に */
  table-layout:fixed;           /* 幅を固定配分して1画面に収める */
  font-size:16px;               /* 文字大きめ */
  background: var(--cardA);
}

/* セル共通：縦横の罫線 */
.compactTbl th,
.compactTbl td{
  padding:10px 8px;
  border:1px solid rgba(0,0,0,.10);   /* ✅ 罫線（縦横） */
  white-space:nowrap;
  vertical-align:middle;
}

/* 見出し */
.compactTbl thead th{
  font-weight:1000;
  text-align:center;
  background: rgba(255,255,255,.55);
}

/* 店番 */
.compactTbl td.mono{
  text-align:center;
  font-variant-numeric: tabular-nums;
  font-weight:900;
}

/* 名前 */
.compactTbl td.name{
  text-align:left;
  font-weight:1000;
  overflow:hidden;
  text-overflow:ellipsis;
}

/* 数字 */
.compactTbl td.num{
  text-align:right;
  font-variant-numeric: tabular-nums;
  font-weight:900;
}

/* 中央セパレータ列：幅を細く＆縦の太線にする */
.compactTbl th.sep,
.compactTbl td.sep{
  width:12px;
  padding:0;
  background: transparent;
  border-left:3px solid rgba(0,0,0,.20);  /* ✅ 中央の太線 */
  border-right:3px solid rgba(0,0,0,.20);
}

/* 行の見やすさ：うっすらゼブラ */
.compactTbl tbody tr:nth-child(even){
  background: rgba(0,0,0,.02);
}

/* PCだけホバー */
@media (hover:hover){
  .compactTbl tbody tr:hover{
    background: rgba(124,92,255,.06);
  }
}
</style>

<?php render_page_end(); ?>