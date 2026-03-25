<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/layout.php';
require_once __DIR__ . '/../../app/service_visit.php';
require_once __DIR__ . '/../../app/store_decommission.php';

require_login();
if (function_exists('require_role')) {
  require_role(['cast','staff','manager','admin','super_user']);
}

$pdo = db();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$store_id_guard = (int)($_GET['store_id'] ?? $_SESSION['store_id'] ?? 0);
if ($store_id_guard > 0) {
  store_decommission_assert_store_writable($pdo, $store_id_guard, '停止中の店舗では会計画面を開けません');
}

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

function positive_int_or_null($value): ?int {
  $n = (int)$value;
  return $n > 0 ? $n : null;
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

function hhmm_to_min(?string $hhmm): ?int {
  $hhmm = trim((string)$hhmm);
  if (!preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $m)) return null;
  $h = (int)$m[1];
  $i = (int)$m[2];
  if ($h < 0 || $h > 23 || $i < 0 || $i > 59) return null;
  return ($h * 60) + $i;
}

function read_remaining_minutes_from_snapshot(?string $json): ?array {
  $json = (string)$json;
  if ($json === '') return null;

  $j = json_decode($json, true);
  if (!is_array($j)) return null;

  $payload = $j['payload'] ?? null;
  if (!is_array($payload)) return null;

  $sets = $payload['sets'] ?? null;
  if (!is_array($sets) || $sets === []) return null;

  $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
  $nowMin = ((int)$now->format('H') * 60) + (int)$now->format('i');

  foreach ($sets as $idx => $set) {
    if (!is_array($set)) continue;
    $startMin = hhmm_to_min((string)($set['started_at'] ?? ''));
    $endMin = hhmm_to_min((string)($set['ends_at'] ?? ''));
    if ($startMin === null || $endMin === null) continue;

    $currentMin = $nowMin;
    if ($endMin <= $startMin) {
      $endMin += 1440;
      if ($currentMin < $startMin) $currentMin += 1440;
    }

    if ($currentMin < $startMin || $currentMin >= $endMin) continue;

    $remain = $endMin - $currentMin;
    if ($remain <= 0) return null;

    return [
      'minutes' => $remain,
      'is_warn' => $remain <= 10,
      'label' => '残' . $remain . '分',
      'set_no' => $idx + 1,
    ];
  }

  return null;
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

$show_void = ((int)($_GET['show_void'] ?? 0) === 1);

$qsBase = 'store_id=' . urlencode((string)$store_id);
if ($show_void) $qsBase .= '&show_void=1';

$cashierFeatures = [
  [
    'label' => '検索',
    'icon' => '🔎',
    'tag' => '現場',
    'href' => '/wbss/public/cashier/search.php?' . $qsBase,
    'available' => is_file(__DIR__ . '/search.php'),
  ],
  [
    'label' => '集計',
    'icon' => '📈',
    'tag' => '管理',
    'href' => '/wbss/public/cashier/reports/index.php?' . $qsBase,
    'available' => is_file(__DIR__ . '/reports/index.php'),
  ],
  [
    'label' => '操作ログ',
    'icon' => '🕵️',
    'tag' => '管理',
    'href' => '/wbss/public/cashier/audit/index.php?' . $qsBase,
    'available' => is_file(__DIR__ . '/audit/index.php'),
  ],
  [
    'label' => '設定',
    'icon' => '⚙️',
    'tag' => '管理',
    'href' => '/wbss/public/cashier/settings/index.php?' . $qsBase,
    'available' => is_file(__DIR__ . '/settings/index.php'),
  ],
];

$cashierPlannedFeatures = array_values(array_filter(
  $cashierFeatures,
  static fn(array $feature): bool => !$feature['available']
));

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
    $back = '/wbss/public/cashier/index.php?'
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
    $customerId = positive_int_or_null($_GET['customer_id'] ?? null);
    $eventInstanceId = positive_int_or_null($_GET['store_event_instance_id'] ?? null);
    $guestCount = max(1, (int)($_GET['guest_count'] ?? 1));
    $visitType = trim((string)($_GET['visit_type'] ?? 'unknown'));
    if ($visitType === '') $visitType = 'unknown';
    $firstFreeStage = trim((string)($_GET['first_free_stage'] ?? 'first'));
    if ($firstFreeStage === '') $firstFreeStage = 'first';
    $visitId = null;

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

    if (wbss_visit_tables_ready($pdo)) {
      $visitId = wbss_create_visit($pdo, [
        'store_id' => $store_id,
        'business_date' => $business_date,
        'customer_id' => $customerId,
        'store_event_instance_id' => $eventInstanceId,
        'primary_ticket_id' => $ticketId,
        'visit_status' => 'arrived',
        'visit_type' => $visitType,
        'arrived_at' => $now,
        'guest_count' => $guestCount,
        'charge_people_snapshot' => $guestCount,
        'first_free_stage' => $firstFreeStage,
        'created_by_user_id' => $actorId,
        'created_at' => $now,
        'updated_at' => $now,
      ]);

      if ($eventInstanceId !== null && $eventInstanceId > 0) {
        wbss_attach_event_entry_to_visit($pdo, [
          'store_id' => $store_id,
          'store_event_instance_id' => $eventInstanceId,
          'visit_id' => $visitId,
          'customer_id' => $customerId,
          'entry_type' => 'event',
          'arrived_at' => $now,
          'created_by_user_id' => $actorId,
          'updated_at' => $now,
        ]);
      }

      wbss_link_visit_ticket($pdo, [
        'store_id' => $store_id,
        'visit_id' => $visitId,
        'ticket_id' => $ticketId,
        'customer_id' => $customerId,
        'link_type' => 'primary',
        'created_at' => $now,
        'updated_at' => $now,
      ]);
    }

    $pdo->commit();

    $redirect = '/wbss/public/cashier/cashier.php?store_id=' . $store_id
      . '&ticket_id=' . $ticketId
      . '&business_date=' . urlencode($business_date);
    if ($visitId !== null) {
      $redirect .= '&visit_id=' . $visitId;
    }
    header('Location: ' . $redirect);
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
$visitJoinSql = '';
$visitSelectSql = '0 AS visit_id';
if (wbss_visit_tables_ready($pdo)) {
  $visitJoinSql = "LEFT JOIN visit_ticket_links vtl ON vtl.ticket_id = t.id";
  $visitSelectSql = 'COALESCE(vtl.visit_id, 0) AS visit_id';
}

$st = $pdo->prepare("
  SELECT
    t.id,
    t.store_id,
    t.business_date,
    t.status,
    t.opened_at,
    t.locked_at,
    t.totals_snapshot,
    {$visitSelectSql},
    COALESCE(p.paid_total, 0) AS paid_total
  FROM tickets t
  {$visitJoinSql}
  LEFT JOIN (
    SELECT ticket_id, SUM(amount) AS paid_total
    FROM ticket_payments
    WHERE status='captured' AND is_void=0
    GROUP BY ticket_id
  ) p ON p.ticket_id = t.id
  WHERE t.store_id=? AND t.business_date=?
    AND (? = 1 OR t.status <> 'void')
  ORDER BY t.id ASC
");
$st->execute([$store_id, $business_date, ($show_void ? 1 : 0)]);
$tickets = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$dashboardUrl = '/wbss/public/dashboard.php';
render_page_start('会計');
render_header('会計', [
  'back_href'  => '/wbss/public/dashboard.php',
  'back_label' => '← ダッシュボード',
  'right_html' => '',
  'show_store' => true,
  'show_user'  => false,
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

.deskLayout{
  display:grid;
  grid-template-columns: minmax(220px, 280px) minmax(0, 1fr);
  gap:14px;
  align-items:start;
}
@media (max-width: 1180px){
  .deskLayout{ grid-template-columns: 1fr; }
}

.sideStack{
  display:grid;
  gap:14px;
  position:sticky;
  top:14px;
}
@media (max-width: 1180px){
  .sideStack{ position:static; }
}

.mainStack{
  display:grid;
  gap:14px;
  min-width:0;
}

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
.todoList{
  display:flex;
  flex-wrap:wrap;
  gap:10px;
}
.todoItem{
  min-width:180px;
  padding:12px 14px;
  border:1px dashed var(--line);
  border-radius:14px;
  background:rgba(127,127,127,.05);
}
.todoItem b{
  display:block;
  margin-bottom:6px;
}
.todoItem .muted{
  line-height:1.5;
}

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
  grid-template-columns: repeat(2, 1fr);
  gap:10px;
  margin-top:10px;
}
@media (max-width: 820px){ .kpiGrid{ grid-template-columns: repeat(2, 1fr); } }

.summaryGrid{
  display:grid;
  grid-template-columns: repeat(4, 1fr);
  gap:10px;
}
@media (max-width: 1024px){
  .summaryGrid{ grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 640px){
  .summaryGrid{ grid-template-columns: 1fr; }
}

.summaryStrip{
  margin:6px 0 14px;
}

.summaryStrip .card{
  padding:12px;
}

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

.ticketsHeader{
  display:flex;
  justify-content:space-between;
  align-items:flex-end;
  gap:10px;
  flex-wrap:wrap;
  margin-bottom:10px;
}

.ticketsMeta{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
}
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
.remainBadge{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-width:78px;
  padding:6px 10px;
  border-radius:999px;
  border:1px solid rgba(37,99,235,.18);
  background:rgba(37,99,235,.08);
  color:#2563eb;
  font-size:12px;
  font-weight:1000;
  white-space:nowrap;
}
.remainBadge.warn{
  border-color:rgba(220,38,38,.28);
  background:rgba(220,38,38,.12);
  color:#dc2626;
  box-shadow:0 0 0 1px rgba(220,38,38,.06) inset;
}

.smallBtns{ display:flex; gap:8px; justify-content:center; align-items:center; flex-wrap:nowrap; }
.smallBtns .btn{ white-space:nowrap; }
/* ===== iPad用 ドロワー拡張 ===== */
@media (min-width: 768px) and (max-width: 1366px){
  #payDrawer{
    width: 72vw !important;
    min-width: 720px;
    max-width: 980px;
  }

  #payDrawerBackdrop{
    background: rgba(0,0,0,.45); /* 背景を少し暗く */
  }
}
@media (min-width: 1367px){
  #payDrawer{
    width: 760px !important;
    max-width: 760px;
  }
}
#payDrawer{
  transition: transform .25s cubic-bezier(.2,.8,.2,1);
}


/* --- header right: void filter --- */
.voidFilterForm{
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding: 0 6px;
}
.voidFilter{
  display:inline-flex;
  align-items:center;
  gap:6px;
  font-weight:900;
  color: var(--muted);
  white-space:nowrap;
}
.voidFilter input{ transform: translateY(1px); }

/* --- list: void row --- */
tr.isVoid{ opacity:.55; }
tr.isVoid .badge{ filter: grayscale(0.2); }


</style>

<div class="wrap">

  <?php
    $sum_open = $sum_locked = $sum_paid = $sum_void = 0;
    $cnt_open = $cnt_locked = $cnt_paid = $cnt_void = 0;
    $sum_paid_total = 0;

    foreach ($tickets as $t) {
      $status = (string)($t['status'] ?? 'open');
      $totalYen = read_total_yen_from_snapshot((string)($t['totals_snapshot'] ?? ''));
      $paidYen  = (int)($t['paid_total'] ?? 0);

      if ($status === 'paid') { $cnt_paid++; $sum_paid += $totalYen; }
      else if ($status === 'locked') { $cnt_locked++; $sum_locked += $totalYen; }
      else if ($status === 'void') { $cnt_void++; $sum_void += $totalYen; }
      else { $cnt_open++; $sum_open += $totalYen; }

      $sum_paid_total += $paidYen;
    }
    $sum_all = $sum_open + $sum_locked + $sum_paid;
  ?>

  <div class="summaryStrip">
    <div class="card">
      <div class="rowBetween">
        <div>
          <div class="title">日次サマリ</div>
          <div class="muted">営業日 <?= h($business_date) ?> の現在値</div>
        </div>
        <div class="row">
          <span class="pill">合計 <?= number_format($sum_all) ?>円</span>
          <span class="pill">入金合計 <?= number_format($sum_paid_total) ?>円</span>
        </div>
      </div>

      <div class="summaryGrid" style="margin-top:10px;">
        <div class="kpi"><div class="label">未収</div><div class="val"><?= number_format($sum_open) ?>円</div><div class="muted"><?= (int)$cnt_open ?>件</div></div>
        <div class="kpi"><div class="label">精算待</div><div class="val"><?= number_format($sum_locked) ?>円</div><div class="muted"><?= (int)$cnt_locked ?>件</div></div>
        <div class="kpi"><div class="label">入金完了</div><div class="val"><?= number_format($sum_paid) ?>円</div><div class="muted"><?= (int)$cnt_paid ?>件</div></div>
        <div class="kpi"><div class="label">入金合計</div><div class="val"><?= number_format($sum_paid_total) ?>円</div><div class="muted">入金反映済</div></div>
        <?php if ($show_void): ?>
          <div class="kpi"><div class="label">取消</div><div class="val"><?= number_format($sum_void) ?>円</div><div class="muted"><?= (int)$cnt_void ?>件</div></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="deskLayout">
    <aside class="sideStack">
      <section class="cardMenu">
        <h2>📄 現場操作</h2>

        <div class="btns">
          <a class="btn btnPrimary" href="/wbss/public/cashier/index.php?<?= $qsBase ?>&action=new&business_date=<?= h($business_date) ?>"><b>＋ 新規伝票</b></a><br>
          <a class="btn" href="/wbss/public/cashier/index.php?<?= $qsBase ?>&business_date=<?= h($business_date) ?>"><b>更新</b></a>
          <?php if ($show_void): ?>
            <a class="btn" href="/wbss/public/cashier/index.php?store_id=<?= (int)$store_id ?>&business_date=<?= h($business_date) ?>">取消を隠す</a>
          <?php else: ?>
            <a class="btn" href="/wbss/public/cashier/index.php?store_id=<?= (int)$store_id ?>&business_date=<?= h($business_date) ?>&show_void=1">取消も表示</a>
          <?php endif; ?>
        </div>

      </section>

      <section class="card">
        <div class="rowBetween">
          <div>
            <div class="title">営業日情報</div>
          </div>
        </div>

        <div class="kpiGrid">
          <div class="kpi"><div class="label">出勤</div><div class="val"><?= (int)$inCount ?>人</div></div>
          <div class="kpi"><div class="label">退勤</div><div class="val"><?= (int)$outCount ?>人</div></div>
          <div class="kpi"><div class="label">遅刻</div><div class="val"><?= (int)$lateCount ?>人</div></div>
          <div class="kpi"><div class="label">在籍</div><div class="val"><?= (int)$staffCount ?>人</div></div>
        </div>

        <div class="row" style="margin-top:12px">
          <span class="pill"><span class="dot"></span>状態：表示中</span>
          <span class="pill">入金：反映済のみ</span>
        </div>
      </section>
    </aside>

    <section class="mainStack">
      <div class="card">
        <div class="ticketsHeader">
          <div>
            <div class="title">本日の伝票一覧</div>
            <div class="muted">PCでは右側に一覧を広く出して、伝票を続けて確認しやすくしています。</div>
          </div>
          <div class="ticketsMeta">
            <span class="pill">総件数 <?= count($tickets) ?>件</span>
          </div>
        </div>

        <div class="tableWrap" style="margin-top:0;">
          <table>
      <thead>
        <tr>
          <th class="center">来店</th>
          <th>作成時間</th>
          <th class="center">残り時間</th>
          <th>状態</th>
          <th class="num">税込合計</th>
          <th class="num">入金</th>
          <th class="num">残</th>
          <th>印刷</th>
          <th class="center">操作</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$tickets): ?>
        <tr><td colspan="9" class="muted" style="padding:18px;">この営業日の伝票がありません</td></tr>
      <?php else: ?>
        <?php foreach ($tickets as $t):
          $id = (int)($t['id'] ?? 0);
          $visitId = (int)($t['visit_id'] ?? 0);
          $status = (string)($t['status'] ?? 'open');
          $totalYen = read_total_yen_from_snapshot((string)($t['totals_snapshot'] ?? ''));
          $paidYen  = (int)($t['paid_total'] ?? 0);
          $remainYen = max(0, $totalYen - $paidYen);
          $sv = ticket_status_view($status);
          $remainTimer = read_remaining_minutes_from_snapshot((string)($t['totals_snapshot'] ?? ''));
        ?>
        <tr class="<?= ($status === 'void') ? 'isVoid' : '' ?>">
          <td class="center"><?= $visitId > 0 ? '#' . $visitId : '—' ?></td>
          <td><?= h(time_hm((string)($t['opened_at'] ?? ''))) ?></td>
          <td class="center">
            <?php if ($remainTimer !== null): ?>
              <span class="remainBadge <?= !empty($remainTimer['is_warn']) ? 'warn' : '' ?>"><?= h((string)$remainTimer['label']) ?></span>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td><span class="badge <?= h($sv['class']) ?>"><?= h($sv['label']) ?></span></td>
          <td class="num" style="font-weight:900;"><?= number_format($totalYen) ?>円</td>
          <td class="num"><?= number_format($paidYen) ?>円</td>
          <td class="num"><?= number_format($remainYen) ?>円</td>
          <td class="center">
              <button type="button" class="btn" onclick="printTicket(<?= (int)$store_id ?>, <?= $id ?>, 'slip')">伝票</button>
              <button type="button" class="btn" onclick="printTicket(<?= (int)$store_id ?>, <?= $id ?>, 'receipt')">領収書</button>
          </td>
          <!-- <td><?= h(time_hm((string)($t['locked_at'] ?? ''))) ?></td> -->
          <td class="center">
            <div class="smallBtns">
              <a class="btn" href="/wbss/public/cashier/cashier.php?store_id=<?= (int)$store_id ?>&ticket_id=<?= $id ?>&business_date=<?= h($business_date) ?>&preview=1">開く</a>
              <?php if ($status !== 'void'): ?>
                <button type="button" class="btn js-pay" data-ticket-id="<?= $id ?>">入金</button>
              <?php else: ?>
                <span class="muted" style="font-weight:900;">取消</span>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
          </table>
        </div>
      </div>
    </section>
  </div>

</div>

<div id="payDrawerBackdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.25); z-index:9998;"></div>

<aside id="payDrawer" style="
  position:fixed; top:0; right:0; height:100vh;
  width:min(72vw, 980px);
  min-width:720px;
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
    const url = `/wbss/public/cashier/payments.php?ticket_id=${encodeURIComponent(ticketId)}&store_id=<?= (int)$store_id ?>&business_date=<?= h($business_date) ?>`;
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
  const AUTO_REFRESH_MS = 5 * 60 * 1000;

  function isDrawerOpen(){
    const drawer = document.getElementById('payDrawer');
    if (!drawer) return false;
    return drawer.style.transform === 'translateX(0)';
  }

  function tryAutoRefresh(){
    if (document.hidden) return;
    if (isDrawerOpen()) return;
    window.location.reload();
  }

  window.setInterval(tryAutoRefresh, AUTO_REFRESH_MS);
})();
</script>
<script>
async function printTicket(storeId, ticketId, kind){
  try{
    const r = await fetch('/wbss/public/cashier/print_api.php', {
      method:'POST',
      headers:{'Content-Type':'application/json', 'Accept':'application/json'},
      credentials: 'same-origin',
      body: JSON.stringify({store_id: storeId, ticket_id: ticketId, kind})
    });

    const text = await r.text(); // まず文字列で受ける
    let j = null;
    try { j = JSON.parse(text); } catch(e){ /* ignore */ }

    if(!r.ok){
      // JSONで返ってない時はHTMLの冒頭を見せる
      const head = text.slice(0, 200).replace(/\s+/g,' ');
      throw new Error((j && j.message) ? j.message : `HTTP ${r.status} / ${head}`);
    }
    if(!j || !j.ok){
      const head = text.slice(0, 200).replace(/\s+/g,' ');
      throw new Error((j && j.message) ? j.message : `JSONではない応答: ${head}`);
    }
    alert('印刷しました');
  }catch(e){
    alert('印刷エラー: ' + (e?.message || e));
  }
}
</script>
<?php render_page_end(); ?>
