<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/layout.php';

require_login();
if (function_exists('require_role')) {
  require_role(['cast','staff','manager','admin','super_user']);
}

$pdo = db();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* =========================
   Helpers (NO redeclare)
========================= */
if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

function ymd_or_today(?string $s): string {
  $s = (string)$s;
  $s = substr($s, 0, 10);
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
  return (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
}

function current_user_id_safe(): int {
  if (function_exists('current_user_id')) return (int)current_user_id();
  return (int)($_SESSION['user_id'] ?? 0);
}

function read_total_yen_from_snapshot(?string $json): int {
  $json = (string)$json;
  if ($json === '') return 0;
  $j = json_decode($json, true);
  if (!is_array($j)) return 0;

  if (isset($j['bill']['total']))   return (int)$j['bill']['total'];
  if (isset($j['totals']['total'])) return (int)$j['totals']['total'];
  if (isset($j['total']))           return (int)$j['total'];
  return 0;
}

function ticket_status_view(string $status): array {
  $status = strtolower(trim($status));
  switch ($status) {
    case 'paid':   return ['label' => '入金完了', 'class' => 'bPaid'];
    case 'locked': return ['label' => '精算待ち', 'class' => 'bLocked'];
    case 'open':
    default:       return ['label' => '未精算',   'class' => 'bOpen'];
  }
}

function time_hm(?string $dt): string {
  $dt = trim((string)$dt);
  if ($dt === '' || $dt === '—') return '—';

  // 例: "2026-02-25 15:03:34" / "2026-02-25 15:03"
  if (preg_match('/\b(\d{2}):(\d{2})(?::\d{2})?\b/', $dt, $m)) {
    return $m[1].':'.$m[2];
  }

  // 念のため DateTime でも試す
  try {
    $d = new DateTime($dt, new DateTimeZone('Asia/Tokyo'));
    return $d->format('H:i');
  } catch (Throwable $e) {
    return '—';
  }
}
/* =========================
   CSRF fallback
========================= */
if (!function_exists('att_csrf_token')) {
  function att_csrf_token(): string {
    if (function_exists('csrf_token')) return (string)csrf_token();
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    return (string)$_SESSION['csrf_token'];
  }
}
if (!function_exists('att_csrf_verify')) {
  function att_csrf_verify(?string $token): bool {
    if (function_exists('csrf_verify')) {
      try {
        $r = csrf_verify($token);
        return ($r === null) ? true : (bool)$r;
      } catch (Throwable $e) {}
    }
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (!$token) return false;
    return hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$token);
  }
}

/* =========================
   UI theme fallback (layout無い環境でも保存する)
========================= */
function ui_theme_allow(): array {
  return ['light','dark','soft','high_contrast','store_color'];
}

function current_ui_theme_fallback(): string {
  if (function_exists('current_ui_theme')) {
    $t = (string)current_ui_theme();
    if ($t !== '') return $t;
  }
  $v = (string)($_SESSION['ui_theme'] ?? '');
  if ($v === '' && isset($_COOKIE['ui_theme'])) $v = (string)$_COOKIE['ui_theme'];
  if ($v === '') $v = 'dark';
  if (!in_array($v, ui_theme_allow(), true)) $v = 'dark';
  return $v;
}

function set_ui_theme_fallback(string $theme): void {
  if (!in_array($theme, ui_theme_allow(), true)) $theme = 'dark';
  $_SESSION['ui_theme'] = $theme;
  setcookie('ui_theme', $theme, [
    'expires'  => time() + 60 * 60 * 24 * 30,
    'path'     => '/',
    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => false,
    'samesite' => 'Lax',
  ]);
}

/* =========================
   Params（先に読む：POSTリダイレクトにも使う）
========================= */
$store_id = (int)($_GET['store_id'] ?? 0);
if ($store_id <= 0) $store_id = (int)($_SESSION['store_id'] ?? 1);
if ($store_id <= 0) $store_id = 1;

$business_date = ymd_or_today($_GET['business_date'] ?? ($_GET['date'] ?? null));

$qsBase = 'store_id=' . urlencode((string)$store_id);

/* =========================
   POST: theme change（layoutは触らない）
   - refererに戻すからクエリ保持される
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'set_theme') {
  if (!att_csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(400);
    echo 'Bad Request (CSRF)';
    exit;
  }

  $theme = (string)($_POST['ui_theme'] ?? 'dark');

  if (function_exists('set_ui_theme')) {
    set_ui_theme($theme);
  } else {
    set_ui_theme_fallback($theme);
  }

  // JSONなら即返す（AJAX用）
  $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
  if (stripos($accept, 'application/json') !== false) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true,'theme'=>$theme], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // PRG: refererへ戻す（クエリもそのまま）
  $back = (string)($_SERVER['HTTP_REFERER'] ?? '');
  if ($back === '') {
    $back = '/seika-app/public/cashier/index.php?'
          . 'store_id=' . urlencode((string)$store_id)
          . '&business_date=' . urlencode((string)$business_date);
  }
  header('Location: ' . $back);
  exit;
}

/* =========================
   New ticket (GET action=new)
========================= */
if (($_GET['action'] ?? '') === 'new') {
  try {
    $actorId = current_user_id_safe();
    $now = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
    $barcode = uniqid('T' . date('ymd') . '-');

    $pdo->beginTransaction();

    $st = $pdo->prepare("
      INSERT INTO tickets
      (store_id, business_date, status,
       barcode_value,
       opened_at,
       created_by,
       created_at,
       updated_at)
      VALUES (?, ?, 'open',
              ?,
              ?, ?, ?, ?)
    ");
    $st->execute([$store_id, $business_date, $barcode, $now, $actorId, $now, $now]);

    $ticketId = (int)$pdo->lastInsertId();
    $pdo->commit();

    header('Location: /seika-app/public/cashier/cashier.php?store_id=' . $store_id . '&ticket_id=' . $ticketId . '&business_date=' . urlencode($business_date));
    exit;

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo "ticket create failed: " . h($e->getMessage());
    exit;
  }
}

/* =========================
   Store name (optional)
========================= */
$storeName = '店舗';
try {
  if ($pdo->query("SHOW TABLES LIKE 'stores'")->fetchColumn()) {
    $st = $pdo->prepare('SELECT name FROM stores WHERE id=?');
    $st->execute([$store_id]);
    $storeName = (string)($st->fetchColumn() ?: $storeName);
  }
} catch (Throwable $e) {}

/* =========================
   Attendance summary
========================= */
$inCount = $outCount = $lateCount = 0;
$staffCount = 0;

try {
  if ($pdo->query("SHOW TABLES LIKE 'attendances'")->fetchColumn()) {
    $st = $pdo->prepare("
      SELECT
        SUM(CASE WHEN status IN ('working','finished') THEN 1 ELSE 0 END) AS in_cnt,
        SUM(CASE WHEN status = 'finished' THEN 1 ELSE 0 END) AS out_cnt,
        SUM(CASE WHEN is_late = 1 AND status IN ('working','finished') THEN 1 ELSE 0 END) AS late_cnt
      FROM attendances
      WHERE store_id=? AND business_date=?
    ");
    $st->execute([$store_id, $business_date]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $inCount   = (int)($row['in_cnt'] ?? 0);
    $outCount  = (int)($row['out_cnt'] ?? 0);
    $lateCount = (int)($row['late_cnt'] ?? 0);
  }

  // 在籍（viewがあるならそれ）
  $hasView = false;
  try {
    $st = $pdo->query("SHOW FULL TABLES LIKE 'v_store_casts_active'");
    $r = $st ? $st->fetch(PDO::FETCH_NUM) : null;
    if ($r && isset($r[1]) && strtolower((string)$r[1]) === 'view') $hasView = true;
  } catch (Throwable $e) {}

  if ($hasView) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM v_store_casts_active WHERE store_id=?");
    $st->execute([$store_id]);
    $staffCount = (int)($st->fetchColumn() ?: 0);
  }
} catch (Throwable $e) {
  // ignore
}

/* =========================
   Tickets list + paid totals
========================= */
$st = $pdo->prepare("
  SELECT
    t.id,
    t.store_id,
    t.business_date,
    t.status,
    t.opened_at,
    t.locked_at,
    t.totals_snapshot,
    COALESCE(p.paid_total, 0) AS paid_total
  FROM tickets t
  LEFT JOIN (
    SELECT ticket_id, SUM(amount) AS paid_total
    FROM ticket_payments
    WHERE status='captured' AND is_void=0
    GROUP BY ticket_id
  ) p ON p.ticket_id = t.id
  WHERE t.store_id=? AND t.business_date=?
  ORDER BY t.id DESC
");
$st->execute([$store_id, $business_date]);
$tickets = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* =========================
   Header (layout.php untouched)
   - backは空にして、自分でボタン表示
========================= */
$headerRight = '
  <a class="btn btnPrimary"
     href="/seika-app/public/cashier/index.php?'.$qsBase.'&action=new&business_date='.h($business_date).'">＋ 新規伝票</a>
  <a class="btn"
     href="/seika-app/public/cashier/index.php?'.$qsBase.'&business_date='.h($business_date).'">更新</a>
';

render_header('会計一覧（' . $storeName . '）', [
  'back_href'  => '',
  'right_html' => $headerRight,
]);

$theme = current_ui_theme_fallback();
?>
<script>
(function(){
  var t = <?= json_encode($theme, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  document.documentElement.setAttribute('data-theme', t);
  if (document.body) document.body.setAttribute('data-theme', t);
})();
</script>

<style>
:root, html, body{
  --bg:#0b1020;
  --card:#121a33;
  --card2:#0f1730;
  --txt:#e8ecff;
  --muted:#aab3d6;
  --line:rgba(255,255,255,.12);
  --shadow: 0 12px 30px rgba(0,0,0,.18);
  --radius:16px;
  --accent:#2563eb;
}

/* ★ここがポイント：htmlでもbodyでもOK */
html[data-theme="light"], body[data-theme="light"]{
  --bg:#f6f7fb;
  --card:#ffffff;
  --card2:#f1f3f8;
  --txt:#0f172a;
  --muted:#516076;
  --line:rgba(15,23,42,.12);
  --shadow: 0 12px 30px rgba(16,24,40,.08);
  --accent:#2563eb;
}
html[data-theme="soft"], body[data-theme="soft"]{
  --bg:#0b1020;
  --card:#111a33;
  --card2:#0f1730;
  --txt:#e9edff;
  --muted:#aab3d6;
  --line:rgba(255,255,255,.10);
  --shadow: 0 12px 30px rgba(0,0,0,.18);
  --accent:#8b5cf6;
}
html[data-theme="high_contrast"], body[data-theme="high_contrast"]{
  --bg:#000;
  --card:#0b0b0b;
  --card2:#111;
  --txt:#fff;
  --muted:#e5e7eb;
  --line:rgba(255,255,255,.35);
  --shadow: 0 12px 30px rgba(0,0,0,.40);
  --accent:#22c55e;
}
html[data-theme="store_color"], body[data-theme="store_color"]{
  --bg:#0b1020;
  --card:#121a33;
  --card2:#0f1730;
  --txt:#e8ecff;
  --muted:#aab3d6;
  --line:rgba(255,255,255,.12);
  --shadow: 0 12px 30px rgba(0,0,0,.18);
  --accent:#06b6d4;
}

html, body{ background:var(--bg) !important; color:var(--txt) !important; }

/* ★ここが重要：layout側wrapperの固定背景を潰す */
html, body{
  background: var(--bg) !important;
  color: var(--txt) !important;
}
main, .app, .app-main, .app-body, .container, .content{
  background: var(--bg) !important;
  color: var(--txt) !important;
}

/* page */
.wrap{ max-width:1280px; margin:0 auto; padding:14px; }
a{ color:inherit; text-decoration:none; }

/* left-top */
.pageTop{
  display:flex; align-items:center; justify-content:space-between;
  gap:10px; flex-wrap:wrap;
  margin: 10px 0 12px 0;
}
.pageTop .left{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.pageTop .meta{ color:var(--muted); font-size:12px; }

/* buttons */
.btn{
  display:inline-flex; align-items:center; justify-content:center;
  padding:10px 14px; border-radius:12px;
  border:1px solid var(--line);
  background: var(--card);
  color: var(--txt);
  text-decoration:none; font-weight:800;
}
.btnPrimary{ background:var(--accent); color:#fff; border-color:var(--accent); }
.btn:active{ transform:translateY(1px); }
.btn:hover{ filter: brightness(1.03); }

.gridMenu{
  display:grid;
  grid-template-columns: repeat(2, 1fr);
  gap:14px;
  margin: 6px 0 14px 0;
}
@media (max-width: 860px){ .gridMenu{ grid-template-columns: 1fr; } }

.cardMenu{
  background:var(--card);
  border:1px solid var(--line);
  border-radius:var(--radius);
  box-shadow:var(--shadow);
  padding:14px;
}
.cardMenu h2{ margin:0 0 6px 0; font-size:16px; }
.cardMenu .desc{ margin:0 0 12px 0; color:var(--muted); font-size:13px; line-height:1.5; }
.btns{ display:flex; flex-wrap:wrap; gap:10px; }
.tag{
  font-size:12px; color:var(--muted);
  border:1px solid var(--line);
  padding:3px 8px; border-radius:999px;
  background:rgba(127,127,127,.08);
}

.topGrid{
  display:grid;
  grid-template-columns: 1.2fr .8fr;
  gap:14px;
  align-items:stretch;
}
@media (max-width: 820px){ .topGrid{ grid-template-columns: 1fr; } }

.card{
  background:var(--card);
  border:1px solid var(--line);
  border-radius:var(--radius);
  box-shadow:var(--shadow);
  padding:14px;
}

.row{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.rowBetween{ display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; }

.title{ font-weight:900; font-size:18px; }
.muted{ color:var(--muted); font-size:13px; }

.pill{
  display:inline-flex; align-items:center; gap:6px;
  padding:3px 10px; border-radius:999px; font-size:12px;
  border:1px solid var(--line); background:var(--card); color:var(--muted);
}
.pill .dot{ width:8px; height:8px; border-radius:50%; background:#12B76A; display:inline-block; }

.kpiGrid{
  display:grid;
  grid-template-columns: repeat(4, 1fr);
  gap:10px;
  margin-top:10px;
}
@media (max-width: 820px){ .kpiGrid{ grid-template-columns: repeat(2, 1fr); } }

.kpi{
  border:1px solid var(--line);
  border-radius:14px;
  padding:10px;
  background:var(--card);
}
.kpi .label{ font-size:12px; color:var(--muted); }
.kpi .val{ font-size:22px; font-weight:900; margin-top:2px; }

.tableWrap{
  overflow:auto;
  border-radius:var(--radius);
  border:1px solid var(--line);
  background:var(--card);
  box-shadow:var(--shadow);
  margin-top:14px;
}
table{ width:100%; border-collapse:separate; border-spacing:0; min-width:900px; }
thead th{
  position:sticky; top:0;
  background:var(--card);
  border-bottom:1px solid var(--line);
  padding:12px 10px;
  text-align:left;
  font-size:12px;
  color:var(--muted);
  z-index:1;
  white-space:nowrap;
}
tbody td{
  border-bottom:1px solid var(--line);
  padding:12px 10px;
  vertical-align:middle;
  white-space:nowrap;
}
tbody tr:hover{ background: rgba(127,127,127,.10); }

.num{ text-align:right; font-variant-numeric: tabular-nums; }
.center{ text-align:center; }

.badge{
  display:inline-flex; align-items:center; gap:6px;
  border-radius:999px; padding:6px 12px;
  font-size:12px; font-weight:900;
  border: 1px solid var(--line);
  background: var(--card);
}
.badge::before{
  content:"";
  width:8px;height:8px;border-radius:50%;
  display:inline-block;
  background: currentColor;
  opacity:.9;
}
.bOpen{ color:#2563eb; background:rgba(37,99,235,.10); border-color:rgba(37,99,235,.25); }
.bLocked{ color:#f59e0b; background:rgba(245,158,11,.14); border-color:rgba(245,158,11,.28); }
.bPaid{ color:#12b76a; background:rgba(18,183,106,.12); border-color:rgba(18,183,106,.28); }

.smallBtns{ display:flex; gap:8px; justify-content:center; flex-wrap:wrap; }
/* ===== iPad用 ドロワー拡張 ===== */
@media (min-width: 768px) and (max-width: 1366px){
  #payDrawer{
    width: 65vw !important;     /* ←ここで広さ調整 */
    max-width: 900px;           /* 大きくなりすぎ防止 */
  }

  #payDrawerBackdrop{
    background: rgba(0,0,0,.45); /* 背景を少し暗く */
  }
}
#payDrawer{
  transition: transform .25s cubic-bezier(.2,.8,.2,1);
}
</style>

<div class="wrap">

  <div class="pageTop">
    <div class="left">
      <a class="btn" href="/seika-app/public/dashboard.php">← 戻る</a>
      <div class="meta">営業日 <?= h($business_date) ?> / 店舗：<?= h($storeName) ?>（#<?= (int)$store_id ?>）</div>
    </div>
  </div>

  <div class="gridMenu">
    <section class="cardMenu">
      <h2>📄 現場メニュー</h2>
      <p class="desc">
        現場はここだけ見ればOK。<br>
        「伝票を作る → 追加注文 → 会計」へつながる入口。
      </p>
      <div class="btns">
        <a class="btn" href="/seika-app/public/cashier/index.php?<?= $qsBase ?>&action=new&business_date=<?= h($business_date) ?>"><b>➕ 新規伝票</b><span class="tag">現場</span></a>
        <a class="btn" href="/seika-app/public/cashier/index.php?<?= $qsBase ?>&business_date=<?= h($business_date) ?>"><b>📚 本日の一覧</b><span class="tag">現場</span></a>
        <a class="btn" href="/seika-app/public/cashier/search.php?<?= $qsBase ?>"><b>🔎 検索</b><span class="tag">現場</span></a>
      </div>
      <div class="row" style="margin-top:10px">
        <span class="pill">✅ 迷ったら「本日の一覧」</span>
        <span class="pill">🧾 会計は伝票の中</span>
        <span class="pill">🔒 store_id 分離</span>
      </div>
    </section>

    <section class="cardMenu">
      <h2>🧭 管理メニュー</h2>
      <p class="desc">店長/管理者向け。運用の微調整や監査に使う。</p>
      <div class="btns">
        <a class="btn" href="/seika-app/public/cashier/reports/index.php?<?= $qsBase ?>"><b>📈 集計</b><span class="tag">管理</span></a>
        <a class="btn" href="/seika-app/public/cashier/audit/index.php?<?= $qsBase ?>"><b>🕵️ 操作ログ</b><span class="tag">管理</span></a>
        <a class="btn" href="/seika-app/public/cashier/settings/index.php?<?= $qsBase ?>"><b>⚙️ 設定</b><span class="tag">管理</span></a>
      </div>
      <p class="desc" style="margin-top:12px;"><span class="tag">※ 権限で見える/見えないを後で確定</span></p>
    </section>
  </div>

  <div class="topGrid">
    <div class="card">
      <div class="rowBetween">
        <div>
          <div class="title">営業日 <?= h($business_date) ?></div>
          <div class="muted">店舗：<?= h($storeName) ?>（#<?= (int)$store_id ?>）</div>
        </div>
      </div>

      <div class="kpiGrid">
        <div class="kpi"><div class="label">出勤</div><div class="val"><?= (int)$inCount ?>人</div></div>
        <div class="kpi"><div class="label">退勤</div><div class="val"><?= (int)$outCount ?>人</div></div>
        <div class="kpi"><div class="label">遅刻</div><div class="val"><?= (int)$lateCount ?>人</div></div>
        <div class="kpi"><div class="label">在籍（キャスト）</div><div class="val"><?= (int)$staffCount ?>人</div></div>
      </div>

      <div class="row" style="margin-top:12px">
        <span class="pill"><span class="dot"></span>状態：表示中</span>
        <span class="pill">入金：captured のみ集計</span>
      </div>
    </div>

    <div class="card">
      <div class="rowBetween">
        <div class="title">日次サマリ</div>
        <div class="muted">（概算）</div>
      </div>

      <?php
        $sum_open = $sum_locked = $sum_paid = 0;
        $cnt_open = $cnt_locked = $cnt_paid = 0;
        $sum_paid_total = 0;

        foreach ($tickets as $t) {
          $status = (string)($t['status'] ?? 'open');
          $totalYen = read_total_yen_from_snapshot((string)($t['totals_snapshot'] ?? ''));
          $paidYen  = (int)($t['paid_total'] ?? 0);

          if ($status === 'paid') { $cnt_paid++; $sum_paid += $totalYen; }
          else if ($status === 'locked') { $cnt_locked++; $sum_locked += $totalYen; }
          else { $cnt_open++; $sum_open += $totalYen; }

          $sum_paid_total += $paidYen;
        }
        $sum_all = $sum_open + $sum_locked + $sum_paid;
      ?>

      <div class="kpiGrid" style="grid-template-columns: repeat(2, 1fr);">
        <div class="kpi"><div class="label">未収（open）</div><div class="val"><?= number_format($sum_open) ?>円</div><div class="muted"><?= (int)$cnt_open ?>件</div></div>
        <div class="kpi"><div class="label">精算待（locked）</div><div class="val"><?= number_format($sum_locked) ?>円</div><div class="muted"><?= (int)$cnt_locked ?>件</div></div>
        <div class="kpi"><div class="label">入金完了（paid）</div><div class="val"><?= number_format($sum_paid) ?>円</div><div class="muted"><?= (int)$cnt_paid ?>件</div></div>
        <div class="kpi"><div class="label">入金合計（captured）</div><div class="val"><?= number_format($sum_paid_total) ?>円</div><div class="muted">※チケット状態とは別</div></div>
      </div>

      <div class="row" style="margin-top:10px">
        <span class="pill">合計（概算） <?= number_format($sum_all) ?>円</span>
      </div>
    </div>
  </div>

  <div class="tableWrap">
    <table>
      <thead>
        <tr>
          <th class="center">ID</th>
          <th>状態</th>
          <th class="num">税込合計</th>
          <th class="num">入金</th>
          <th class="num">残</th>
          <th>伝票作成時間</th>
          <th>伝票チェック時間</th>
          <th class="center">操作</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$tickets): ?>
        <tr><td colspan="8" class="muted" style="padding:18px;">この営業日の伝票がありません</td></tr>
      <?php else: ?>
        <?php foreach ($tickets as $t):
          $id = (int)($t['id'] ?? 0);
          $status = (string)($t['status'] ?? 'open');
          $totalYen = read_total_yen_from_snapshot((string)($t['totals_snapshot'] ?? ''));
          $paidYen  = (int)($t['paid_total'] ?? 0);
          $remainYen = max(0, $totalYen - $paidYen);
          $sv = ticket_status_view($status);
        ?>
        <tr>
          <td class="center" style="font-weight:900;"><?= $id ?></td>
          <td><span class="badge <?= h($sv['class']) ?>"><?= h($sv['label']) ?></span></td>
          <td class="num" style="font-weight:900;"><?= number_format($totalYen) ?>円</td>
          <td class="num"><?= number_format($paidYen) ?>円</td>
          <td class="num"><?= number_format($remainYen) ?>円</td>
          <td><?= h(time_hm((string)($t['opened_at'] ?? ''))) ?></td>
          <td><?= h(time_hm((string)($t['locked_at'] ?? ''))) ?></td>
          <td class="center">
            <div class="smallBtns">
              <a class="btn" href="/seika-app/public/cashier/cashier.php?store_id=<?= (int)$store_id ?>&ticket_id=<?= $id ?>&business_date=<?= h($business_date) ?>">開く</a>
              <button type="button" class="btn js-pay" data-ticket-id="<?= $id ?>">入金</button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

</div>

<div id="payDrawerBackdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.25); z-index:9998;"></div>

<aside id="payDrawer" style="
  position:fixed; top:0; right:0; height:100vh;
  width:min(100px, 50vw);
  transform:translateX(110%);
  transition:transform .18s ease;
  background:var(--card);
  color:var(--txt);
  border-left:1px solid var(--line);
  box-shadow: -12px 0 30px rgba(16,24,40,.15);
  z-index:9999;
  display:flex; flex-direction:column;
">
  <div style="padding:12px 14px; border-bottom:1px solid var(--line); display:flex; align-items:center; justify-content:space-between; gap:10px;">
    <div>
      <div style="font-weight:900;">入金</div>
      <div id="payDrawerSub" style="font-size:12px; color:var(--muted);">ticket_id: -</div>
    </div>
    <div style="display:flex; gap:8px;">
      <a id="payOpenNewTab" class="btn" href="#" target="_blank" rel="noopener">別タブ</a>
      <button id="payDrawerClose" type="button" class="btn">閉じる</button>
    </div>
  </div>

  <iframe id="payFrame" src="about:blank" style="border:0; width:100%; flex:1; background:var(--card);"></iframe>
</aside>

<script>
(function(){
  const drawer = document.getElementById('payDrawer');
  const backdrop = document.getElementById('payDrawerBackdrop');
  const btnClose = document.getElementById('payDrawerClose');
  const frame = document.getElementById('payFrame');
  const sub = document.getElementById('payDrawerSub');
  const openNewTab = document.getElementById('payOpenNewTab');

  function openDrawer(ticketId){
    const url = `/seika-app/public/cashier/payments.php?ticket_id=${encodeURIComponent(ticketId)}&store_id=<?= (int)$store_id ?>&business_date=<?= h($business_date) ?>`;
    frame.src = url;
    sub.textContent = `ticket_id: ${ticketId}`;
    openNewTab.href = url;

    backdrop.style.display = 'block';
    drawer.style.transform = 'translateX(0)';
    document.body.style.overflow = 'hidden';
  }

  function closeDrawer(){
    drawer.style.transform = 'translateX(110%)';
    backdrop.style.display = 'none';
    document.body.style.overflow = '';
  }

  document.querySelectorAll('.js-pay').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const ticketId = btn.getAttribute('data-ticket-id') || '';
      if (!ticketId) return;
      openDrawer(ticketId);
    });
  });

  btnClose.addEventListener('click', closeDrawer);
  backdrop.addEventListener('click', closeDrawer);

  window.addEventListener('keydown', (e)=>{
    if (e.key === 'Escape') closeDrawer();
  });
})();
</script>
<script>
(function(){
  var t = <?= json_encode($theme, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
  document.documentElement.setAttribute('data-theme', t);
  document.body && document.body.setAttribute('data-theme', t);
})();
</script>
<?php render_page_end(); ?>