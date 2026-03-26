<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/service_transport.php';

require_login();
if (function_exists('require_role')) {
  require_role(['cast','admin','manager','super_user']); // cast本人が使う想定
}

$pdo = db();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function current_user_id_safe(): int {
  return function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
}

/** store_id 解決（既存のストア選択がある前提） */
function resolve_store_id(PDO $pdo): int {
  if (function_exists('current_store_id')) {
    $sid = (int)current_store_id();
    if ($sid > 0) return $sid;
  }
  $sid = (int)($_GET['store_id'] ?? $_POST['store_id'] ?? ($_SESSION['store_id'] ?? 0));
  if ($sid <= 0) {
    header('Location: /wbss/public/store_select.php?next=' . urlencode('/wbss/public/profile.php'));
    exit;
  }
  $_SESSION['store_id'] = $sid;
  return $sid;
}

/** CSRF */
function csrf_token(): string {
  if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
  return (string)$_SESSION['_csrf'];
}
function csrf_verify(?string $token): void {
  if (!$token || empty($_SESSION['_csrf']) || !hash_equals((string)$_SESSION['_csrf'], (string)$token)) {
    http_response_code(403);
    exit('csrf');
  }
}

/** YYYY-mm-dd */
function jst_today_ymd(): string {
  return (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
}
function jst_yesterday_ymd(): string {
  return (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->modify('-1 day')->format('Y-m-d');
}

/** "20:00" or "20:00:00" -> "HH:MM:00" (or null) */
function normalize_time(?string $t): ?string {
  $t = trim((string)$t);
  if ($t === '') return null;
  if (preg_match('/^\d{2}:\d{2}$/', $t)) {
    [$hh,$mm] = array_map('intval', explode(':', $t));
    if ($hh<0||$hh>23||$mm<0||$mm>59) return null;
    return sprintf('%02d:%02d:00', $hh, $mm);
  }
  if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $t)) return $t;
  return null;
}

/** 今期(半月)の start/end */
function current_half_period(string $todayYmd): array {
  $d = new DateTime($todayYmd, new DateTimeZone('Asia/Tokyo'));
  $day = (int)$d->format('j'); // 1..31
  $ym  = $d->format('Y-m');
  if ($day <= 15) {
    $start = $ym . '-01';
    $end   = $ym . '-15';
    $label = '前半（1〜15日）';
  } else {
    $start = $ym . '-16';
    $end   = (clone $d)->modify('last day of this month')->format('Y-m-d');
    $label = '後半（16日〜月末）';
  }
  return [$start, $end, $label];
}

/** points集計（指定期間） */
function fetch_points_summary(PDO $pdo, int $storeId, int $castUserId, string $startYmd, string $endYmd): array {
  $out = ['douhan'=>0.0,'shimei'=>0.0,'total'=>0.0];
  $st = $pdo->prepare("
    SELECT point_type, SUM(point_value) AS s
    FROM cast_points
    WHERE store_id = ?
      AND cast_user_id = ?
      AND business_date BETWEEN ? AND ?
    GROUP BY point_type
  ");
  $st->execute([$storeId, $castUserId, $startYmd, $endYmd]);
  foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
    $t = (string)$r['point_type'];
    $s = (float)$r['s'];
    if ($t === 'douhan' || $t === 'shimei') $out[$t] = $s;
  }
  $out['total'] = (float)$out['douhan'] + (float)$out['shimei'];
  return $out;
}

/** note から #goal_total=xx #goal_shimei=xx #goal_douhan=xx を読む */
function parse_goals_from_note(?string $note): array {
  $note = (string)($note ?? '');
  $out = ['total'=>null,'shimei'=>null,'douhan'=>null];
  if (preg_match('/#goal_total=(\d+(?:\.\d+)?)/u', $note, $m)) $out['total'] = (float)$m[1];
  if (preg_match('/#goal_shimei=(\d+(?:\.\d+)?)/u', $note, $m)) $out['shimei'] = (float)$m[1];
  if (preg_match('/#goal_douhan=(\d+(?:\.\d+)?)/u', $note, $m)) $out['douhan'] = (float)$m[1];
  return $out;
}

/** 直近N日（今日含む）の日付配列 */
function last_n_dates(string $todayYmd, int $n): array {
  $d = new DateTime($todayYmd, new DateTimeZone('Asia/Tokyo'));
  $out = [];
  for ($i=$n-1; $i>=0; $i--) {
    $x = (clone $d)->modify('-'.$i.' day')->format('Y-m-d');
    $out[] = $x;
  }
  return $out;
}

/** 直近N日の日別合計を取得（cast_points） */
function fetch_points_daily_totals(PDO $pdo, int $storeId, int $castUserId, string $startYmd, string $endYmd): array {
  // [ymd => total]
  $map = [];
  $st = $pdo->prepare("
    SELECT business_date, SUM(point_value) AS s
    FROM cast_points
    WHERE store_id = ?
      AND cast_user_id = ?
      AND business_date BETWEEN ? AND ?
    GROUP BY business_date
    ORDER BY business_date
  ");
  $st->execute([$storeId, $castUserId, $startYmd, $endYmd]);
  foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
    $ymd = (string)$r['business_date'];
    $map[$ymd] = (float)$r['s'];
  }
  return $map;
}

/** スパークライン SVG（values配列） */
function sparkline_svg(array $values, int $w=220, int $h=54): string {
  $n = count($values);
  if ($n <= 1) return '';
  $min = min($values);
  $max = max($values);
  $pad = 4;

  // 全部同値なら少しだけ幅を持たせる
  if (abs($max - $min) < 1e-9) { $max = $min + 1.0; }

  $dx = ($w - $pad*2) / ($n - 1);
  $pts = [];
  for ($i=0; $i<$n; $i++) {
    $x = $pad + $dx * $i;
    $v = (float)$values[$i];
    $t = ($v - $min) / ($max - $min); // 0..1
    $y = ($h - $pad) - ($h - $pad*2) * $t;
    $pts[] = sprintf('%.2f,%.2f', $x, $y);
  }

  $poly = implode(' ', $pts);

  // 塗りつぶし（底まで閉じる）
  $area = $pts;
  $area[] = sprintf('%.2f,%.2f', $w-$pad, $h-$pad);
  $area[] = sprintf('%.2f,%.2f', $pad, $h-$pad);
  $areaPoly = implode(' ', $area);

  // NOTE: CSS変数の色を使うため style 属性で var() を使う
  return '
  <svg viewBox="0 0 '.$w.' '.$h.'" width="'.$w.'" height="'.$h.'" aria-label="7日推移" role="img">
    <polyline points="'.$areaPoly.'" fill="rgba(124,92,255,.12)" stroke="none"></polyline>
    <polyline points="'.$poly.'" fill="none" stroke="rgba(124,92,255,.85)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"></polyline>
  </svg>';
}

/** 0..1 clamp */
function clamp01(float $x): float { return max(0.0, min(1.0, $x)); }

$storeId = resolve_store_id($pdo);
$userId  = current_user_id_safe();
if ($userId <= 0) { http_response_code(401); exit('not logged in'); }

// 店名
$st = $pdo->prepare("SELECT name FROM stores WHERE id=? LIMIT 1");
$st->execute([$storeId]);
$storeName = (string)($st->fetchColumn() ?: ('#'.$storeId));

// ユーザー
$st = $pdo->prepare("SELECT display_name, is_active FROM users WHERE id=? LIMIT 1");
$st->execute([$userId]);
$u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
$displayName  = (string)($u['display_name'] ?? ('user#'.$userId));
$isActiveUser = (int)($u['is_active'] ?? 1);

// cast_profiles 読み込み（無ければデフォ）
$profile = [
  'employment_type'     => 'part',
  'default_start_time'  => '20:00',
  'shop_tag'            => '',
  'note'                => '',
];
try {
  $st = $pdo->prepare("
    SELECT employment_type, default_start_time, shop_tag, note
    FROM cast_profiles
    WHERE user_id=? AND store_id=? LIMIT 1
  ");
  $st->execute([$userId, $storeId]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  if ($r) {
    $profile['employment_type'] = (string)($r['employment_type'] ?? 'part');
    $profile['default_start_time'] = ($r['default_start_time'] !== null && $r['default_start_time'] !== '')
      ? substr((string)$r['default_start_time'], 0, 5)
      : '20:00';
    $profile['shop_tag'] = (string)($r['shop_tag'] ?? '');
    $profile['note']     = (string)($r['note'] ?? '');
  }
} catch (Throwable $e) {}

$transportProfile = transport_fetch_profile($pdo, $storeId, $userId);
$transportHasSecondary = transport_profile_has_secondary_fields($pdo);
$transportHasPickupTarget = transport_profile_has_pickup_target_field($pdo);

// 保存
$msg = '';
$err = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  csrf_verify($_POST['csrf_token'] ?? null);

  if ($isActiveUser !== 1) {
    $err = '非在籍のため編集できません';
  } else {
    $newStart = normalize_time($_POST['default_start_time'] ?? '');
    $newNote  = trim((string)($_POST['note'] ?? ''));

    if ($newStart === null) {
      $err = '基本開始時刻が不正です（例：20:00）';
    } else {
      $pdo->beginTransaction();
      try {
        $up = $pdo->prepare("
          INSERT INTO cast_profiles
            (user_id, store_id, employment_type, default_start_time, shop_tag, note, created_at, updated_at)
          VALUES
            (?, ?, ?, ?, ?, ?, NOW(), NOW())
          ON DUPLICATE KEY UPDATE
            store_id=VALUES(store_id),
            default_start_time=VALUES(default_start_time),
            note=VALUES(note),
            updated_at=NOW()
        ");
        $up->execute([
          $userId,
          $storeId,
          $profile['employment_type'], // 雇用は本人は触らない
          $newStart,
          $profile['shop_tag'],        // shop_tagも本人は触らない
          $newNote,
        ]);

        transport_save_profile($pdo, $storeId, $userId, $_POST, $userId);

        // 監査ログ（cast_shift_logs を流用）
        try {
          $lg = $pdo->prepare("
            INSERT INTO cast_shift_logs (store_id, user_id, action, payload_json, created_by_user_id)
            VALUES (?, ?, 'cast.profile.update', ?, ?)
          ");
          $lg->execute([
            $storeId,
            $userId,
            json_encode([
              'default_start_time' => substr($newStart,0,5),
              'note' => $newNote,
              'transport_updated' => true,
            ], JSON_UNESCAPED_UNICODE),
            $userId,
          ]);
        } catch (Throwable $e) {}

        $pdo->commit();

        $profile['default_start_time'] = substr($newStart,0,5);
        $profile['note'] = $newNote;
        $transportProfile = transport_fetch_profile($pdo, $storeId, $userId);
        $msg = '保存しました';
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $err = '保存失敗: ' . $e->getMessage();
      }
    }
  }
}

// ポイント表示（昨日 / 今期(半月)）
$today = jst_today_ymd();
$yday  = jst_yesterday_ymd();

[$pStart, $pEnd, $pLabel] = current_half_period($today);

$ySum = fetch_points_summary($pdo, $storeId, $userId, $yday, $yday);
$hSum = fetch_points_summary($pdo, $storeId, $userId, $pStart, $pEnd);

// 指名比率
$ratio = 0.0;
if ($hSum['total'] > 0.0) $ratio = ($hSum['shimei'] / $hSum['total']) * 100.0;

// 目標（noteから読める。無ければデフォ）
$goals = parse_goals_from_note($profile['note']);

// デフォ目標（ここは好みで調整）
$defaultGoalTotal  = ($profile['employment_type'] === 'regular') ? 40.0 : 25.0;
$defaultGoalShimei = ($profile['employment_type'] === 'regular') ? 25.0 : 15.0;
$defaultGoalDouhan = ($profile['employment_type'] === 'regular') ? 15.0 : 10.0;

$employmentTypeLabel = match ((string)$profile['employment_type']) {
  'regular' => 'レギュラー',
  'part' => 'バイト',
  default => (string)$profile['employment_type'],
};

$goalTotal  = $goals['total']  ?? $defaultGoalTotal;
$goalShimei = $goals['shimei'] ?? $defaultGoalShimei;
$goalDouhan = $goals['douhan'] ?? $defaultGoalDouhan;

// 進捗（0..1）
$progTotal  = ($goalTotal  > 0) ? clamp01($hSum['total']  / $goalTotal)  : 0.0;
$progShimei = ($goalShimei > 0) ? clamp01($hSum['shimei'] / $goalShimei) : 0.0;
$progDouhan = ($goalDouhan > 0) ? clamp01($hSum['douhan'] / $goalDouhan) : 0.0;

// 直近7日推移
$last7 = last_n_dates($today, 7);
$map7  = fetch_points_daily_totals($pdo, $storeId, $userId, $last7[0], $last7[count($last7)-1]);
$vals7 = [];
foreach ($last7 as $d) $vals7[] = (float)($map7[$d] ?? 0.0);
$spark = sparkline_svg($vals7, 260, 60);

// UI
render_page_start('プロフィール');
render_header('プロフィール', [
  'back_href' => '/wbss/public/dashboard_cast.php',
  'back_label' => '← ダッシュボード',
  'right_html' => '<a class="btn ghost" href="/wbss/public/cast_week.php">📅 出勤（週）</a>',
]);

?>
<style>
:root{
  --bg1:#f7f8ff;
  --bg2:#fff6fb;
  --card:#ffffff;
  --ink:#1f2937;
  --muted:#6b7280;
  --line:rgba(15,23,42,.10);

  --pink:#ff5fa2;
  --purple:#7c5cff;
  --mint:#34d399;
  --sky:#60a5fa;
  --amber:#f59e0b;

  --shadow: 0 10px 30px rgba(17,24,39,.08);
  --shadow2: 0 6px 16px rgba(17,24,39,.06);
}
.page{
  background:
    radial-gradient(1000px 600px at 10% 0%, rgba(124,92,255,.12), transparent 60%),
    radial-gradient(900px 600px at 90% 10%, rgba(255,95,162,.14), transparent 60%),
    linear-gradient(180deg, var(--bg1), var(--bg2));
  min-height: 100vh;
}
.wrap{
  max-width: 980px;
  margin: 0 auto;
  padding: 14px 12px 40px;
}
.hero{
  background: linear-gradient(135deg, rgba(124,92,255,.16), rgba(255,95,162,.10));
  border:1px solid rgba(124,92,255,.18);
  border-radius: 18px;
  padding: 14px;
  box-shadow: var(--shadow2);
}
.heroTop{
  display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap;
}
.h1{
  font-weight:1000; font-size:18px; color:var(--ink);
  display:flex; align-items:center; gap:8px;
}
.sub{ margin-top:4px; font-size:12px; color:var(--muted); }
.btn{
  appearance:none;
  border:1px solid var(--line);
  background: rgba(255,255,255,.95);
  color: var(--ink);
  padding: 10px 14px;
  border-radius: 14px;
  font-weight:900;
  cursor:pointer;
  text-decoration:none;
  box-shadow: var(--shadow2);
}
.btn:active{ transform: translateY(1px); }
.btn.ghost{ background: rgba(255,255,255,.7); }
.btn.primary{
  border-color: rgba(124,92,255,.25);
  background: linear-gradient(135deg, rgba(124,92,255,.18), rgba(255,95,162,.12));
}
.btn:disabled{ opacity:.55; cursor:not-allowed; }

.notice{
  margin-top:10px; padding:10px 12px; border-radius:14px;
  border:1px solid var(--line); background: rgba(255,255,255,.85);
}
.notice.ok{ border-color: rgba(52,211,153,.35); background: rgba(52,211,153,.10); }
.notice.ng{ border-color: rgba(239,68,68,.35); background: rgba(239,68,68,.10); }

.grid{
  margin-top:12px;
  display:grid;
  grid-template-columns: 1fr;
  gap:12px;
}

.card{
  border:1px solid var(--line);
  background: rgba(255,255,255,.92);
  border-radius: 18px;
  box-shadow: var(--shadow);
  overflow:hidden;
}
.cardHead{
  padding:12px 12px 10px;
  border-bottom:1px solid rgba(15,23,42,.06);
  display:flex; align-items:center; justify-content:space-between; gap:10px;
}
.cardTitle{ font-weight:1000; font-size:14px; display:flex; gap:8px; align-items:center; }
.body{ padding:12px; }

.badge{
  display:inline-flex; align-items:center; gap:6px;
  padding:4px 10px; border-radius:999px;
  border:1px solid rgba(15,23,42,.10);
  background: rgba(255,255,255,.8);
  font-weight:900; font-size:12px;
}
.badge.p{ border-color: rgba(124,92,255,.25); background: rgba(124,92,255,.08); }
.badge.m{ border-color: rgba(52,211,153,.25); background: rgba(52,211,153,.08); }
.badge.a{ border-color: rgba(245,158,11,.25); background: rgba(245,158,11,.08); }

.kpis{
  display:grid;
  grid-template-columns: 1fr 1fr;
  gap:10px;
}
@media (max-width: 620px){ .kpis{ grid-template-columns: 1fr; } }

.kpi{
  border:1px solid rgba(15,23,42,.08);
  background: rgba(255,255,255,.85);
  border-radius:16px;
  padding:9px 10px;
}
.kpiTop{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:8px;
}
.kpi .lbl{ font-size:12px; color:var(--muted); font-weight:900; line-height:1.2; }
.kpiMeta{ font-size:11px; color:var(--muted); font-weight:800; text-align:right; line-height:1.3; }
.valRow{
  display:flex;
  align-items:flex-end;
  justify-content:space-between;
  gap:8px;
  margin-top:4px;
}
.kpi .val{ font-weight:1000; font-size:22px; line-height:1; }
.kpi .sub2{ margin-top:6px; display:flex; gap:6px; flex-wrap:wrap; color:var(--muted); font-size:12px; }

@media (max-width: 620px){
  .cardHead{
    padding:10px 10px 8px;
    align-items:flex-start;
  }
  .body{
    padding:10px;
  }
  .kpis{
    gap:8px;
  }
  .kpi{
    border-radius:14px;
    padding:8px 9px;
  }
  .kpi .lbl{
    font-size:11px;
  }
  .kpiMeta{
    font-size:10px;
    max-width:58%;
  }
  .kpi .val{
    font-size:20px;
  }
  .kpi .sub2{
    margin-top:5px;
    gap:5px;
  }
  .kpi .sub2 .badge{
    padding:3px 8px;
    font-size:11px;
  }
}

.muted{ color: var(--muted); font-size:12px; }
.row{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.right{ text-align:right; }

.gaugeGrid{
  margin-top:10px;
  display:grid;
  grid-template-columns: 1fr 1fr;
  gap:10px;
}
@media (max-width: 620px){ .gaugeGrid{ grid-template-columns: 1fr; } }

.gauge{
  border:1px solid rgba(15,23,42,.08);
  background: rgba(255,255,255,.85);
  border-radius:16px;
  padding:10px;
}
.gauge .top{
  display:flex; justify-content:space-between; align-items:baseline; gap:10px;
}
.gauge .name{ font-weight:1000; font-size:13px; }
.gauge .num{ font-weight:1000; font-size:12px; color:var(--muted); }
.bar{
  margin-top:8px;
  height:12px;
  border-radius:999px;
  background: rgba(15,23,42,.08);
  overflow:hidden;
}
.bar > i{
  display:block;
  height:100%;
  width:0%;
  border-radius:999px;
  background: linear-gradient(90deg, rgba(124,92,255,.85), rgba(255,95,162,.75));
}
.bar.mint > i{ background: linear-gradient(90deg, rgba(52,211,153,.85), rgba(96,165,250,.70)); }
.bar.amber > i{ background: linear-gradient(90deg, rgba(245,158,11,.85), rgba(255,95,162,.55)); }

.sparkWrap{
  margin-top:10px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  flex-wrap:wrap;
}
.spark{
  border:1px solid rgba(15,23,42,.08);
  background: rgba(255,255,255,.85);
  border-radius:16px;
  padding:10px;
}
.spark small{ color:var(--muted); font-weight:900; }

.recentBlock{
  margin-top:10px;
  border:1px solid rgba(15,23,42,.08);
  background: rgba(255,255,255,.76);
  border-radius:16px;
  overflow:hidden;
}
.recentBlock summary{
  list-style:none;
  cursor:pointer;
  padding:10px 12px;
}
.recentBlock summary::-webkit-details-marker{
  display:none;
}
.recentSummary{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
}
.recentSummary__title{
  font-weight:1000;
  font-size:14px;
}
.recentSummary__meta{
  display:flex;
  align-items:center;
  gap:6px;
  flex-wrap:wrap;
  justify-content:flex-end;
}
.recentSummary__chip{
  display:inline-flex;
  align-items:center;
  min-height:26px;
  padding:0 8px;
  border-radius:999px;
  border:1px solid rgba(15,23,42,.08);
  background:#fff;
  font-size:11px;
  font-weight:900;
}
.recentSummary__chev{
  color: var(--muted);
  font-size:12px;
  font-weight:900;
  transition: transform .2s ease;
}
.recentBlock[open] .recentSummary__chev{
  transform: rotate(180deg);
}
.recentBody{
  padding:0 12px 12px;
  border-top:1px dashed rgba(15,23,42,.08);
}
@media (max-width: 620px){
  .recentBlock summary{
    padding:9px 10px;
  }
  .recentSummary{
    align-items:flex-start;
  }
  .recentSummary__title{
    font-size:13px;
  }
  .recentSummary__meta{
    max-width:58%;
    gap:5px;
  }
  .recentSummary__chip{
    font-size:10px;
    min-height:24px;
    padding:0 7px;
  }
  .recentBody{
    padding:0 10px 10px;
  }
  .spark{
    width:100%;
    min-width:0 !important;
    padding:8px;
  }
}

.formGrid{ display:grid; grid-template-columns:1fr; gap:10px; }
.field{
  border:1px solid rgba(15,23,42,.08);
  background: rgba(255,255,255,.85);
  border-radius:16px;
  padding:10px;
}
.field .lbl{ font-size:12px; color:var(--muted); font-weight:900; margin-bottom:8px; }
.inp{
  width:100%;
  padding: 12px 12px;
  border-radius: 14px;
  border:1px solid rgba(15,23,42,.12);
  background:#fff;
  font-size: 16px;
  font-weight:900;
  color: var(--ink);
}
textarea.inp{ min-height: 88px; resize: vertical; font-weight:800; }
.transportBlocks{ display:grid; grid-template-columns:1fr; gap:12px; }
.pickupTargetSwitch{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:10px;
  margin-bottom:12px;
}
.pickupTargetBtn{
  appearance:none;
  border:1px solid rgba(15,23,42,.10);
  background:rgba(255,255,255,.72);
  color:var(--ink);
  min-height:46px;
  border-radius:14px;
  padding:10px 12px;
  font-weight:1000;
  box-shadow:var(--shadow2);
  cursor:pointer;
}
.pickupTargetBtn.is-active{
  border-color:rgba(124,92,255,.28);
  background:linear-gradient(135deg, rgba(124,92,255,.16), rgba(255,95,162,.10));
}
.transportBlock{
  border:1px solid rgba(15,23,42,.08);
  background: rgba(255,255,255,.72);
  border-radius:16px;
  padding:12px;
}
.transportBlockHead{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  margin-bottom:8px;
}
.transportBlockTitle{
  font-size:14px;
  font-weight:1000;
  color:var(--ink);
}
.transportGrid{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:10px;
}
.transportGrid .fieldFull{ grid-column:1 / -1; }
.transportActions{
  grid-column:1 / -1;
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:10px;
}
.mapBtn,
.geoBtn{
  width:100%;
  justify-content:center;
}
@media (min-width: 861px){
  .transportBlocks{
    grid-template-columns:1fr 1fr;
    align-items:start;
  }
  .transportGrid{
    grid-template-columns:repeat(2, minmax(0, 1fr));
  }
}
@media (max-width: 620px){
  .transportGrid{
    grid-template-columns:1fr;
  }
  .transportActions{
    grid-template-columns:1fr;
  }
  .pickupTargetSwitch{
    grid-template-columns:1fr;
  }
}
</style>

<div class="page">
  <div class="wrap">

    <div class="hero">
      <div class="heroTop">
        <div>
          <div class="h1">👤 プロフィール</div>
          <div class="sub">店舗：<b><?= h($storeName) ?></b> / <?= h($displayName) ?></div>
        </div>
        <div class="row">
          <span class="badge p">雇用 <?= h($employmentTypeLabel) ?></span>
          <?php if ($profile['shop_tag'] !== ''): ?>
            <span class="badge a">店番 <?= h($profile['shop_tag']) ?></span>
          <?php endif; ?>
          <a class="btn ghost" href="/wbss/public/cast_week.php">📅 出勤（週）</a>
        </div>
      </div>

      <?php if ($msg): ?><div class="notice ok"><?= h($msg) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="notice ng"><?= h($err) ?></div><?php endif; ?>

      <?php if ($isActiveUser !== 1): ?>
        <div class="notice ng">非在籍のため編集できません</div>
      <?php endif; ?>
    </div>

    <div class="grid">

      <!-- ポイント -->
      <div class="card">
        <div class="cardHead">
          <div class="cardTitle">⭐ ポイント</div>
          <div class="muted right">集計は営業日(business_date)基準</div>
        </div>
        <div class="body">

          <div class="kpis">
            <div class="kpi">
              <div class="kpiTop">
                <div class="lbl">昨日</div>
                <div class="kpiMeta"><?= h($yday) ?></div>
              </div>
              <div class="valRow">
                <div class="val"><?= number_format($ySum['total'], 2) ?></div>
              </div>
              <div class="sub2">
                <span class="badge m">同伴 <?= number_format($ySum['douhan'], 2) ?></span>
                <span class="badge p">指名 <?= number_format($ySum['shimei'], 2) ?></span>
              </div>
            </div>

            <div class="kpi">
              <div class="kpiTop">
                <div class="lbl">今期 <?= h($pLabel) ?></div>
                <div class="kpiMeta"><?= h($pStart) ?>〜<?= h($pEnd) ?></div>
              </div>
              <div class="valRow">
                <div class="val"><?= number_format($hSum['total'], 2) ?></div>
              </div>
              <div class="sub2">
                <span class="badge m">同伴 <?= number_format($hSum['douhan'], 2) ?></span>
                <span class="badge p">指名 <?= number_format($hSum['shimei'], 2) ?></span>
                <span class="badge a">指名比率 <?= number_format($ratio, 1) ?>%</span>
              </div>
            </div>
          </div>

          <!-- 直近7日 -->
          <details class="recentBlock">
            <summary>
              <div class="recentSummary">
                <div class="recentSummary__title">📈 直近7日</div>
                <div class="recentSummary__meta">
                  <span class="recentSummary__chip">合計 <?= number_format(array_sum($vals7), 2) ?></span>
                  <span class="recentSummary__chip">平均 <?= number_format(array_sum($vals7)/max(1,count($vals7)), 2) ?></span>
                  <span class="recentSummary__chev">▾</span>
                </div>
              </div>
            </summary>
            <div class="recentBody">
              <div class="sparkWrap">
                <div class="spark">
                  <div class="row" style="justify-content:space-between">
                    <div style="font-weight:1000;">📈 直近7日（合計）</div>
                    <small><?= h($last7[0]) ?>〜<?= h($last7[count($last7)-1]) ?></small>
                  </div>
                  <div style="margin-top:8px;"><?= $spark ?></div>
                  <div class="muted" style="margin-top:8px;">
                    7日合計：<?= number_format(array_sum($vals7), 2) ?> / 平均：<?= number_format(array_sum($vals7)/max(1,count($vals7)), 2) ?>
                  </div>
                </div>

                <div class="spark" style="min-width:260px;">
                  <div style="font-weight:1000;">🗓️ 日別</div>
                  <div class="muted" style="margin-top:8px; line-height:1.9;">
                    <?php foreach ($last7 as $i=>$d): ?>
                      <div style="display:flex;justify-content:space-between;gap:10px;">
                        <span><?= h(substr($d,5)) ?></span>
                        <b><?= number_format((float)$vals7[$i], 2) ?></b>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            </div>
          </details>

          <div class="muted" style="margin-top:10px;">
            ※ 目標はメモに <code>#goal_total=30 #goal_shimei=20 #goal_douhan=10</code> のように書くと上書きできます。
          </div>

        </div>
      </div>

	      <!-- 設定 -->
	      <div class="card">
	        <div class="cardHead">
	          <div class="cardTitle">⚙️ 出勤の基本設定</div>
	          <div class="muted">週入力の初期値に使う</div>
        </div>
        <div class="body">
	          <form method="post" class="formGrid">
	            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="pickup_zip" value="<?= h((string)$transportProfile['pickup_zip']) ?>">
              <input type="hidden" name="pickup_prefecture" value="<?= h((string)$transportProfile['pickup_prefecture']) ?>">
              <input type="hidden" name="pickup_city" value="<?= h((string)$transportProfile['pickup_city']) ?>">
              <input type="hidden" name="pickup_address2" value="<?= h((string)$transportProfile['pickup_address2']) ?>">
              <input type="hidden" name="pickup_enabled" value="<?= h((string)$transportProfile['pickup_enabled']) ?>">
              <input type="hidden" name="privacy_level" value="<?= h((string)$transportProfile['privacy_level']) ?>">
              <input type="hidden" name="pickup_target" id="pickup_target" value="<?= h((string)($transportProfile['pickup_target'] ?? 'primary')) ?>">
              <?php if ($transportHasSecondary): ?>
                <input type="hidden" name="pickup_sub_zip" value="<?= h((string)$transportProfile['pickup_sub_zip']) ?>">
                <input type="hidden" name="pickup_sub_prefecture" value="<?= h((string)$transportProfile['pickup_sub_prefecture']) ?>">
                <input type="hidden" name="pickup_sub_city" value="<?= h((string)$transportProfile['pickup_sub_city']) ?>">
                <input type="hidden" name="pickup_sub_address2" value="<?= h((string)$transportProfile['pickup_sub_address2']) ?>">
              <?php endif; ?>

	            <div class="field">
              <div class="lbl">基本開始時刻</div>
              <input class="inp"
                     type="time"
                     name="default_start_time"
                     step="60"
                     value="<?= h($profile['default_start_time'] ?: '20:00') ?>"
                     <?= ($isActiveUser !== 1) ? 'disabled' : '' ?>>
              <div class="muted" style="margin-top:8px;">
                cast_week.php の「基本開始で埋める」に使います
              </div>
            </div>

	            <div class="field">
	              <div class="lbl">メモ（任意）</div>
	              <textarea class="inp"
                        name="note"
                        maxlength="255"
                        placeholder="例）基本は20:30〜 / #goal_total=30 #goal_shimei=20 #goal_douhan=10"
                        <?= ($isActiveUser !== 1) ? 'disabled' : '' ?>><?= h($profile['note']) ?></textarea>
              <div class="muted" style="margin-top:8px;">
	                目標もここに入れられます（管理側が設定してもOK）
	              </div>
	            </div>

              <div class="field">
                <div class="lbl">送迎場所</div>
                <?php if ($transportHasSecondary && $transportHasPickupTarget): ?>
                  <div class="pickupTargetSwitch">
                    <button
                      type="button"
                      class="pickupTargetBtn <?= ((string)($transportProfile['pickup_target'] ?? 'primary') === 'primary') ? 'is-active' : '' ?>"
                      data-pickup-target-value="primary"
                      <?= ($isActiveUser !== 1) ? 'disabled' : '' ?>
                    >今日は基本に迎え</button>
                    <button
                      type="button"
                      class="pickupTargetBtn <?= ((string)($transportProfile['pickup_target'] ?? 'primary') === 'secondary') ? 'is-active' : '' ?>"
                      data-pickup-target-value="secondary"
                      <?= ($isActiveUser !== 1) ? 'disabled' : '' ?>
                    >今日はサブに迎え</button>
                    <button
                      type="button"
                      class="pickupTargetBtn <?= ((string)($transportProfile['pickup_target'] ?? 'primary') === 'self') ? 'is-active' : '' ?>"
                      data-pickup-target-value="self"
                      <?= ($isActiveUser !== 1) ? 'disabled' : '' ?>
                    >今日は自走</button>
                  </div>
                  <div class="muted" style="margin-top:8px;">送迎ルート最適化では、この選択を優先して迎車地点を使います。</div>
                <?php endif; ?>
                <div class="transportBlocks">
                  <div class="transportBlock">
                    <div class="transportBlockHead">
                      <div class="transportBlockTitle">基本</div>
                      <span class="badge m">一番よく使う場所</span>
                    </div>
                    <div class="transportGrid">
                      <div class="field fieldFull">
                        <div class="lbl">住所</div>
                        <input class="inp"
                               name="pickup_address1"
                               maxlength="255"
                               placeholder="例）岡山市北区..."
                               value="<?= h((string)$transportProfile['pickup_address1']) ?>"
                               <?= ($isActiveUser !== 1) ? 'disabled' : '' ?>>
                        <div class="muted" style="margin-top:8px;">住所を入れて座標が空なら、保存時に座標を自動取得します。</div>
                      </div>
                      <div class="field">
                        <div class="lbl">建物名・部屋</div>
                        <input class="inp"
                               name="pickup_building"
                               maxlength="255"
                               placeholder="例）〇〇マンション 101"
                               value="<?= h((string)$transportProfile['pickup_building']) ?>"
                               <?= ($isActiveUser !== 1) ? 'disabled' : '' ?>>
                      </div>
                      <div class="field">
                        <div class="lbl">メモ</div>
                        <input class="inp"
                               name="pickup_note"
                               maxlength="255"
                               placeholder="例）裏口で待機"
                               value="<?= h((string)$transportProfile['pickup_note']) ?>"
                               <?= ($isActiveUser !== 1) ? 'disabled' : '' ?>>
                      </div>
                      <div class="field">
                        <div class="lbl">緯度</div>
                        <input class="inp"
                               id="pickup_lat"
                               name="pickup_lat"
                               inputmode="decimal"
                               placeholder="35.0000000"
                               value="<?= h((string)$transportProfile['pickup_lat']) ?>"
                               <?= ($isActiveUser !== 1) ? 'disabled' : '' ?>>
                      </div>
                      <div class="field">
                        <div class="lbl">経度</div>
                        <input class="inp"
                               id="pickup_lng"
                               name="pickup_lng"
                               inputmode="decimal"
                               placeholder="139.0000000"
                               value="<?= h((string)$transportProfile['pickup_lng']) ?>"
                               <?= ($isActiveUser !== 1) ? 'disabled' : '' ?>>
                      </div>
                        <div class="transportActions">
	                        <button class="btn ghost mapBtn"
	                                type="button"
                                  data-map-lat="pickup_lat"
                                  data-map-lng="pickup_lng">🗺 Google Mapsで開く</button>
	                        <button class="btn ghost geoBtn"
	                                type="button"
	                                data-geo-lat="pickup_lat"
	                                data-geo-lng="pickup_lng"
	                                <?= ($isActiveUser !== 1) ? 'disabled' : '' ?>>📍 現在地を取得</button>
                        </div>
	                    </div>
	                  </div>

                  <div class="transportBlock">
                    <div class="transportBlockHead">
                      <div class="transportBlockTitle">サブ</div>
                      <span class="badge a">予備の場所</span>
                    </div>
                    <?php if ($transportHasSecondary): ?>
                      <div class="transportGrid">
                        <div class="field fieldFull">
                          <div class="lbl">住所</div>
                          <input class="inp"
                                 name="pickup_sub_address1"
                                 maxlength="255"
                                 placeholder="例）実家・待ち合わせ場所など"
                                 value="<?= h((string)$transportProfile['pickup_sub_address1']) ?>"
                                 <?= ($isActiveUser !== 1) ? 'disabled' : '' ?>>
                        </div>
                        <div class="field">
                          <div class="lbl">建物名・部屋</div>
                          <input class="inp"
                                 name="pickup_sub_building"
                                 maxlength="255"
                                 value="<?= h((string)$transportProfile['pickup_sub_building']) ?>"
                                 <?= ($isActiveUser !== 1) ? 'disabled' : '' ?>>
                        </div>
                        <div class="field">
                          <div class="lbl">メモ</div>
                          <input class="inp"
                                 name="pickup_sub_note"
                                 maxlength="255"
                                 placeholder="例）駅前ロータリー"
                                 value="<?= h((string)$transportProfile['pickup_sub_note']) ?>"
                                 <?= ($isActiveUser !== 1) ? 'disabled' : '' ?>>
                        </div>
                        <div class="field">
                          <div class="lbl">緯度</div>
                          <input class="inp"
                                 id="pickup_sub_lat"
                                 name="pickup_sub_lat"
                                 inputmode="decimal"
                                 placeholder="35.0000000"
                                 value="<?= h((string)$transportProfile['pickup_sub_lat']) ?>"
                                 <?= ($isActiveUser !== 1) ? 'disabled' : '' ?>>
                        </div>
                        <div class="field">
                          <div class="lbl">経度</div>
                          <input class="inp"
                                 id="pickup_sub_lng"
                                 name="pickup_sub_lng"
                                 inputmode="decimal"
                                 placeholder="139.0000000"
                                 value="<?= h((string)$transportProfile['pickup_sub_lng']) ?>"
                                 <?= ($isActiveUser !== 1) ? 'disabled' : '' ?>>
                        </div>
                          <div class="transportActions">
	                          <button class="btn ghost mapBtn"
	                                  type="button"
                                    data-map-lat="pickup_sub_lat"
                                    data-map-lng="pickup_sub_lng">🗺 Google Mapsで開く</button>
	                          <button class="btn ghost geoBtn"
	                                  type="button"
	                                  data-geo-lat="pickup_sub_lat"
	                                  data-geo-lng="pickup_sub_lng"
	                                  <?= ($isActiveUser !== 1) ? 'disabled' : '' ?>>📍 現在地を取得</button>
                          </div>
	                      </div>
	                    <?php else: ?>
                      <div class="muted">サブ地点はDB更新後に使えます。先に基本だけ登録できます。</div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

	            <button class="btn primary" type="submit" <?= ($isActiveUser !== 1) ? 'disabled' : '' ?>>💾 保存</button>

	            <div class="muted">
	              雇用形態・店番は管理側で設定する想定です。送迎場所は本人が更新できます。
	            </div>
	          </form>
	        </div>
	      </div>

    </div>

  </div>
</div>

<script>
document.querySelectorAll('[data-map-lat][data-map-lng]').forEach((btn) => {
  btn.addEventListener('click', () => {
    const latId = btn.getAttribute('data-map-lat');
    const lngId = btn.getAttribute('data-map-lng');
    const latInput = latId ? document.getElementById(latId) : null;
    const lngInput = lngId ? document.getElementById(lngId) : null;
    const lat = latInput ? String(latInput.value || '').trim() : '';
    const lng = lngInput ? String(lngInput.value || '').trim() : '';
    if (!lat || !lng) {
      alert('緯度と経度を入力してから開いてください');
      return;
    }
    window.open('https://www.google.com/maps?q=' + encodeURIComponent(lat + ',' + lng), '_blank', 'noopener');
  });
});

document.querySelectorAll('[data-geo-lat][data-geo-lng]').forEach((btn) => {
  btn.addEventListener('click', () => {
    const latId = btn.getAttribute('data-geo-lat');
    const lngId = btn.getAttribute('data-geo-lng');
    const latInput = latId ? document.getElementById(latId) : null;
    const lngInput = lngId ? document.getElementById(lngId) : null;
    if (!latInput || !lngInput || !navigator.geolocation) {
      alert('現在地取得に対応していません');
      return;
    }

    const oldText = btn.textContent;
    btn.disabled = true;
    btn.textContent = '取得中...';

    navigator.geolocation.getCurrentPosition((pos) => {
      latInput.value = String(pos.coords.latitude.toFixed(7));
      lngInput.value = String(pos.coords.longitude.toFixed(7));
      btn.textContent = '取得しました';
      window.setTimeout(() => {
        btn.disabled = false;
        btn.textContent = oldText;
      }, 1200);
    }, (err) => {
      btn.disabled = false;
      btn.textContent = oldText;
      alert('現在地を取得できませんでした: ' + (err && err.message ? err.message : '位置情報を許可してください'));
    }, {
      enableHighAccuracy: true,
      timeout: 10000,
      maximumAge: 0,
    });
  });
});

const pickupTargetInput = document.getElementById('pickup_target');
document.querySelectorAll('[data-pickup-target-value]').forEach((btn) => {
  btn.addEventListener('click', () => {
    const value = btn.getAttribute('data-pickup-target-value') || 'primary';
    if (pickupTargetInput) pickupTargetInput.value = value;
    document.querySelectorAll('[data-pickup-target-value]').forEach((el) => {
      el.classList.toggle('is-active', el === btn);
    });
  });
});
</script>

<?php render_page_end(); ?>
