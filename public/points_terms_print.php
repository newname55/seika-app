<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/repo_points.php';
require_once __DIR__ . '/../app/service_points.php';

require_login();
require_role(['admin','manager','super_user']);

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
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = $now->format('Y-m');

$term = (string)($_GET['term'] ?? '');
if (!in_array($term, ['first','second'], true)) {
  $day = (int)$now->format('j');
  $term = ($day >= 16) ? 'second' : 'first';
}

$businessDate = (string)($_GET['business_date'] ?? $now->format('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate)) $businessDate = $now->format('Y-m-d');

[$fromYmd, $toYmd] = service_points_term_range($ym, $term);
if ($businessDate < $fromYmd) $businessDate = $fromYmd;
if ($businessDate > $toYmd)   $businessDate = $toYmd;

/* =========================
   店名
========================= */
$st = $pdo->prepare("SELECT name FROM stores WHERE id=? LIMIT 1");
$st->execute([$storeId]);
$storeName = (string)($st->fetchColumn() ?: ('#'.$storeId));

/* =========================
   キャスト（★通常画面と同じ関数で統一）
   - is_active DESC
   - shop_tag 数値昇順
========================= */
$casts = repo_points_casts_for_store($pdo, $storeId);

/* =========================
   今日（1日分）
========================= */
$dayMap = repo_points_day_map($pdo, $storeId, $businessDate); // [uid]['shimei'|'douhan']

/* =========================
   月初〜今日 累計（紙不要にするやつ）
========================= */
$monthStart = $ym . '-01';
$mtdRows = repo_points_term_summary($pdo, $storeId, $monthStart, $businessDate);
$mtdMap = []; // [uid] => ['douhan_sum'=>..,'shimei_sum'=>..]
$sumMtdDouhan = 0.0;
$sumMtdShimei = 0.0;
foreach ($mtdRows as $r) {
  $uid = (int)($r['user_id'] ?? 0);
  if ($uid > 0) $mtdMap[$uid] = $r;
  $sumMtdDouhan += (float)($r['douhan_sum'] ?? 0);
  $sumMtdShimei += (float)($r['shimei_sum'] ?? 0);
}

/* =========================
   休み(K)：cast_shift_plans is_off=1
========================= */
$offMap = [];
try {
  $st = $pdo->prepare("SELECT user_id FROM cast_shift_plans WHERE store_id=? AND business_date=? AND status='planned' AND is_off=1");
  $st->execute([$storeId, $businessDate]);
  foreach ($st->fetchAll(PDO::FETCH_COLUMN, 0) as $uid) $offMap[(int)$uid] = true;
} catch (Throwable $e) {}

/* =========================
   2面（左/右）に割る
========================= */
$all = $casts;
$half = (int)ceil(count($all) / 2);
$left  = array_slice($all, 0, $half);
$right = array_slice($all, $half);
$maxRows = max(count($left), count($right));

?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ポイント印刷（<?= h($storeName) ?>）</title>
<style>
:root{
  --ink:#111827;
  --muted:#6b7280;
  --line:rgba(17,24,39,.16);
  --line2:rgba(17,24,39,.10);
  --bg:#ffffff;
  --zebra:rgba(17,24,39,.03);
  --kbg:rgba(245,158,11,.16);
  --kline:rgba(245,158,11,.40);
}

body{
  margin:0;
  color:var(--ink);
  background:var(--bg);
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Noto Sans JP","Hiragino Sans","Yu Gothic",Meiryo,Arial,sans-serif;
}

.wrap{
  max-width: 1100px;
  margin: 0 auto;
  padding: 14px 14px 26px;
}

.head{
  display:flex;
  justify-content:space-between;
  align-items:flex-end;
  gap:12px;
  flex-wrap:wrap;
  margin-bottom:10px;
}

.h1{
  font-weight:1000;
  font-size:20px;
}
.sub{
  margin-top:4px;
  color:var(--muted);
  font-size:12px;
}

.meta{
  text-align:right;
  color:var(--muted);
  font-size:12px;
}
.meta b{ color:var(--ink); }

.tools{
  display:flex;
  gap:10px;
  align-items:center;
  justify-content:flex-end;
  margin: 10px 0 12px;
}
.btn{
  border:1px solid var(--line);
  background:#fff;
  border-radius:10px;
  padding:8px 12px;
  font-weight:900;
  cursor:pointer;
}
.btn:active{ transform: translateY(1px); }

.kpi{
  display:grid;
  grid-template-columns: repeat(3, 1fr);
  gap:10px;
  margin-bottom: 12px;
}
.k{
  border:1px solid var(--line2);
  border-radius:12px;
  padding:10px 12px;
  text-align:center;
}
.k .cap{ color:var(--muted); font-size:12px; }
.k .val{ margin-top:4px; font-weight:1000; font-size:16px; }

table{
  width:100%;
  border-collapse:collapse;
  table-layout:fixed;
  font-size:14px;
}
th, td{
  border:1px solid var(--line2);
  padding:8px 8px;
  white-space:nowrap;
  vertical-align:middle;
}
thead th{
  background: #f8fafc;
  font-weight:1000;
  text-align:center;
}
td.num{ text-align:right; font-variant-numeric: tabular-nums; font-weight:900; }
td.no { text-align:center; font-variant-numeric: tabular-nums; font-weight:1000; }
td.name{ text-align:left; font-weight:1000; overflow:hidden; text-overflow:ellipsis; }

.sep{
  width:10px;
  padding:0;
  background: transparent;
  border-left:3px solid rgba(17,24,39,.22);
  border-right:3px solid rgba(17,24,39,.22);
}

tbody tr:nth-child(even){ background: var(--zebra); }

.kBadge{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  margin-left:8px;
  height:20px;
  padding:0 8px;
  border-radius:999px;
  border:1px solid var(--kline);
  background: var(--kbg);
  font-weight:1000;
  font-size:12px;
}

@media print{
  .tools{ display:none !important; }
  .wrap{ max-width: none; padding: 8mm 8mm 10mm; }
  @page { size: A4 landscape; margin: 8mm; }
}
</style>
</head>
<body>
<div class="wrap">

  <div class="head">
    <div>
      <div class="h1">ポイント表（印刷）</div>
      <div class="sub">店舗：<b><?= h($storeName) ?></b> (#<?= (int)$storeId ?>) ／ 本日：<b><?= h($businessDate) ?></b> ／ 月初〜本日 累計</div>
    </div>
    <div class="meta">
      月：<b><?= h($ym) ?></b><br>
      期：<b><?= $term==='first'?'前半(1-15)':'後半(16-末)' ?></b><br>
      生成：<b><?= h((new DateTime('now',$tz))->format('Y-m-d H:i')) ?></b>
    </div>
  </div>

  <div class="tools">
    <button class="btn" onclick="window.print()">🖨 印刷</button>
    <button class="btn" onclick="window.close()">閉じる</button>
  </div>

  <div class="kpi">
    <div class="k">
      <div class="cap">対象</div>
      <div class="val"><?= h($monthStart) ?> 〜 <?= h($businessDate) ?></div>
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <th>店番</th><th>名前</th><th>指名</th><th>指名累計</th><th>同伴</th><th>同伴累計</th>
        <th class="sep"></th>
        <th>店番</th><th>名前</th><th>指名</th><th>指名累計</th><th>同伴</th><th>同伴累計</th>
      </tr>
    </thead>
    <tbody>
      <?php for ($i=0; $i<$maxRows; $i++): ?>
        <?php $L = $left[$i] ?? null; $R = $right[$i] ?? null; ?>
        <tr>
          <?php if ($L): ?>
            <?php
              $uid = (int)($L['user_id'] ?? $L['id'] ?? 0);
              $tag = trim((string)($L['shop_tag'] ?? ''));
              $tagShow = $tag !== '' ? $tag : (string)$uid;

              $daySh = (float)($dayMap[$uid]['shimei'] ?? 0);
              $dayDo = (float)($dayMap[$uid]['douhan'] ?? 0);

              $m = $mtdMap[$uid] ?? ['douhan_sum'=>0,'shimei_sum'=>0];
              $mSh = (float)($m['shimei_sum'] ?? 0);
              $mDo = (float)($m['douhan_sum'] ?? 0);

              $isOff = isset($offMap[$uid]);
            ?>
            <td class="no"><?= h($tagShow) ?></td>
            <td class="name"><?= h((string)$L['display_name']) ?></td>
            <td class="num"><?= $isOff ? 'K' : (string)(int)round($daySh,0) ?></td>
            <td class="num"><?= (string)(int)round($mSh,0) ?></td>
            <td class="num"><?= $isOff ? 'K' : (string)(int)round($dayDo,0) ?></td>
            <td class="num"><?= (string)(int)round($mDo,0) ?></td>
          <?php else: ?>
            <td colspan="6"></td>
          <?php endif; ?>

          <td class="sep"></td>

          <?php if ($R): ?>
            <?php
              $uid = (int)($R['user_id'] ?? $R['id'] ?? 0);
              $tag = trim((string)($R['shop_tag'] ?? ''));
              $tagShow = $tag !== '' ? $tag : (string)$uid;

              $daySh = (float)($dayMap[$uid]['shimei'] ?? 0);
              $dayDo = (float)($dayMap[$uid]['douhan'] ?? 0);

              $m = $mtdMap[$uid] ?? ['douhan_sum'=>0,'shimei_sum'=>0];
              $mSh = (float)($m['shimei_sum'] ?? 0);
              $mDo = (float)($m['douhan_sum'] ?? 0);

              $isOff = isset($offMap[$uid]);
            ?>
            <td class="no"><?= h($tagShow) ?></td>
            <td class="name"><?= h((string)$R['display_name']) ?></td>
            <td class="num"><?= $isOff ? 'K' : (string)(int)round($daySh,0) ?></td>
            <td class="num"><?= (string)(int)round($mSh,0) ?></td>
            <td class="num"><?= $isOff ? 'K' : (string)(int)round($dayDo,0) ?></td>
            <td class="num"><?= (string)(int)round($mDo,0) ?></td>
          <?php else: ?>
            <td colspan="6"></td>
          <?php endif; ?>
        </tr>
      <?php endfor; ?>
    </tbody>
  </table>

</div>
</body>
</html>