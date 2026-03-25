<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/layout.php';
require_once __DIR__ . '/../../app/service_visit.php';

// 任意（環境により存在）
$storeLib = __DIR__ . '/../../app/store.php';
if (is_file($storeLib)) require_once $storeLib;

require_login();
$pdo = db();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

/**
 * 役割判定：auth.php の実装に寄せる
 * - has_role() が無ければ SESSION roles を見る（互換）
 */
if (!function_exists('has_role')) {
  function has_role(string $role): bool {
    if (function_exists('current_user_roles')) {
      $roles = current_user_roles();
      return is_array($roles) && in_array($role, $roles, true);
    }
    return isset($_SESSION['roles']) && is_array($_SESSION['roles']) && in_array($role, $_SESSION['roles'], true);
  }
}

// user id
$userId = 0;
if (function_exists('current_user_id')) {
  $userId = (int)current_user_id();
} else {
  $userId = (int)($_SESSION['user']['id'] ?? ($_SESSION['user_id'] ?? 0));
}

$isSuper   = has_role('super_user');
$isAdmin   = has_role('admin');
$isManager = has_role('manager');
$isCast    = has_role('cast');

// cast専用へ（必要なら）
if ($isCast && !$isAdmin && !$isManager && !$isSuper) {
  header('Location: /wbss/public/dashboard_cast.php');
  exit;
}

/**
 * 店舗選択（haruto_core前提）
 * - super/admin: 全店（GETで切替）
 * - manager: 自分に紐づく店だけ（repo_allowed_stores があるなら使用。なければ全店）
 */
$stores = [];
if ($isSuper || $isAdmin) {
  $stores = $pdo->query("SELECT id,name,business_day_start FROM stores WHERE is_active=1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} elseif ($isManager) {
  if (function_exists('repo_allowed_stores')) {
    $stores = repo_allowed_stores($pdo, $userId, false);
    // business_day_start が無い場合があるので埋める
    if ($stores) {
      $ids = array_map(fn($s)=>(int)$s['id'], $stores);
      $in  = implode(',', array_fill(0, count($ids), '?'));
      $st  = $pdo->prepare("SELECT id,business_day_start FROM stores WHERE id IN ($in)");
      $st->execute($ids);
      $m = [];
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $m[(int)$r['id']] = (string)($r['business_day_start'] ?? '');
      foreach ($stores as &$s) {
        $sid = (int)($s['id'] ?? 0);
        if (!isset($s['business_day_start'])) $s['business_day_start'] = $m[$sid] ?? '06:00:00';
      }
      unset($s);
    }
  } else {
    // 最低限：managerでも全店（運用で絞るなら repo_allowed_stores を用意）
    $stores = $pdo->query("SELECT id,name,business_day_start FROM stores WHERE is_active=1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!$stores) {
  echo "stores が取得できません（権限/DBを確認）";
  exit;
}

// store_id 決定（GET優先 → session → att_safe_store_id → 先頭）
$storeId = (int)($_GET['store_id'] ?? 0);
if ($storeId <= 0) {
  if (function_exists('get_current_store_id')) $storeId = (int)get_current_store_id();
}
if ($storeId <= 0) {
  $storeId = (int)($_SESSION['store_id'] ?? 0);
}
if ($storeId <= 0 && function_exists('att_safe_store_id')) {
  $storeId = (int)att_safe_store_id();
}
if ($storeId <= 0) {
  $storeId = (int)$stores[0]['id'];
}

// 許可チェック
$allowedIds = array_map(fn($s)=>(int)$s['id'], $stores);
if (!in_array($storeId, $allowedIds, true)) $storeId = (int)$stores[0]['id'];

if (function_exists('set_current_store_id')) {
  set_current_store_id($storeId);
} else {
  $_SESSION['store_id'] = $storeId;
}

// 店舗名 & 営業日開始
$storeName = '';
$bizStart  = '06:00:00';
foreach ($stores as $s) {
  if ((int)$s['id'] === $storeId) {
    $storeName = (string)($s['name'] ?? '');
    $bizStart  = (string)($s['business_day_start'] ?? '06:00:00');
    break;
  }
}
if ($storeName === '') $storeName = '#'.$storeId;

function business_date_for_store(string $businessDayStart): string {
  $tz = new DateTimeZone('Asia/Tokyo');
  $now = new DateTimeImmutable('now', $tz);

  $cut = preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $businessDayStart) ? $businessDayStart : '06:00:00';
  if (strlen($cut) === 5) $cut .= ':00';

  $cutDT = new DateTimeImmutable($now->format('Y-m-d') . ' ' . $cut, $tz);
  $biz = ($now < $cutDT) ? $now->modify('-1 day') : $now;
  return $biz->format('Y-m-d');
}

// ticket_id（haruto_core は tickets.id）
$ticket_id = (int)($_GET['ticket_id'] ?? 0);
$auto_preview = ((int)($_GET['preview'] ?? 0) === 1);
$business_date = business_date_for_store($bizStart);

// 席マスター（そのまま）
$SEAT_MASTER = [
  ['id'=>0,   'label'=>'(席を決定)'],
  ['id'=>1, 'label'=>'1'], ['id'=>2, 'label'=>'2'], ['id'=>3, 'label'=>'3'], ['id'=>4, 'label'=>'4'], ['id'=>5, 'label'=>'5'],
  ['id'=>6, 'label'=>'6'], ['id'=>7, 'label'=>'7'], ['id'=>8, 'label'=>'8'], ['id'=>9, 'label'=>'9'], ['id'=>10, 'label'=>'10'],
  ['id'=>11, 'label'=>'11'], ['id'=>12, 'label'=>'12'], ['id'=>13, 'label'=>'13'], ['id'=>14, 'label'=>'14'], ['id'=>15, 'label'=>'15'],
  ['id'=>16, 'label'=>'16'], ['id'=>17, 'label'=>'17'], ['id'=>18, 'label'=>'18'], ['id'=>19, 'label'=>'19'], ['id'=>20, 'label'=>'20'],
  ['id'=>21, 'label'=>'21'], ['id'=>22, 'label'=>'22'], ['id'=>23, 'label'=>'23'], ['id'=>24, 'label'=>'24'], ['id'=>25, 'label'=>'25'],
  ['id'=>26, 'label'=>'26'], ['id'=>27, 'label'=>'27'], ['id'=>28, 'label'=>'28'], ['id'=>29, 'label'=>'29'], ['id'=>30, 'label'=>'30'],
  ['id'=>101, 'label'=>'VIP A'],
  ['id'=>102, 'label'=>'VIP B'],
];

// ステータス取得（haruto_core: open/locked/paid/void）
$ticket_status = 'open';
$visitSummary = null;
$ticketSeatId = 0;
if ($ticket_id > 0) {
  $st = $pdo->prepare("SELECT status, business_date, seat_id FROM tickets WHERE id = ? LIMIT 1");
  $st->execute([$ticket_id]);
  $ticketRow = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  $ticket_status = (string)($ticketRow['status'] ?? 'open');
  $ticketSeatId = (int)($ticketRow['seat_id'] ?? 0);
  $ticketBusinessDate = (string)($ticketRow['business_date'] ?? '');
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ticketBusinessDate)) {
    $business_date = $ticketBusinessDate;
  }
  $visitSummary = wbss_fetch_ticket_visit_summary($pdo, $ticket_id);
}
// 旧UIは closed を見て編集禁止にしているので paid を closed 扱いに寄せる
$ticket_status_ui = ($ticket_status === 'paid') ? 'closed' : $ticket_status;

// right（layout.php の render_header 用）
$right = '
  <a class="btn" href="/wbss/public/dashboard.php">ダッシュボード</a>
  <a class="btn" href="/wbss/public/cashier/index.php?store_id='.(int)$storeId.'">新 会計一覧</a>
  ' . ($ticket_id > 0 ? '<a class="btn" href="/wbss/public/orders/index.php?ticket_id='.(int)$ticket_id.'&seat_id='.(int)$ticketSeatId.'">注文</a>' : '') . '
  ' . ($ticket_id > 0 ? '<a class="btn" href="/wbss/public/orders/ticket_casts.php?ticket_id='.(int)$ticket_id.'">担当集計</a>' : '') . '
';
render_page_start('新 会計一覧');
render_header('新 会計一覧');
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>WEB会計 iPad版</title>
<style>
/* =========================
   iPad 現場向け：見やすさ最優先（整理版）
   ※ 重複を削除し、最終的に効かせたい値だけ残しています
   ========================= */

:root{
  --bg:#f4f6fb;
  --card:#ffffff;
  --line:#e7e9f2;
  --text:#0f1222;
  --muted:#6b7280;

  --primary:#111827;
  --blue:#2563eb;
  --cyan:#0891b2;
  --green:#16a34a;
  --yellow:#f59e0b;
  --orange:#f97316;
  --red:#dc2626;
  --purple:#7c3aed;

  --softBlue:#eef4ff;
  --softGreen:#eaf8ef;
  --softYellow:#fff6e5;
  --softRed:#ffecec;
  --softPurple:#f3edff;

  --radius:18px;
  --shadow:0 10px 30px rgba(15,18,34,.08);
}

*{ box-sizing:border-box; }

/* ✅ ページ全体の横スクロールを潰す（iPadでよく起きる） */
html, body{ max-width:100%; overflow-x:hidden; }

body{
  margin:0;
  font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  background: var(--bg);
  color: var(--text);
  -webkit-text-size-adjust: 100%;
}

.wrap{
  max-width: min(1400px, 100%);
  margin: 0 auto;
  padding: 12px 12px 24px;
  min-width: 0;
}

/* grid/flex内で長い内容が親を押し広げるのを防ぐ */
.layout, .card, .pane, .headCard, .headTop, .actionGrid{ min-width:0; }

/* ====== Sticky Header ====== */
.stickyHeader{
  position: sticky;
  top: 0;
  z-index: 50;
  padding: 10px 0 12px;
  background: linear-gradient(180deg, rgba(244,246,251,.98), rgba(244,246,251,.90));
  backdrop-filter: blur(10px);
}

.headCard{
  background: var(--card);
  border: 1px solid var(--line);
  box-shadow: var(--shadow);
  border-radius: var(--radius);
  padding: 12px;
}

.headTop{
  display:flex;
  gap:10px;
  align-items:center;
  justify-content:space-between;
  flex-wrap:wrap;
}

.headMain{
  display:flex;
  align-items:center;
  gap:10px;
  flex-wrap:wrap;
  min-width:0;
  flex:1 1 auto;
}

.headActions{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  align-items:center;
}

.headActions .btn{
  min-height: 44px;
  padding: 10px 14px;
  font-size: 14px;
  border-radius: 12px;
  white-space: nowrap;
}

.titleBox h1{
  font-size: 18px;
  margin:0;
  font-weight: 1000;
  letter-spacing: .02em;
}

.sub{
  margin-top:4px;
  font-size: 12px;
  color: var(--muted);
  line-height: 1.35;
}

.statusPill{
  display:flex;
  align-items:center;
  gap:8px;
  padding: 8px 12px;
  border-radius: 999px;
  border:1px solid var(--line);
  background:#fff;
  font-weight: 900;
  font-size: 13px;
  white-space:nowrap;
}
.statusDot{ width:10px;height:10px;border-radius:999px; background:#bbb; }
.statusPill.open .statusDot{ background: var(--green); }
.statusPill.closed .statusDot{ background: var(--red); }
.statusPill.void .statusDot{ background: #9ca3af; }

/* ====== Action Bar ====== */
.actionGrid{
  display:grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 8px;
  margin-top: 8px;
}
@media (max-width: 1200px){
  .actionGrid{ grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (max-width: 820px){
  .actionGrid{ grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

.btn{
  border: none;
  border-radius: 16px;
  padding: 14px 14px;
  min-height: 58px;
  display:flex;
  align-items:center;
  justify-content:center;
  gap:10px;
  font-size: 16px;
  font-weight: 1000;
  text-decoration:none;
  cursor:pointer;
  user-select:none;
  touch-action: manipulation;
  box-shadow: 0 10px 18px rgba(0,0,0,.08);
}
.btn:active{ transform: translateY(1px); }
.btn.disabled, .btn:disabled{ opacity:.45; cursor:not-allowed; box-shadow:none; }

.b-dark{ background: var(--primary); color:#fff; }
.b-blue{ background: var(--blue); color:#fff; }
.b-cyan{ background: var(--cyan); color:#fff; }
.b-green{ background: var(--green); color:#fff; }
.b-yellow{ background: var(--yellow); color:#111; }
.b-orange{ background: var(--orange); color:#111; }
.b-red{ background: var(--red); color:#fff; }
.b-purple{ background: var(--purple); color:#fff; }

/* ====== Cards / Layout ====== */
.layout{
  display:grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 460px);
  gap: 12px;
  margin-top: 12px;
  align-items:start;
}
@media (max-width: 1180px){
  .layout{ grid-template-columns: 1fr; }
}

.card{
  background: var(--card);
  border: 1px solid var(--line);
  border-radius: var(--radius);
  box-shadow: var(--shadow);
  padding: 12px;
}

.cardTitle{
  display:flex;
  align-items:flex-end;
  justify-content:space-between;
  gap:10px;
  flex-wrap:wrap;
  margin-bottom: 8px;
}
.cardTitle h2{ margin:0; font-size: 14px; font-weight: 1000; }

.muted{ color: var(--muted); font-size: 12px; line-height: 1.35; }
.small{ font-size:12px; }
.hr{ height:1px; background: var(--line); margin: 10px 0; }

.grid2{ display:grid; grid-template-columns: 1fr 1fr; gap:10px; }
.grid3{ display:grid; grid-template-columns: 1fr 1fr 1fr; gap:10px; }
.grid4{ display:grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap:10px; }
.full{ grid-column: 1/-1; }
@media (max-width: 1024px){
  .grid4{ grid-template-columns: 1fr 1fr; }
  .grid3{ grid-template-columns: 1fr; }
}

.globalQuickGrid{
  display:grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap:8px;
  margin-bottom:8px;
}
@media (max-width: 1180px){
  .globalQuickGrid{ grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 700px){
  .globalQuickGrid{ grid-template-columns: 1fr; }
}

.quickMeta{
  margin-top:6px;
  display:flex;
  flex-wrap:wrap;
  gap:6px;
}

.quickStatus{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  flex-wrap:wrap;
}

.quickStatus strong{
  font-size: 16px;
  font-weight: 1100;
}

.globalActionRow{
  display:grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap:8px;
}
.globalActionRow #closeBtn{
  grid-column: span 2;
}
@media (max-width: 1024px){
  .globalActionRow{ grid-template-columns: repeat(3, minmax(0, 1fr)); }
  .globalActionRow #closeBtn{ grid-column: span 1; }
}
@media (max-width: 640px){
  .globalActionRow{ grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .globalActionRow #closeBtn{ grid-column: span 2; }
}

/* ✅ labelは統一（重複削除） */
label{
  font-size: 12px;
  color: var(--muted);
  display:block;
  margin-bottom: 6px;
  font-weight:800;
  line-height:1.2;
  min-height:1.2em;
}

input, select{
  width: 100%;
  border-radius: 14px;
  border: 1px solid var(--line);
  background:#fff;
  padding: 14px 12px;
  min-height: 58px;
  font-size: 16px;
  font-weight: 800;
  outline: none;
  min-width: 0;
}
input:focus, select:focus{
  border-color: rgba(37,99,235,.55);
  box-shadow: 0 0 0 4px rgba(37,99,235,.12);
}

/* ====== Timer Box ====== */
.timerBox{
  border-radius: 16px;
  border: 1px solid var(--line);
  padding: 12px;
  background: linear-gradient(180deg, #fff, #fbfbff);
}
.timerBig{ font-size: 24px; font-weight: 1100; }
.warnBox{
  margin-top:8px;
  padding:10px 12px;
  border-radius:14px;
  border:2px solid rgba(220,38,38,.25);
  background: var(--softRed);
  color: var(--red);
  font-weight: 1000;
  display:none;
}
.warnBox.on{ display:block; }

/* ====== Tabs ====== */
.tabs{
  display:flex;
  gap:10px;
  flex-wrap:nowrap;
  overflow-x:auto;
  padding-bottom:6px;
  -webkit-overflow-scrolling:touch;
}
.tabs.wrap{ flex-wrap:wrap; overflow-x:visible; }

.tab{
  flex: 0 0 auto;
  scroll-snap-align: start;
  padding: 14px 16px;
  border-radius: 16px;
  border: 1px solid var(--line);
  background: #fff;
  font-weight: 1000;
  font-size: 15px;
  cursor:pointer;
  user-select:none;
  touch-action: manipulation;
  box-shadow: 0 10px 18px rgba(0,0,0,.06);
}
.tab.on{
  border-color: rgba(37,99,235,.35);
  background: var(--softBlue);
  color: #0b2a6e;
}

.pane{
  border: 1px solid var(--line);
  border-radius: 18px;
  padding: 12px;
  background: #fff;
}

/* ====== Large number buttons ====== */
.gridButtons{ display:flex; flex-wrap:wrap; gap:8px; }

.noBtn{
  min-width: 70px;
  min-height: 58px;
  padding: 10px 12px;
  border-radius: 16px;
  border: 1px solid var(--line);
  background:#fff;
  font-weight: 1100;
  font-size: 17px;
  cursor:pointer;
  user-select:none;
  touch-action: manipulation;
}
.noBtn.on{ background: var(--primary); color:#fff; border-color: var(--primary); }
.noBtn.disabled{ opacity: .35; cursor:not-allowed; }

/* ====== Highlight blocks ====== */
.blockSafe{
  border-radius: 18px;
  padding: 12px;
  border: 1px solid var(--line);
  background: #fff;
}
.blockBlue{ background: var(--softBlue); border-color: rgba(37,99,235,.25); }
.blockGreen{ background: var(--softGreen); border-color: rgba(22,163,74,.25); }
.blockYellow{ background: var(--softYellow); border-color: rgba(245,158,11,.25); }
.blockPurple{ background: var(--softPurple); border-color: rgba(124,58,237,.20); }

.compactCard{
  padding: 10px;
}

.compactCard .cardTitle{
  margin-bottom: 6px;
}

.compactCard .cardTitle h2{
  font-size: 13px;
}

.compactGrid{
  gap:8px;
}

.compactCard .blockSafe{
  padding: 9px 10px;
  border-radius: 15px;
}

.compactCard label{
  margin-bottom: 4px;
  font-size: 11px;
}

.compactCard input,
.compactCard select{
  min-height: 46px;
  padding: 10px 10px;
  font-size: 14px;
  border-radius: 12px;
}

.compactCard .btn{
  min-height: 46px;
  padding: 10px 12px;
  font-size: 13px;
  border-radius: 13px;
  line-height: 1.15;
  white-space: nowrap;
}

.compactCard .muted{
  font-size: 10px;
  line-height: 1.25;
}

.compactCard .badgeMini,
.compactCard .badgeMini2,
.compactCard .badge{
  padding: 3px 8px;
  font-size: 10px;
}

.compactCard .timerBox{
  padding: 6px 8px;
  border-radius: 12px;
}

.compactCard .timerBig{
  font-size: 18px;
  line-height: 1.1;
}

.compactCard .warnBox{
  margin-top: 6px;
  padding: 7px 9px;
  border-radius: 10px;
  font-size: 11px;
}

.quickSelect{
  display:flex;
  align-items:center;
  gap:8px;
}

.quickSelect select,
.quickSelect input{
  flex:1 1 auto;
}

.quickValue{
  font-size: 11px;
  font-weight: 1000;
  color: var(--text);
  white-space: nowrap;
}

.quickStatusLine{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:8px;
  margin-bottom:4px;
}

.quickStatusLine strong{
  font-size: 15px;
}

.quickHint{
  font-size: 10px;
  color: var(--muted);
}

.compactTimeGrid{
  align-items:stretch;
}

.compactTimeGrid .blockSafe{
  min-height: 0;
}

.compactField{
  display:flex;
  flex-direction:column;
  gap:4px;
}

.compactField .muted{
  min-height: 0;
}

.timerMini{
  display:grid;
  gap:2px;
}

.timerMiniTop{
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:8px;
}

.timerMiniMeta{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  color: var(--muted);
  font-size: 10px;
}

.actionPair{
  display:grid;
  grid-template-columns: 1fr 1fr;
  gap:8px;
}

.actionPair .btn{
  min-width:0;
  font-size: 12px;
  letter-spacing: -.01em;
}

/* ====== Right column sticky ====== */
.stickyRight{ position: sticky; top: 96px; }
@media (max-width: 1200px){
  .stickyRight{ position: static; }
}

@media (max-width: 1180px){
  .wrap{
    padding: 8px 8px 16px;
  }

  .stickyHeader{
    padding: 6px 0 8px;
  }

  .headCard{
    padding: 8px;
  }

  .headMain{
    gap:8px;
  }

  .titleBox h1{
    font-size: 15px;
  }

  .sub{
    display:none;
  }

  .headCard > .muted{
    display:none;
  }

  .statusPill{
    padding: 5px 9px;
    font-size: 11px;
  }

  .actionGrid{
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap:6px;
    margin-top:6px;
  }

  .actionGrid .btn{
    min-height: 42px;
    font-size: 13px;
    padding: 8px 10px;
    border-radius: 12px;
  }

  .headActions{
    width:100%;
  }

  .headActions .btn{
    flex:1 1 0;
    min-height: 40px;
    font-size: 12px;
    padding: 8px 10px;
  }

  .rightCol{
    order: 2;
  }

  .leftCol{
    order: 1;
  }

  .layout{
    gap: 8px;
    margin-top: 8px;
  }

  .card{
    padding: 8px;
  }

  .compactCard{
    padding: 8px;
  }

  .compactCard .cardTitle{
    margin-bottom: 4px;
  }

  .compactCard .cardTitle .muted{
    display:none;
  }

  .globalQuickGrid{
    gap:6px;
    margin-bottom:6px;
  }

  .compactGrid{
    gap:6px;
  }

  .compactCard .blockSafe{
    padding: 7px 8px;
    border-radius: 13px;
  }

  .compactCard label{
    font-size: 10px;
    margin-bottom: 3px;
  }

  .compactCard input,
  .compactCard select{
    min-height: 40px;
    padding: 8px 9px;
    font-size: 13px;
  }

  .compactCard .btn{
    min-height: 40px;
    padding: 8px 10px;
    font-size: 12px;
  }

  .compactCard .muted{
    font-size: 9px;
    line-height: 1.2;
  }

  .quickMeta{
    margin-top:4px;
    gap:4px;
  }

  .quickHint{
    display:none;
  }

  .compactCard .timerBox{
    padding: 5px 6px;
  }

  .compactCard .timerBig{
    font-size: 16px;
  }

  .compactCard .warnBox{
    padding: 5px 7px;
    font-size: 10px;
  }

  .globalActionRow{
    gap:6px;
  }

  .compactCard > .grid4 > .full.muted{
    display:none;
  }
}

/* ====== Closed mode overlay ====== */
.closedOverlay{
  display:none;
  position: fixed;
  inset: 0;
  background: rgba(15,18,34,.55);
  z-index: 999;
  padding: 18px;
}
.closedOverlay.on{ display:block; }

.closedModal{
  max-width: 720px;
  margin: 40px auto;
  background: #fff;
  border-radius: 20px;
  border: 1px solid var(--line);
  padding: 16px;
  box-shadow: var(--shadow);
}

/* ====== legacy classes compatibility ====== */
.badgeMini{ display:inline-block; padding:4px 10px; border-radius:999px; border:1px solid var(--line); background:#fff; font-size:12px; font-weight:900; }
.badge{ display:inline-block; padding:4px 10px; border-radius:999px; background:#eef1ff; font-size:12px; font-weight:900; margin-right:6px; }
.badgeMini2{ display:inline-block; padding:6px 12px; border-radius:999px; border:1px solid var(--line); background:#fff; font-size:13px; font-weight:1000; white-space:nowrap; }

.danger{ color: var(--red); font-weight: 1000; }
.money{ font-variant-numeric: tabular-nums; }

.pill{ display:inline-block; padding:4px 10px; border-radius:999px; border:1px solid var(--line); background:#fff; font-size:12px; font-weight:900; }

.tableWrap{ overflow:auto; border-radius:14px; border:1px solid var(--line); }
.setTable{ width:100%; border-collapse:collapse; font-size:13px; }
.setTable th,.setTable td{ border-bottom:1px solid var(--line); padding:10px 8px; }
.setTable .num{ text-align:right; }
.setTable .center{ text-align:center; }

.vipOn{ background:var(--softYellow); }
.shTag{ display:inline-block; padding:3px 8px; border-radius:999px; border:1px solid var(--line); margin:0 6px 6px 0; font-size:12px; font-weight:900; background:#fff; }
.shH{ background:var(--softPurple); }
.shJ{ background:var(--softBlue); }

.grid30{ display:flex; flex-wrap:wrap; gap:8px; }
.btn2{
  border:none;
  border-radius:14px;
  padding:12px 14px;
  min-height:52px;
  font-weight:1000;
  box-shadow:0 8px 14px rgba(0,0,0,.08);
  background:#fff;
  border:1px solid var(--line);
}

/* ====== “詰めUI”部品 ====== */
.tabXL{
  flex: 0 0 auto;
  min-height: 42px;
  padding: 10px 16px;
  border-radius: 14px;
  border: 1px solid var(--line);
  background: #fff;
  font-weight: 700;
  font-size: 16px;
  cursor: pointer;
  user-select: none;
  touch-action: manipulation;
  box-shadow: 0 6px 12px rgba(0,0,0,.05);
  white-space: nowrap;
}
.tabXL.on{
  border-color: rgba(37,99,235,.35);
  background: var(--softBlue);
  color: #0b2a6e;
}

.setTabCard{
  min-width: 150px;
  display:flex;
  flex-direction:column;
  align-items:flex-start;
  gap:4px;
  padding: 10px 14px;
}

.setTabCardTitle{
  font-size:14px;
  font-weight:1000;
  line-height:1.1;
}

.setTabCardMeta{
  font-size:11px;
  color:var(--muted);
  line-height:1.2;
}

.tabXL.on .setTabCardMeta{
  color:#335389;
}

.setPaneTop{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  flex-wrap:wrap;
  margin-bottom:10px;
}

.phaseTabs{ display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.phaseBtn{
  min-height: 60px;
  padding: 14px 16px;
  border-radius: 16px;
  border: 1px solid var(--line);
  background:#fff;
  font-weight:1100;
  font-size:16px;
  cursor:pointer;
  touch-action: manipulation;
  user-select:none;
  box-shadow: 0 10px 18px rgba(0,0,0,.06);
}
.phaseBtn.on{ background: var(--primary); color:#fff; border-color: var(--primary); }
.phaseBtn.disabled{ opacity:.35; cursor:not-allowed; box-shadow:none; }

.cashierDashTabs{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  padding:4px;
  margin:10px 0 10px;
  border:1px solid var(--line);
  border-radius:18px;
  background:linear-gradient(180deg, #ffffff, #f7f8fc);
  box-shadow:0 10px 24px rgba(15,18,34,.08);
}

.cashierDashTab{
  appearance:none;
  border:1px solid transparent;
  background:transparent;
  color:var(--muted);
  border-radius:14px;
  min-height:58px;
  padding:12px 16px;
  font-size:15px;
  font-weight:900;
  line-height:1.2;
  cursor:pointer;
  transition:background .16s ease, color .16s ease, border-color .16s ease, transform .16s ease;
  display:flex;
  align-items:center;
  gap:10px;
  flex:1 1 180px;
}

.cashierDashTab:hover{
  color:var(--text);
  border-color:rgba(37,99,235,.18);
  background:rgba(37,99,235,.05);
}

.cashierDashTab.is-active{
  color:#0f172a;
  border-color:rgba(37,99,235,.20);
  background:linear-gradient(135deg, #bfdbfe, #dbeafe 55%, #fef3c7);
}

.cashierDashTabIcon{
  width:34px;
  height:34px;
  border-radius:12px;
  display:grid;
  place-items:center;
  background:#fff;
  box-shadow: inset 0 0 0 1px rgba(15,18,34,.08);
  font-size:16px;
  flex:0 0 auto;
}

.cashierDashTabText{
  display:flex;
  flex-direction:column;
  align-items:flex-start;
  gap:2px;
}

.cashierDashTabTitle{
  font-size:14px;
  font-weight:1000;
  color:inherit;
}

.cashierDashTabMeta{
  font-size:11px;
  color:var(--muted);
  font-weight:700;
}

.cashierDashTab.is-active .cashierDashTabMeta{
  color:#334155;
}

.workTabPane{
  display:none;
}

.workTabPane.active{
  display:block;
}

.btnSmall{
  border:none;
  border-radius: 16px;
  min-height: 58px;
  padding: 14px 14px;
  font-weight:1100;
  font-size:16px;
  cursor:pointer;
  touch-action: manipulation;
  user-select:none;
  box-shadow: 0 10px 18px rgba(0,0,0,.08);
}
.btnSmall:active{ transform: translateY(1px); }

/* ====== legacy row layouts（重複整理：最終値） ====== */
.rowTight{
  display:grid;
  grid-template-columns: 160px 160px 1fr;
  gap:10px;
  align-items:end;
}
@media (max-width: 1024px){
  .rowTight{ grid-template-columns: 1fr 1fr; }
}

/* ドリンク行：最終的に使うレイアウトだけ残す */
.drinkRowTight{
  display:grid;
  grid-template-columns: 1.2fr 1fr auto; /* “金額” “対象” “追加ボタン” */
  gap:12px;
  align-items:end;
}
.drinkRowTight > div{ min-width:0; }
.drinkRowTight > div:last-child{
  display:flex;
  flex-direction:column;
  align-items:flex-end;
}
@media (max-width:820px){
  .drinkRowTight{ grid-template-columns: 1fr; align-items:stretch; }
  .drinkRowTight > div:last-child{ align-items:stretch; }
  #addDrinkBtn{ width:100%; }
}

/* 旧drinkRow（複数行入力のほう） */
.drinkRow{
  display:grid;
  grid-template-columns:160px 140px 1fr 110px;
  gap:10px;
  align-items:center;
  margin-bottom:8px;
}
.key{ font-weight:1000; }

/* ===== ドリンク金額 input + ボタン ===== */
.amtRow{
  display:flex;
  gap:10px;
  align-items:stretch;
  flex-wrap:nowrap;
}
.amtRow .amtInput{ flex: 1 1 auto; min-width: 140px; }
.amtRow .amtBtns{
  flex: 0 0 auto;
  display:flex;
  gap:8px;
  align-items:stretch;
  white-space:nowrap;
}
.btnAmt{
  min-height: 58px;
  padding: 0 14px;
  border-radius: 14px;
  font-weight: 1000;
  border: 1px solid var(--line);
  background:#fff;
  box-shadow: 0 8px 14px rgba(0,0,0,.08);
  cursor:pointer;
}
.btnAmt:active{ transform: translateY(1px); }

@media (max-width: 820px){
  .amtRow{ flex-wrap:wrap; }
  .amtRow .amtBtns{ width:100%; white-space:normal; }
  .amtRow .amtBtns .btnAmt{ flex:1 1 0; }
}

/* ===== レディース（checkbox + stepper） ===== */
.chkMini{
  width:22px;
  height:22px;
  min-height:auto;
  padding:0;
  border-radius:6px;
  accent-color: var(--blue);
}

.stepper{
  display:flex;
  align-items:stretch;
  border:1px solid var(--line);
  border-radius:14px;
  overflow:hidden;
  background:#fff;
}

.stepBtn{
  min-width:64px;
  min-height:58px;
  border:none;
  background:#fff;
  font-size:20px;
  font-weight:1100;
  cursor:pointer;
  touch-action: manipulation;
}
.stepBtn:active{ transform: translateY(1px); }

.stepVal{
  color: var(--text);
  -webkit-text-fill-color: var(--text);
  opacity: 1;
}

.btnMini{
  border:1px solid var(--line);
  background:#fff;
  border-radius:12px;
  padding:10px 12px;
  min-height:46px;
  font-weight:900;
  cursor:pointer;
  box-shadow: 0 8px 14px rgba(0,0,0,.08);
}

/* ===== ゲスト人数 / レディース：横並び安定 ===== */
.guestLadiesRow{
  display:flex;
  gap:12px;
  align-items:flex-end;
  flex-wrap:wrap;
}
.guestBox{ flex: 0 0 auto; }
.ladiesBox{ flex: 1 1 260px; min-width:260px; }

/* ゲスト人数は2桁前提：短く */
#guest_people{
  width: 15ch;
  max-width: 300px;
  text-align:center;
}

/* ===== row2：左（ゲスト/レディース）と右（VIP/種別）を上揃え ===== */
.row2{
  display:grid;
  grid-template-columns: 1.2fr 1fr;
  gap:12px;
  align-items:start;
}
.row2 .row2{
  display:grid;
  grid-template-columns: 1fr 1fr;
  gap:10px;
  align-items:start;
}
/* =========================
   Collapsible / Tabs (共通)
   ========================= */

/* タブバー */
.sectionTabs{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
  margin: 10px 0 12px;
}
.sectionTab{
  border:1px solid var(--line);
  background:#fff;
  border-radius:16px;
  padding:12px 14px;
  min-height:52px;
  font-weight:1000;
  cursor:pointer;
  user-select:none;
  touch-action: manipulation;
  box-shadow: 0 8px 14px rgba(0,0,0,.06);
}
.sectionTab.on{
  background: var(--softBlue);
  border-color: rgba(37,99,235,.35);
  color:#0b2a6e;
}

/* 折りたたみヘッダ */
.foldHead{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  padding: 10px 12px;
  border:1px solid var(--line);
  border-radius:16px;
  background:#fff;
  cursor:pointer;
  user-select:none;
  touch-action: manipulation;
  box-shadow: 0 8px 14px rgba(0,0,0,.06);
}
.foldTitle{
  display:flex;
  align-items:center;
  gap:10px;
  min-width:0;
}
.foldTitle .name{
  font-weight:1000;
  font-size:14px;
  white-space:nowrap;
}
.foldTitle .hint{
  font-size:12px;
  color: var(--muted);
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
  max-width: 42vw;
}
.foldIcon{
  width:38px;
  height:38px;
  border-radius:12px;
  border:1px solid var(--line);
  display:flex;
  align-items:center;
  justify-content:center;
  font-weight:1100;
  background:#fff;
}

/* 折りたたみ本文 */
.foldBody{
  margin-top:10px;
  display:none;
}
.foldWrap[data-open="1"] .foldBody{ display:block; }

/* タブ切替の場合：選択中だけ表示 */
.foldWrap[data-mode="tabs"] .foldBody{ display:none; }
.foldWrap[data-mode="tabs"][data-open="1"] .foldBody{ display:block; }
/* FREE客色付け */
.freeLine{
  color:blue;
  font-weight:900;
  letter-spacing:0.5px;
}
</style>
</head>
<body>
<div class="wrap">

  <!-- ===== Sticky Header ===== -->
  <div class="stickyHeader">
    <div class="headCard">
      <div class="headTop">
        <div class="headMain">
          <div class="titleBox">
            <h1>WEB会計（iPad版）</h1>
            <div class="sub">
              店舗: <b><?= h($storeName) ?></b>（#<?= (int)$storeId ?>） / 営業日: <b><?= h($business_date) ?></b>（切替 <?= h($bizStart) ?>）<br>
              Ticket: <b>#<?= (int)$ticket_id ?></b> / 出勤確定のキャストだけが候補として表示されます。
              <?php if (is_array($visitSummary)): ?><br>
              Visit: <b>#<?= (int)($visitSummary['visit_id'] ?? 0) ?></b> /
              Customer: <b><?= (int)($visitSummary['customer_id'] ?? 0) > 0 ? (int)$visitSummary['customer_id'] : '—' ?></b> /
              Event: <b><?= (int)($visitSummary['store_event_instance_id'] ?? 0) > 0 ? (int)$visitSummary['store_event_instance_id'] : '—' ?></b> /
              Type: <b><?= h((string)($visitSummary['visit_type'] ?? 'unknown')) ?></b>
              <?php endif; ?>
            </div>
          </div>

          <div class="headActions">
            <a class="btn b-dark" href="/wbss/public/dashboard.php">ダッシュボード</a>
            <a class="btn b-blue" href="/wbss/public/cashier/index.php?store_id=<?= (int)$storeId ?>">新 会計一覧</a>
          </div>
        </div>

        <?php
          $cls = 'open';
          $label = '未会計';
          if ($ticket_status_ui === 'closed') { $cls='closed'; $label='会計済'; }
          if ($ticket_status_ui === 'void')   { $cls='void';   $label='無効'; }
        ?>
        <div class="statusPill <?= h($cls) ?>" id="statusBadge">
          <span class="statusDot"></span>
          状態：<b><?= h($label) ?></b>
        </div>
      </div>

    </div>
  </div>

  <form method="post" id="billForm">
    <input type="hidden" name="payload_json" id="payload_json" value="">

  <div class="layout">
    <!-- ===== Left ===== -->
    <div class="leftCol">

      <div class="card compactCard">
        <div class="cardTitle">
          <h2>全体操作</h2>
          <div class="muted">先に席を決めておくと、ドリンク追加まで迷わず進めます</div>
        </div>

        <div id="globalQuickTools"></div>

        <div class="grid4 compactGrid compactTimeGrid">
          <div class="blockSafe blockBlue compactField">
            <label>開始時刻（セット1の開始）</label>
            <input type="time" id="start_time" value="20:00" step="60">
            <div class="muted">※同伴は 20:00固定</div>
          </div>

          <div class="blockSafe blockYellow compactField">
            <label>割引（税別・円）</label>
            <input type="number" id="discount" min="0" value="0" inputmode="numeric">
            <div class="muted">※入れ間違い防止：マイナス不可</div>
          </div>

          <div class="blockSafe blockGreen compactField">
            <label>タイマー</label>
            <div class="timerBox timerMini">
              <div class="timerMiniTop">
                <div class="muted" id="timerInfo">未開始</div>
                <div class="timerBig" id="timerRemain">—</div>
              </div>
              <div class="timerMiniMeta">
                <span id="timerEnds">—</span>
              </div>
              <div class="warnBox" id="warnBox">交渉時間</div>
            </div>
          </div>

          <div class="blockSafe blockPurple compactField">
            <label>操作</label>
            <div class="actionPair">
              <button type="button" class="btn b-green" id="startBtn">▶ 開始</button>
              <button type="button" class="btn b-orange" id="stopBtn">■ 停止</button>
            </div>
          </div>

          <div class="full globalActionRow">
            <button type="button" class="btn b-dark" id="saveBtnInline">保存（DB）</button>
            <button type="button" class="btn b-blue" id="addSetBtn">+ 延長</button>
            <button class="btn b-green" type="button" id="previewBtn">プレビュー</button>
            <button class="btn b-dark" type="button" id="freeHistBtn">FREE履歴</button>
            <button class="btn b-red" type="button" id="closeBtn">会計（チェック）</button>
          </div>

          <div class="full muted">
            20:00〜20:29開始=6000 / 20:30以降=7000・人数=max(来店人数,指名数)
          </div>
        </div><!-- /grid4 -->
      </div><!-- ✅ /card（全体カード） -->

      <div class="card">
        <div class="cardTitle">
          <h2>セット一覧</h2>
          <div class="muted">上でセットを切り替え、下で内容を編集します</div>
        </div>
        <div class="tabs" id="setTabs"></div>
        <div class="pane" id="setPane"></div>
      </div>

    </div><!-- /leftCol -->

      <!-- ===== Right ===== -->
      <div class="rightCol">
        <div class="card stickyRight" id="previewCard" style="display:none;">
          <div class="cardTitle">
            <h2>プレビュー（ブラウザ計算）</h2>
            <div class="muted">確認用</div>
          </div>
          <div id="preview"></div>
          <div class="hr"></div>
          <div id="previewDetail"></div>
          <div class="hr"></div>
          <div id="previewPt"></div>
        </div>

        <div class="card stickyRight" id="serverCard" style="display:none;">
          <div class="cardTitle">
            <h2>サーバ確定結果（API計算）</h2>
            <div class="muted">最終確認</div>
          </div>
          <div id="serverSummary"></div>
          <div class="hr"></div>
          <div id="serverPt"></div>
          <div class="hr"></div>
          <div id="serverHistory"></div>
        </div>

        <div class="card" id="serverErrCard" style="display:none;">
          <div class="danger" id="serverErr"></div>
        </div>
      </div>
    </div>
  </form>

</div>

<div class="closedOverlay" id="closedOverlay">
  <div class="closedModal">
    <h2 style="margin:0 0 6px;font-size:16px;font-weight:1100;">この伝票は「会計済」です</h2>
    <div class="muted">誤入力防止のため編集を無効化しています。必要なら管理者でステータス変更してください。</div>
    <div style="display:flex;gap:10px;margin-top:12px;flex-wrap:wrap;">
      <a class="btn b-blue" href="/wbss/public/cashier/index.php?store_id=<?= (int)$storeId ?>">会計一覧へ</a>
      <a class="btn b-dark" href="/wbss/public/dashboard.php">ダッシュボードへ</a>
    </div>
  </div>
</div>
<script>
/* ========= 保存（上部ボタン） ========= */
(() => {
  ['saveBtnTop', 'saveBtnInline'].forEach((id) => {
    const btn = document.getElementById(id);
    if (!btn) return;
    btn.addEventListener('click', async () => {
      try {
        await saveToDB();
        alert('保存しました');
      } catch (e) {
        alert('保存失敗: ' + (e?.message || e));
      }
    });
  });

  /* ========= iPad版：追加の誤操作防止 ========= */
  const ids = ['serverCalcBtn', 'serverCalcBtn_dup', 'serverCalcBtn_head'];
  const btns = ids.map(id => document.getElementById(id)).filter(Boolean);

  async function doCalc() {
    try {
      await calcOnServer();
    } catch (err) {
      showServerError(err?.message || String(err));
    }
  }
  btns.forEach(b => b.addEventListener('click', doCalc));

  /* ========= 一覧へ戻る ========= */
  const goIndex2 = document.getElementById('goIndex2Btn');
  if (goIndex2) {
    goIndex2.addEventListener('click', () => {
      const url = '/wbss/public/cashier/index.php?store_id=<?= (int)$storeId ?>';
      window.location.href = url;
    });
  }
})();
</script>

<script>
  // legacy互換：SHOP_ID/BUSINESS_DATE/TICKET_ID を供給
  window.SHOP_ID       = window.SHOP_ID       ?? <?= (int)$storeId ?>; // store_id
  window.BUSINESS_DATE = window.BUSINESS_DATE ?? <?= json_encode($business_date, JSON_UNESCAPED_UNICODE) ?>;
  window.TICKET_STATUS = window.TICKET_STATUS ?? <?= json_encode($ticket_status_ui, JSON_UNESCAPED_UNICODE) ?>;
  window.TICKET_ID     = window.TICKET_ID     ?? <?= (int)$ticket_id ?>;
  window.SEAT_MASTER   = window.SEAT_MASTER   ?? <?= json_encode($SEAT_MASTER, JSON_UNESCAPED_UNICODE) ?>;
  window.__CONFIRM_CLOSE_PHRASE__ = window.__CONFIRM_CLOSE_PHRASE__ ?? 'CLOSE';

  const SHOP_ID = window.SHOP_ID;
  const BUSINESS_DATE = window.BUSINESS_DATE;
  const TICKET_ID = window.TICKET_ID;
  const TICKET_STATUS = window.TICKET_STATUS;

  function applyVipBySeat(setObj, seatId){
    const beforeVip = !!setObj.vip;
    const beforeAuto = !!setObj.vip_auto_by_seat;

    seatId = clampSeatId(seatId);

    if (typeof setObj.vip_auto_by_seat !== 'boolean') setObj.vip_auto_by_seat = false;

    if (seatIsVip(seatId)) {
      setObj.vip = true;
      setObj.vip_auto_by_seat = true;
    } else {
      if (setObj.vip_auto_by_seat) setObj.vip = false;
      setObj.vip_auto_by_seat = false;
    }

    if (beforeVip !== !!setObj.vip || beforeAuto !== !!setObj.vip_auto_by_seat) {
      logEvent('vip_change', state.ui.selected_set_index+1, {
        before: beforeVip,
        after: !!setObj.vip,
        reason: 'applyVipBySeat'
      });
    }
  }

  const payloadEl   = document.getElementById('payload_json');
  const startTimeEl = document.getElementById('start_time');
  const discountEl  = document.getElementById('discount');

  const previewCard     = document.getElementById('previewCard');
  const previewEl       = document.getElementById('preview');
  const previewDetailEl = document.getElementById('previewDetail');
  const previewPtEl     = document.getElementById('previewPt');

  const timerInfoEl   = document.getElementById('timerInfo');
  const timerRemainEl = document.getElementById('timerRemain');
  const timerEndsEl   = document.getElementById('timerEnds');
  const warnBoxEl     = document.getElementById('warnBox');

  const setTabsEl = document.getElementById('setTabs');
  const setPaneEl = document.getElementById('setPane');

  const serverCard       = document.getElementById('serverCard');
  const serverSummaryEl  = document.getElementById('serverSummary');
  const serverPtEl       = document.getElementById('serverPt');
  const serverHistoryEl  = document.getElementById('serverHistory');
  const serverErrCard    = document.getElementById('serverErrCard');
  const serverErrEl      = document.getElementById('serverErr');
  
  const globalQuickToolsEl = document.getElementById('globalQuickTools');
  
  function setFreePhase(setIndex, custNo, phase){
    const s = state.sets[setIndex];
    const cust = getCustomer(s, custNo);
    if (!cust || cust.mode !== 'free') return;

    const before = cust.free.phase;
    if (!(phase === 'first' || phase === 'second' || phase === 'third')) return;

    cust.free.phase = phase;
    logEvent('free_phase_set', setIndex+1, {custNo, before, after: phase});
    render(false);
  }

  function kindOptionsHtml(selectedKind, idx){
    return `
      <option value="normal50" ${selectedKind==='normal50'?'selected':''}>通常50分 7000</option>
      <option value="half25" ${selectedKind==='half25'?'selected':''}>ハーフ25分 3500</option>
      <option value="pack_douhan" ${selectedKind==='pack_douhan'?'selected':''} ${idx>=1?'disabled':''}>同伴 13000</option>
    `;
  }

  function renderGlobalQuickTools(setObj, idx){
    if (!globalQuickToolsEl) return;
    if (!setObj) {
      globalQuickToolsEl.innerHTML = '';
      return;
    }

    const seatId = clampSeatId(setObj.seat_id || 0);
    const seatMissing = seatId <= 0;
    const drinkSum = (setObj.drinks || []).reduce((a, d)=> a + (d.amount|0), 0);

    globalQuickToolsEl.innerHTML = `
      <div class="globalQuickGrid">
        <div class="blockSafe blockBlue">
          <label>現在セットの席</label>
          <div class="quickSelect">
            <select id="quickSeatSel">${seatOptionsHtml(seatId)}</select>
            <span class="quickValue">${seatMissing ? '未定' : escapeHtml(seatLabelById(seatId))}</span>
          </div>
          <div class="quickMeta">
            <span class="badgeMini">${seatMissing ? '席未決定' : `席 ${escapeHtml(seatLabelById(seatId))}`}</span>
            <span class="badgeMini">${seatIsVip(seatId) ? 'VIP自動ON' : '通常席'}</span>
          </div>
        </div>

        <div class="blockSafe blockYellow">
          <label>来店人数</label>
          <div class="quickSelect">
            <input type="number" id="quickGuestPeople" min="0" value="${setObj.guest_people|0}">
            <span class="quickValue">${setObj.guest_people|0}名</span>
          </div>
          <div class="quickHint">現在セットへ即反映</div>
        </div>

        <div class="blockSafe blockPurple">
          <label>セット種別</label>
          <select id="quickKindSel">
            ${kindOptionsHtml(setObj.kind || 'normal50', idx)}
          </select>
          <div class="quickHint">延長前の確認もここで</div>
        </div>

        <div class="blockSafe blockGreen">
          <label>現在セットの状態</label>
          <div class="quickStatusLine">
            <strong>${seatMissing ? '席を先に決定' : 'ドリンク追加OK'}</strong>
            <span class="badgeMini">セット${idx + 1}</span>
          </div>
          <div class="quickMeta">
            <span class="badgeMini">ドリンク ${drinkSum.toLocaleString()}円</span>
            <span class="badgeMini">終了 ${escapeHtml(setObj.ends_at || '—')}</span>
          </div>
        </div>
      </div>
    `;

    document.getElementById('quickSeatSel')?.addEventListener('change', (e)=>{
      setSeatId(idx, e.target.value);
    });
    document.getElementById('quickGuestPeople')?.addEventListener('change', (e)=>{
      setGuestPeople(idx, e.target.value);
    });
    document.getElementById('quickKindSel')?.addEventListener('change', (e)=>{
      setKindForSet(idx, e.target.value);
    });
  }

// CLOSEボタン：payments.php へ遷移（入金へ誘導）
(() => {
  const closeBtn = document.getElementById('closeBtn');
  if (!closeBtn) return;

  // ---- helpers
  const qs = new URLSearchParams(location.search);
  const getInt = (v) => {
    const n = parseInt(String(v ?? ''), 10);
    return Number.isFinite(n) ? n : 0;
  };
  const getStr = (v) => String(v ?? '').trim();

  // ticket_id は URL優先、無ければ window から
  const ticketId = getInt(qs.get('ticket_id')) || getInt(window.TICKET_ID);

  // store_id / business_date は URL or window or PHP埋め込みで確保したい
  // （この2つがURLに無いと payments.php の条件によっては困る）
  const storeId =
    getInt(qs.get('store_id')) ||
    getInt(window.STORE_ID) ||
    1;

  const businessDate =
    getStr(qs.get('business_date')) ||
    getStr(window.BUSINESS_DATE) ||
    ''; // 取れないなら空（payments.php側でデフォルトがあるならOK）

  // ---- 初期無効条件
  if (!ticketId) {
    closeBtn.disabled = true;
    closeBtn.classList.add('disabled');
    closeBtn.textContent = '精算する（保存後に有効）';
    closeBtn.title = 'ticket_id が無いので先に保存してください';
    return;
  }

  // paid なら押せない（あなたのDBロジックは paid を使ってるので）
  if (window.TICKET_STATUS === 'paid') {
    closeBtn.disabled = true;
    closeBtn.classList.add('disabled');
    closeBtn.textContent = '入金完了（PAID）';
    closeBtn.title = '入金済';
    return;
  }


})();

  const KIND = {
    normal50:   { price: 7000, dur: 50, label:'通常50分(7000)' },
    half25:     { price: 3500, dur: 25, label:'ハーフ25分(3500)' },
    pack_douhan:{ price:13000, dur: 90, label:'同伴 13000' },
  };
  const PACK_START = "20:00";
  const PACK_END   = "21:30";
  const FORCE_NORMAL_AFTER = "21:30";

  const state = {
    sets: [],
    history: [],
    ui: { selected_set_index: 0, last_payer_sel: null, work_tab: 'set' },
    timer: { running:false, base_start_hhmm:null, interval_id:null },
    cast_candidates: [],
  };

  function getCandidateNos(){
    const nos = (state.cast_candidates || [])
      .map(x => Number(x.cast_no || 0))
      .filter(n => Number.isInteger(n) && n >= 1 && n < 900);
    return Array.from(new Set(nos)).sort((a,b)=>a-b);
  }
  function candidateTitle(no){
    const hit = (state.cast_candidates || []).find(x => Number(x.cast_no) === Number(no));
    return hit ? `${no}番 ${hit.name || ''}` : `${no}番`;
  }

  async function loadCastCandidates(){
    const shopId = SHOP_ID;
    const businessDate = BUSINESS_DATE;

    // ★ ここは「旧API名」のまま。haruto_core側で api_cast_candidates.php が生きてる前提。
    // もし新しい場所にあるならここだけURL差し替え。
    const r = await fetch(
    `/wbss/public/api/cast_candidates.php?store_id=${encodeURIComponent(shopId)}&business_date=${encodeURIComponent(businessDate)}`,
    { cache:'no-store' }
    );

    const txt = await r.text();
    if (!r.ok) throw new Error(`candidates HTTP ${r.status}: ${txt.slice(0,200)}`);

    let j;
    try { j = JSON.parse(txt); } catch(e){ throw new Error('candidates Non-JSON: ' + txt.slice(0,200)); }
    if (!j.ok) throw new Error(j.error || 'candidates load failed');

    state.cast_candidates = Array.isArray(j.list) ? j.list : [];
  }

  function seatLabelById(id){
    id = Number(id||0);
    const m = Array.isArray(window.SEAT_MASTER) ? window.SEAT_MASTER : [];
    const hit = m.find(x => Number(x.id) === id);
    return hit ? String(hit.label) : '(未選択)';
  }
  function clampSeatId(id){
    id = Number(id||0);
    const m = Array.isArray(window.SEAT_MASTER) ? window.SEAT_MASTER : [];
    const ok = m.some(x => Number(x.id) === id);
    return ok ? id : 0;
  }
  function seatIsVip(id){
    id = Number(id||0);
    return id >= 100;
  }
  function seatOptionsHtml(selectedId){
    const list = Array.isArray(window.SEAT_MASTER) ? window.SEAT_MASTER : [];
    const sel = clampSeatId(selectedId);
    return list.map(s=>{
      const on = (Number(s.id) === sel) ? 'selected' : '';
      return `<option value="${Number(s.id)}" ${on}>${escapeHtml(String(s.label||''))}</option>`;
    }).join('');
  }
  function setSeatId(setIndex, seatId){
    const s = state.sets[setIndex];
    if (!s) return;

    const beforeSeat = clampSeatId(s.seat_id || 0);
    const afterSeat  = clampSeatId(seatId || 0);

    s.seat_id = afterSeat;

    if (typeof s.vip_auto_by_seat !== 'boolean') s.vip_auto_by_seat = false;

    if (seatIsVip(afterSeat)) {
      s.vip = true;
      s.vip_auto_by_seat = true;
      logEvent('vip_change', setIndex+1, { before: false, after: true, reason: 'seat_is_vip' });
    } else {
      if (s.vip_auto_by_seat) {
        s.vip = false;
        logEvent('vip_change', setIndex+1, { before: true, after: false, reason: 'seat_left_vip' });
      }
      s.vip_auto_by_seat = false;
    }

    logEvent('seat_change', setIndex+1, { before: beforeSeat, after: afterSeat, label: seatLabelById(afterSeat) });

    render(false);
  }

  function seatMoveAsNewSet(fromSetIdx, newSeatId){
    const from = state.sets[fromSetIdx];
    if (!from) return;

    const seatBefore = Number(from.seat_id || 0);

    const copied = deepClone(from);

    // ★ ここ、元コードに抜けがあったので修正（seatAfter 未定義）
    const seatAfter = clampSeatId(newSeatId || 0);
    copied.seat_id = seatAfter;
    applyVipBySeat(copied, seatAfter);

    copied.started_at = fmtHHMM(roundTo5Min(new Date()));
    copied.ends_at = null;
    copied.drinks = [];

    state.sets.push(copied);

    logEvent('seat_move_new_set', state.sets.length, {
      from_set_no: fromSetIdx+1,
      to_set_no: state.sets.length,
      seat_before: seatBefore,
      seat_after: seatAfter,
      vip: !!copied.vip,
      vip_auto_by_seat: !!copied.vip_auto_by_seat
    });

    state.ui.selected_set_index = state.sets.length - 1;
    syncSetTimes();
    render(true);
    requestSilentSave('seat_move_new_set');
  }

  function pad2(n){ return String(n).padStart(2,'0'); }
  function nowStr(){
    const d = new Date();
    return `${d.getFullYear()}-${pad2(d.getMonth()+1)}-${pad2(d.getDate())} ${pad2(d.getHours())}:${pad2(d.getMinutes())}:${pad2(d.getSeconds())}`;
  }
  function logEvent(type, setNo, detail){ state.history.push({t: nowStr(), type, setNo, detail}); }

  function timeToMin(t){
    const parts = String(t || "0:0").split(':');
    const h = parseInt(parts[0] || "0", 10);
    const m = parseInt(parts[1] || "0", 10);
    return (h*60 + m);
  }
  function minToHHMM(min){
    if (min < 0) min = 0;
    const h = Math.floor(min/60) % 24;
    const m = min % 60;
    return `${pad2(h)}:${pad2(m)}`;
  }
  function addMinutesHHMM(hhmm, minutes){ return minToHHMM(timeToMin(hhmm) + (minutes|0)); }
  function isAfterOrEq(hhmm, cut){ return timeToMin(hhmm) >= timeToMin(cut); }

  function roundTo5Min(date){
    const step = 5 * 60 * 1000;
    const rounded = Math.round(date.getTime() / step) * step;
    return new Date(rounded);
  }
  function fmtHHMM(date){ return `${pad2(date.getHours())}:${pad2(date.getMinutes())}`; }
  function hhmmToDateToday(hhmm){
    const [h,m] = String(hhmm || "00:00").split(':').map(x=>parseInt(x,10));
    const d = new Date();
    d.setSeconds(0,0);
    d.setHours(h||0, m||0, 0, 0);
    return d;
  }

  function freeSnapshot(setObj, custNo){
    const cust = getCustomer(setObj, custNo);
    const fr = cust?.free || {first:0, second:0, third:0, phase:'first'};
    return { first:fr.first||0, second:fr.second||0, third:fr.third||0, phase:fr.phase||'first' };
  }

  function buildFreeStatusText(setObj){
    normalizeCustomers(setObj);
    const guestN = Math.max(0, setObj.guest_people|0);
    if (!guestN) return 'ゲストなし';

    const lines = [];
    for (let c=1;c<=guestN;c++){
      const cust = setObj.customers?.[String(c)];
      if (!cust) continue;

      if (cust.mode === 'shimei'){
        lines.push(`客${c}: 指名モード`);
        continue;
      }

      const fr = cust.free || {};
      const ph = String(fr.phase||'first').toUpperCase();
      const cur = getCurrentAssignedCastNoForCustomer(setObj, c) || '-';

      lines.push(`客${c} PHASE:${ph} 今:${cur} F:${fr.first||'-'} S:${fr.second||'-'} T:${fr.third||'-'}`);
    }
    return lines.join('\n');
  }

  function normalizeCustomers(setObj){
    const n = Math.max(0, setObj.guest_people|0);
    if (!setObj.customers) setObj.customers = {};

    const candSet = new Set(getCandidateNos());

    for (let c=1; c<=n; c++){
      const k = String(c);
      if (!setObj.customers[k]) {
        setObj.customers[k] = { mode: 'free', shimei: {}, free: { first:0, second:0, third:0, phase:'first' } };
      }
      const cust = setObj.customers[k];

      cust.mode = (cust.mode === 'shimei') ? 'shimei' : 'free';

      if (!cust.free) cust.free = { first:0, second:0, third:0, phase:'first' };

      if (Array.isArray(cust.shimei)) {
        const m = {};
        cust.shimei.forEach(v=>{
          const no = parseInt(v,10);
          if (Number.isInteger(no) && no>=1 && no<900) m[String(no)]='jounai';
        });
        cust.shimei = m;
      }

      const nm = {};
      if (cust.shimei && typeof cust.shimei === 'object'){
        Object.keys(cust.shimei).forEach(no=>{
          const nno = parseInt(no,10);
          if (!Number.isInteger(nno) || nno<1 || nno>=900) return;
          if (candSet.size && !candSet.has(nno)) return;
          const kind = String(cust.shimei[no] || 'jounai');
          nm[String(nno)] = (kind === 'hon') ? 'hon' : 'jounai';
        });
      }
      cust.shimei = nm;

      ['first','second','third'].forEach(role=>{
        const v = parseInt(cust.free[role] || "0", 10);
        if (!(v>=1 && v<900)) { cust.free[role] = 0; return; }
        if (candSet.size && !candSet.has(v)) { cust.free[role] = 0; return; }
        cust.free[role] = v;
      });

      const ph = String(cust.free.phase || 'first');
      cust.free.phase = (ph==='first'||ph==='second'||ph==='third') ? ph : 'first';
    }

    Object.keys(setObj.customers).forEach(k=>{
      if (parseInt(k,10) > n) delete setObj.customers[k];
    });

    if (!setObj.customer_tab_selected) setObj.customer_tab_selected = 1;
    if (setObj.customer_tab_selected < 1) setObj.customer_tab_selected = 1;
    if (setObj.customer_tab_selected > n) setObj.customer_tab_selected = n || 1;

    setObj.seat_id = clampSeatId(setObj.seat_id || 0);
  }

  function getCustomer(setObj, custNo){
    normalizeCustomers(setObj);
    return setObj.customers[String(custNo)] || null;
  }

  function isLocked(phase, role){
    if (phase === 'second' && role === 'first') return true;
    if (phase === 'third' && (role === 'first' || role === 'second')) return true;
    return false;
  }

  function toggleFreeRoleForCustomer(setIndex, custNo, role, castNo){
    const s = state.sets[setIndex];
    const cust = getCustomer(s, custNo);
    if (!cust || cust.mode !== 'free') return;
    if (isLocked(cust.free.phase, role)) return;

    const snapBefore = freeSnapshot(s, custNo);

    const before = cust.free[role] || 0;
    cust.free[role] = (before === castNo) ? 0 : castNo;

    const snapAfter = freeSnapshot(s, custNo);

    logEvent('free_role_toggle', setIndex+1, {
      custNo, role, castNo,
      before, after: cust.free[role],
      free_before: snapBefore,
      free_after: snapAfter
    });

    render(false);
  }

  function resolveFreeToCast(setObj, custNo, role){
    const cust = getCustomer(setObj, custNo);
    if (!cust || cust.mode !== 'free') return 0;
    const no = parseInt((cust.free && cust.free[role]) || "0", 10);
    return (no>=1 && no<900) ? no : 0;
  }

  function getCurrentAssignedCastNoForCustomer(setObj, custNo){
    const cust = getCustomer(setObj, custNo);
    if (!cust || cust.mode !== 'free') return 0;
    const phase = (cust.free && cust.free.phase) ? cust.free.phase : 'first';
    const no = parseInt((cust.free && cust.free[phase]) || "0", 10);
    return (no>=1 && no<900) ? no : 0;
  }

  function setCustomerMode(setObj, custNo, mode){
    normalizeCustomers(setObj);
    const cust = setObj.customers[String(custNo)];
    if (!cust) return;

    const before = cust.mode;
    cust.mode = (mode === 'shimei') ? 'shimei' : 'free';
    if (cust.mode === 'free') cust.shimei = {};

    logEvent('customer_mode_change', state.ui.selected_set_index+1, {custNo, before, after: cust.mode});
  }
  function cycleCustomerShimei(setObj, custNo, castNo){
    normalizeCustomers(setObj);
    const cust = setObj.customers[String(custNo)];
    if (!cust) return;

    if (cust.mode !== 'shimei') cust.mode = 'shimei';

    const key = String(castNo);
    const cur = cust.shimei[key] || null;

    let next = null;
    if (cur === null) next = 'jounai';
    else if (cur === 'jounai') next = 'hon';
    else if (cur === 'hon') next = null;

    const before = cur;
    if (next === null) delete cust.shimei[key];
    else cust.shimei[key] = next;

    logEvent('customer_shimei_cycle', state.ui.selected_set_index+1, {custNo, castNo, before, after: next});
    render(false);
  }

  function enforceKindRules(){
    state.sets.forEach((s, idx)=>{
      if (!s.kind) s.kind = 'normal50';
      if (!KIND[s.kind]) s.kind = 'normal50';
      if (idx >= 1 && s.kind === 'pack_douhan') s.kind = 'normal50';

      if (idx === 0) {
        const st = s.started_at || (state.timer.base_start_hhmm || startTimeEl.value || "20:00");
        if (s.kind === 'pack_douhan' && isAfterOrEq(st, FORCE_NORMAL_AFTER)) s.kind = 'normal50';
      }
    });
  }

  function promoteJounaiToHonAfterNormal50(){
    if (!state.sets || state.sets.length < 2) return;

    for (let i = 1; i < state.sets.length; i++){
      const prev = state.sets[i - 1];
      const cur  = state.sets[i];
      if (!prev || !cur) continue;

      const curKind = (cur.kind || 'normal50');
      if (curKind === 'pack_douhan') continue;

      normalizeCustomers(prev);
      normalizeCustomers(cur);

      const guest = Math.min(
        Math.max(0, prev.guest_people | 0),
        Math.max(0, cur.guest_people  | 0)
      );
      if (guest <= 0) continue;

      for (let c = 1; c <= guest; c++){
        const p = prev.customers?.[String(c)];
        const x = cur.customers?.[String(c)];
        if (!p || !x) continue;
        if (p.mode !== 'shimei' || x.mode !== 'shimei') continue;

        const pMap = (p.shimei && typeof p.shimei === 'object') ? p.shimei : {};
        const xMap = (x.shimei && typeof x.shimei === 'object') ? x.shimei : {};

        Object.keys(pMap).forEach(no=>{
          if (pMap[no] !== 'jounai') return;
          if (xMap[no] == null) return;
          xMap[no] = 'hon';
        });

        x.shimei = xMap;
      }
    }
  }

  function syncSetTimes(){
    if (!state.sets.length) return;

    let base = (state.timer.base_start_hhmm || startTimeEl.value || "20:00");
    if (!state.sets[0].started_at) state.sets[0].started_at = base;

    enforceKindRules();

    if (state.sets[0].kind === 'pack_douhan') {
      state.sets[0].started_at = PACK_START;
      state.sets[0].ends_at    = PACK_END;
      startTimeEl.value = PACK_START;

      let cur = PACK_END;
      for (let i=1;i<state.sets.length;i++){
        if (state.sets[i].kind === 'pack_douhan') state.sets[i].kind = 'normal50';
        if (!KIND[state.sets[i].kind]) state.sets[i].kind = 'normal50';
        state.sets[i].started_at = cur;
        state.sets[i].ends_at    = addMinutesHHMM(cur, KIND[state.sets[i].kind].dur);
        cur = state.sets[i].ends_at;
      }
      promoteJounaiToHonAfterNormal50();
      return;
    }

    state.sets[0].started_at = base;
    state.sets[0].ends_at    = addMinutesHHMM(base, KIND[state.sets[0].kind].dur);

    let cur = state.sets[0].ends_at;

    for (let i=1;i<state.sets.length;i++){
      if (!state.sets[i].kind || !KIND[state.sets[i].kind]) state.sets[i].kind = 'normal50';
      if (state.sets[i].kind === 'pack_douhan') state.sets[i].kind = 'normal50';
      state.sets[i].started_at = cur;
      state.sets[i].ends_at    = addMinutesHHMM(cur, KIND[state.sets[i].kind].dur);
      cur = state.sets[i].ends_at;
    }
    promoteJounaiToHonAfterNormal50();
  }

  function deepClone(obj){ return JSON.parse(JSON.stringify(obj || null)); }

  function addSet({guest_people=null, vip=false, drinks=[], carryCustomers=true, resetFreeRotation=false} = {}){
    const prev = state.sets[state.sets.length - 1];
    const gp = (guest_people != null) ? guest_people : (prev ? prev.guest_people : 2);

    const prevSeatId = prev ? Number(prev.seat_id || 0) : 0;

    const setObj = {
      seat_id: clampSeatId(prevSeatId),
      kind: 'normal50',
      guest_people: Math.max(0, gp|0),
      vip: seatIsVip(prevSeatId),
      vip_auto_by_seat: seatIsVip(prevSeatId),
      drinks: [],
      customers: {},
      customer_tab_selected: 1,
      started_at: null,
      ends_at: null,
    };

    if (carryCustomers && prev && prev.customers) {
      setObj.customers = deepClone(prev.customers);
      setObj.customer_tab_selected = Math.min(prev.customer_tab_selected || 1, Math.max(1, setObj.guest_people));
      setObj.kind = 'normal50';
    }

    if (resetFreeRotation) {
      normalizeCustomers(setObj);
      const guestN = Math.max(0, setObj.guest_people|0);

      const before = {};
      for (let c=1; c<=guestN; c++){
        const cust = setObj.customers[String(c)];
        if (!cust || cust.mode !== 'free') continue;
        before[String(c)] = freeSnapshot(setObj, c);
        cust.free = { first:0, second:0, third:0, phase:'first' };
      }

      logEvent('free_rotation_reset', state.sets.length + 1, {
        reason: 'addSet_resetFreeRotation',
        before
      });
    }

    state.sets.push(setObj);
    normalizeCustomers(setObj);
    syncSetTimes();
    return setObj;
  }

    function setLadiesPeople(setIndex, val){
      const s = state.sets[setIndex];
      const before = s.ladies_people|0;

      const guestN = Math.max(0, s.guest_people|0);
      const next = Math.max(0, Math.min(guestN, parseInt(val || "0", 10) || 0));

      s.ladies_people = next;

      if (before !== next){
        logEvent('ladies_people_change', setIndex+1, {before, after: next, guest_people: guestN});
      }

      render(true);
    }

  function setGuestPeople(setIndex, val){
    const s = state.sets[setIndex];

    const before = s.guest_people|0;
    const beforeLadies = s.ladies_people|0;

    s.guest_people = Math.max(0, parseInt(val || "0", 10) || 0);

    // ★追加：ladies_people は 0〜guest_people に収める（事故防止）
    if (s.ladies_people == null) s.ladies_people = 0;
    s.ladies_people = Math.max(0, Math.min(s.guest_people|0, (s.ladies_people|0)));

    normalizeCustomers(s);

    if (before !== (s.guest_people|0)) {
      logEvent('guest_people_change', setIndex+1, {before, after:s.guest_people});
    }

    // ★追加：ゲスト人数変更で ladies が変わった場合もログ（任意だけど便利）
    if (beforeLadies !== (s.ladies_people|0)) {
      logEvent('ladies_people_clamp', setIndex+1, {before: beforeLadies, after: s.ladies_people, by_guest_people: s.guest_people});
    }

    render(true);
  }
  function setVip(setIndex, on){
    const s = state.sets[setIndex];
    const before = s.vip;
    s.vip = !!on;
    if (before !== s.vip) logEvent('vip_change', setIndex+1, {before, after:s.vip});
    render(false);
  }
  function setKindForSet(setIndex, kind){
    if (!KIND[kind]) return;
    const s = state.sets[setIndex];
    const before = s.kind;
    s.kind = kind;
    if (setIndex >= 1 && s.kind === 'pack_douhan') s.kind = 'normal50';
    if (setIndex === 0 && s.kind === 'pack_douhan') startTimeEl.value = PACK_START;

    logEvent('set_kind_change', setIndex+1, {before, after:s.kind});
    syncSetTimes();
    render(true);
    requestSilentSave('set_kind_change');
  }
  function selectSetTab(setIndex){
    const before = state.ui.selected_set_index;
    state.ui.selected_set_index = setIndex;
    logEvent('set_tab_select', setIndex+1, {before: before+1, after: setIndex+1});
    render(false);
  }
  function selectCustomerTab(setIndex, custNo){
    const s = state.sets[setIndex];
    const before = s.customer_tab_selected;
    s.customer_tab_selected = custNo;
    normalizeCustomers(s);
    logEvent('customer_tab_select', setIndex+1, {before, after:s.customer_tab_selected});
    render(false);
  }
  function selectWorkTab(tabKey){
    const allow = new Set(['set','customer','drink']);
    const next = allow.has(tabKey) ? tabKey : 'set';
    state.ui.work_tab = next;
    render(false);
  }

  function addDrink(setIndex, amount, payerType, payerId, meta=null){
    amount = Math.max(0, parseInt(amount || "0", 10));
    if (!amount) return;
    const s = state.sets[setIndex];
    s.drinks.push({amount, payer_type:payerType, payer_id:String(payerId), meta: meta||null});
    logEvent('drink_add', setIndex+1, {amount, payer_type:payerType, payer_id:String(payerId), meta});
    render(false);
  }
  function removeDrink(setIndex, drinkIndex){
    const s = state.sets[setIndex];
    const d = s.drinks[drinkIndex];
    s.drinks.splice(drinkIndex, 1);
    logEvent('drink_remove', setIndex+1, d);
    render(false);
  }

  function buildPayerOptions(setObj){
    normalizeCustomers(setObj);
    const guestN = Math.max(1, setObj.guest_people|0);

    const groups = [];
    groups.push({
      label:'客（誰が頼んだ）',
      items: Array.from({length: guestN}, (_,i)=>({v:`cust:${i+1}`, t:`客${i+1}`}))
    });

    const shimeiItems = [];
    for (let c=1; c<=guestN; c++){
      const cust = setObj.customers[String(c)];
      if (!cust) continue;
      if (cust.mode === 'shimei' && cust.shimei && typeof cust.shimei === 'object'){
        Object.keys(cust.shimei).forEach(no=>{
          const knd = cust.shimei[no];
          const tag = (knd === 'hon') ? '★本' : '○場内';
          shimeiItems.push({v:`shimei:${no}`, t:`客${c} ${tag} 店番${no}`});
        });
      }
    }
    if (shimeiItems.length) groups.push({label:'指名（客ごと）評価', items: shimeiItems});

    const freeItems = [];
    for (let c=1;c<=guestN;c++){
      const cust = setObj.customers[String(c)];
      if (!cust || cust.mode !== 'free') continue;
      const fr = cust.free;
      if (fr.first)  freeItems.push({v:`free:${c}:first`,  t:`客${c} First 店番${fr.first}`});
      if (fr.second) freeItems.push({v:`free:${c}:second`, t:`客${c} Second 店番${fr.second}`});
      if (fr.third)  freeItems.push({v:`free:${c}:third`,  t:`客${c} Third 店番${fr.third}`});
    }
    if (freeItems.length) groups.push({label:'フリー席付き（評価）', items: freeItems});

    const currentItems = [];
    for (let c=1;c<=guestN;c++){
      const cust = setObj.customers[String(c)];
      if (!cust || cust.mode !== 'free') continue;
      const no = getCurrentAssignedCastNoForCustomer(setObj, c);
      if (no) {
        const phase = cust.free.phase || 'first';
        currentItems.push({v:`free:${c}:${phase}`, t:`★客${c} 今(${phase.toUpperCase()}) 店番${no}`});
      }
    }
    if (currentItems.length) groups.unshift({label:'今最後に付いてる（最優先）', items: currentItems});

    return groups;
  }

  function kindToTimerLabel(kind){
    if (kind === 'pack_douhan') return '同伴';
    if (kind === 'half25') return 'ハーフ';
    return '通常';
  }
  function startTimer(){
    if (!startTimeEl.value){
      startTimeEl.value = fmtHHMM(roundTo5Min(new Date()));
    }
    if (state.sets[0] && state.sets[0].kind === 'pack_douhan') startTimeEl.value = PACK_START;

    state.timer.base_start_hhmm = startTimeEl.value;
    state.timer.running = true;

    if (!state.sets.length) addSet({guest_people:2});
    syncSetTimes();

    if (state.timer.interval_id) clearInterval(state.timer.interval_id);
    state.timer.interval_id = setInterval(updateTimerUI, 1000);
    updateTimerUI();

    logEvent('timer_start', 0, {base_start: state.timer.base_start_hhmm});
    requestSilentSave('timer_start');
  }
  function stopTimer(clearStart=true){
    if (state.timer.interval_id) {
      clearInterval(state.timer.interval_id);
      state.timer.interval_id = null;
    }
    state.timer.running = false;
    if (clearStart) state.timer.base_start_hhmm = null;
    updateTimerUI();
    if (clearStart) logEvent('timer_stop', 0, {});
    requestSilentSave('timer_stop');
  }
  function updateTimerUI(){
    if (!state.timer.running || !state.timer.base_start_hhmm){
      timerInfoEl.textContent = '未開始';
      timerRemainEl.textContent = '—';
      timerEndsEl.textContent = '—';
      warnBoxEl.classList.remove('on');
      return;
    }

    syncSetTimes();
    const last = state.sets[state.sets.length - 1];
    const regEndHHMM = last?.ends_at || '—';

    const base = hhmmToDateToday(state.timer.base_start_hhmm);
    const now = new Date();
    const elapsedMin = Math.floor((now.getTime() - base.getTime()) / (60*1000));

    timerInfoEl.textContent = `開始 ${state.timer.base_start_hhmm} / 経過 ${elapsedMin} 分`;
    timerEndsEl.textContent = `登録 ${state.sets.length} セット終了予定 ${regEndHHMM}`;

    let curSetIdx = 0;
    for (let i=0;i<state.sets.length;i++){
      const s = state.sets[i];
      if (!s.started_at || !s.ends_at) continue;
      const nowHH = fmtHHMM(now);
      if (timeToMin(s.started_at) <= timeToMin(nowHH) && timeToMin(nowHH) < timeToMin(s.ends_at)) {
        curSetIdx = i;
        break;
      }
    }
    const cur = state.sets[curSetIdx];
    if (cur && cur.started_at && cur.ends_at) {
      const remain = timeToMin(cur.ends_at) - timeToMin(fmtHHMM(now));
      const label = kindToTimerLabel(cur.kind);
      timerRemainEl.textContent = `現在 セット${curSetIdx+1}（${label}）残 ${remain} 分`;
      if (remain <= 10 && remain > 0) warnBoxEl.classList.add('on');
      else warnBoxEl.classList.remove('on');
    } else {
      timerRemainEl.textContent = '—';
      warnBoxEl.classList.remove('on');
    }
  }

  function calcPtByKind(effKind, shMap){
    const ptDouhan = {};
    const ptShimei = {};

    if (effKind === 'pack_douhan') {
      Object.keys(shMap).forEach(no=>{
        ptDouhan[no] = (ptDouhan[no]||0) + 1.0;
        ptShimei[no] = (ptShimei[no]||0) + 1.5;
      });
    } else if (effKind === 'half25') {
      Object.keys(shMap).forEach(no=>{
        ptShimei[no] = (ptShimei[no]||0) + 0.5;
      });
    } else {
      Object.keys(shMap).forEach(no=>{
        const k = (shMap[no] === 'hon') ? 1.0 : 0.5;
        ptShimei[no] = (ptShimei[no]||0) + k;
      });
    }
    return { ptDouhan, ptShimei };
  }

  function kindToLabel(kind){
    if (kind === 'pack_douhan') return '同伴';
    if (kind === 'half25') return 'ハーフ';
    return '通常';
  }

  function computePreview(){
    const taxRate = 0.10;
    const cast = { shimei_fee:{}, drink_sales:{}, pt:{} };
    const cust = { drink_sales:{} };
    let setTotal=0, vipTotal=0, shimeiTotal=0, drinkTotal=0;

    syncSetTimes();

    const rows = state.sets.map((s, idx)=>{
      normalizeCustomers(s);
      const kind = s.kind || 'normal50';
      const guest = Math.max(0, s.guest_people|0);

      const shMap = {};
      for (let c=1;c<=guest;c++){
        const co = s.customers[String(c)];
        if (!co) continue;
        if (co.mode === 'shimei' && co.shimei && typeof co.shimei === 'object'){
          Object.keys(co.shimei).forEach(no=>{
            const knd = (co.shimei[no] === 'hon') ? 'hon' : 'jounai';
            if (shMap[no] === 'hon') return;
            shMap[no] = knd;
          });
        }
      }
      const shimeiList = Object.keys(shMap).map(n=>parseInt(n,10)).filter(n=>n>=1&&n<900).sort((a,b)=>a-b);
      const shimeiCount = shimeiList.length;

      let effKind = kind;
      if (effKind === 'pack_douhan' && shimeiCount === 0) effKind = 'normal50';

      const charge = Math.max(guest, shimeiCount);

      function normal50PriceByStart(hhmm){
        if (hhmm >= '20:00' && hhmm < '20:30') return 6000;
        return 7000;
      }

      let unit = KIND[effKind].price;
      if (effKind === 'normal50') unit = normal50PriceByStart(s.started_at || (startTimeEl.value || "20:00"));

      // ★ ladies（サーバと同じ式）
      const ladiesUnit = 3500;

      // set内 ladies_people を使う（0〜guestにクランプ）
      let ladiesPeople = (s.ladies_people|0);
      ladiesPeople = Math.max(0, Math.min(guest, ladiesPeople));

      // 課金人数は従来通り max(来店, 指名ユニーク)
      const normalPeople = Math.max(0, charge - ladiesPeople);

      // セット料金
      const setFee = (unit * normalPeople) + (ladiesUnit * ladiesPeople);

      const vipFee = s.vip ? 10000 : 0;

      let shimeiFee = 0;
      if (shimeiCount > 0 && effKind !== 'pack_douhan'){
        const per = (effKind === 'half25') ? 500 : 1000;
        shimeiFee = per * shimeiCount;

        const each = shimeiFee / shimeiCount;
        shimeiList.forEach(no=>{
          const k = String(no);
          cast.shimei_fee[k] = (cast.shimei_fee[k]||0) + each;
        });
      }

      const { ptDouhan, ptShimei } = calcPtByKind(effKind, shMap);
      const allPtKeys = Array.from(new Set([...Object.keys(ptDouhan), ...Object.keys(ptShimei)]));
      allPtKeys.forEach(no=>{
        if (!cast.pt[no]) cast.pt[no] = {douhan:0, shimei:0, total:0};
        cast.pt[no].douhan += (ptDouhan[no]||0);
        cast.pt[no].shimei += (ptShimei[no]||0);
        cast.pt[no].total  += (ptDouhan[no]||0) + (ptShimei[no]||0);
      });

      let drinkSum = 0;
      (s.drinks||[]).forEach(d=>{
        const amount = Math.max(0, d.amount|0);
        if (!amount) return;
        drinkSum += amount;

        if (d.payer_type === 'cust'){
          const k = 'cust' + String(d.payer_id);
          cust.drink_sales[k] = (cust.drink_sales[k]||0) + amount;
        } else if (d.payer_type === 'shimei'){
          const k = String(d.payer_id);
          cast.drink_sales[k] = (cast.drink_sales[k]||0) + amount;
        } else if (d.payer_type === 'free'){
          const parts = String(d.payer_id).split(':');
          const cNo = parseInt(parts[0] || "0", 10);
          const role = parts[1] || '';
          const no = resolveFreeToCast(s, cNo, role);
          if (no){
            const k = String(no);
            cast.drink_sales[k] = (cast.drink_sales[k]||0) + amount;
          }
        }
      });
      const sub = setFee + vipFee + shimeiFee + drinkSum;

      setTotal += setFee;
      vipTotal += vipFee;
      shimeiTotal += shimeiFee;
      drinkTotal += drinkSum;

      return {
        no: idx+1,
        kind: effKind,
        unit,
        started_at: s.started_at || '—',
        ends_at: s.ends_at || '—',
        guest,
        ladiesPeople,
        normalPeople,
        shimeiMap: shMap,
        shimeiCount,
        charge,
        vip: s.vip,
        setFee,
        vipFee,
        shimeiFee,
        drinkSum,
        sub,
        pt: {douhan: ptDouhan, shimei: ptShimei}
      };
    });

    const discount = Math.max(0, parseInt(discountEl.value || "0", 10));
    const subtotalEx = Math.max(0, setTotal + vipTotal + shimeiTotal + drinkTotal - discount);
    const tax = Math.round(subtotalEx * taxRate);
    const total = subtotalEx + tax;

    return {
      rows,
      cast,
      cust,
      grand:{ subtotalEx, tax, total, discount, setTotal, vipTotal, shimeiTotal, drinkTotal }
    };
  }

  function renderPreview(res){
    const g = res.grand;

    previewEl.innerHTML = `
      <div class="muted" style="margin-bottom:8px;">
        開始時刻: <b>${(startTimeEl.value || '—')}</b><br>
        <span class="small">※ 20:00〜20:29開始＝6000円 / 20:30以降開始＝7000円</span>
      </div>

      <div class="row2">
        <div><span class="pill">税別小計</span><div style="font-weight:900;font-size:18px;">${g.subtotalEx.toLocaleString()}円</div></div>
        <div><span class="pill">消費税</span><div style="font-weight:900;font-size:18px;">${g.tax.toLocaleString()}円</div></div>
        <div><span class="pill">税込合計</span><div style="font-weight:900;font-size:22px;">${g.total.toLocaleString()}円</div></div>
        <div><span class="pill">割引</span><div style="font-weight:900;font-size:18px;">${g.discount.toLocaleString()}円</div></div>
      </div>

      <div class="hr"></div>
      <div class="muted">
        セット ${g.setTotal.toLocaleString()}
        / VIP ${g.vipTotal.toLocaleString()}
        / 指名 ${g.shimeiTotal.toLocaleString()}
        / DR ${g.drinkTotal.toLocaleString()}
      </div>
    `;

    previewDetailEl.innerHTML = `
      <h2>セット別</h2>
      <div class="tableWrap">
        <table class="setTable">
          <thead>
            <tr>
              <th class="center">時間</th>
              <th class="center">#</th>
              <th>種別</th>
              <th class="num">人数</th>
              <th>指名</th>
              <th class="center">税別小計</th>
            </tr>
          </thead>
          <tbody>
            ${res.rows.map(r=>{
              const shHtml = Object.keys(r.shimeiMap||{}).sort((a,b)=>parseInt(a)-parseInt(b)).map(no=>{
                const knd = r.shimeiMap[no];
                return `<span class="shTag ${knd==='hon'?'shH':'shJ'}">${knd==='hon'?'★':'○'}${no}</span>`;
              }).join('') || '<span class="muted">なし</span>';

              return `
                <tr class="${r.vip ? 'vipOn':''}">
                  <td class="center"><div class="badgeMini">${r.started_at} → ${r.ends_at}</div></td>
                  <td class="center key">${r.no}</td>
                  <td>${kindToLabel(r.kind)}<div class="muted small">単価 ${r.unit.toLocaleString()}円</div></td>
                  <td class="num">
                    ${(() => {
                      const guest  = Number(r.guest || 0);
                      const shimei = Number(r.shimeiCount || 0);
                      const ladies = Number(r.ladiesPeople || 0);
                      const free   = Math.max(0, guest - shimei - ladies);

                      return `
                        <div>来店数 ${guest}</div>
                        ${free > 0 ? `<div class="freeLine">FREE ${free}</div>` : ``}
                        <div>指名 ${shimei}</div>
                        <div>L ${ladies}</div>
                        <div class="key">課金 ${r.charge}</div>
                      `;
                    })()}
                  </td>
                  <td>${shHtml}</td>
                  <td class="money key"><strong>${r.sub.toLocaleString()}</strong></td>
                </tr>
              `;
            }).join('')}
          </tbody>
        </table>
      </div>
    `;

    const ptEntries = Object.entries(res.cast.pt || {})
      .map(([no, v])=>({no: parseInt(no,10), ...v}))
      .filter(x=>x.no>=1 && x.no<900)
      .sort((a,b)=>a.no-b.no);

    previewPtEl.innerHTML = `
      <h2>pt（ブラウザ計算）</h2>
      ${ptEntries.length ? `
        <div style="display:flex;flex-wrap:wrap;gap:6px;">
          ${ptEntries.map(x=>`<span class="badgeMini">店番${x.no}：同伴${x.douhan} / 指名${x.shimei} / 合計${x.total}</span>`).join('')}
        </div>
      ` : `<div class="muted">pt なし</div>`}
    `;

    previewCard.style.display = '';
  }

  function buildPayload(){
    // 先に時間同期は1回でOK（forEach内で毎回syncしない）
    syncSetTimes();

    state.sets.forEach(s=>{
      normalizeCustomers(s);

      // legacy互換：free_by_cust を作る
      const fb = {};
      const guestN = Math.max(0, s.guest_people|0);
      for (let c=1;c<=guestN;c++){
        const cust = s.customers[String(c)];
        if (!cust || cust.mode !== 'free') continue;
        const fr = cust.free || {};
        fb[String(c)] = { first:fr.first||0, second:fr.second||0, third:fr.third||0, phase:fr.phase||'first' };
      }
      s.free_by_cust = fb;
      delete s.shimei;

      // ✅ ladies_people はセット内に保持しているので、そのままでOK
      // 念のためクランプ
      if (s.ladies_people == null) s.ladies_people = 0;
      s.ladies_people = Math.max(0, Math.min((s.guest_people|0), (s.ladies_people|0)));
    });

    return {
      start_time: startTimeEl.value || "20:00",
      discount: parseInt(discountEl.value||"0",10) || 0,
      sets: state.sets,
      history: state.history,
    };
  }

  function openFreeHistoryWindow(){
    const payload = buildPayload();
    const html = `
    <!doctype html>
    <html lang="ja"><head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>FREE履歴（Ticket ${TICKET_ID||''}）</title>
      <style>
        body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;padding:16px;}
        .muted{color:#666;font-size:12px;}
        pre{white-space:pre-wrap;background:#f6f7fb;padding:12px;border-radius:10px;}
      </style>
    </head>
    <body>
      <h2>FREE履歴（Ticket ${TICKET_ID||''}）</h2>
      ${formatFreeHistory(payload.history)}
      <h3>raw</h3>
      <pre>${escapeHtml(JSON.stringify(payload.history, null, 2))}</pre>
    </body></html>`;

    const w = window.open('', '_blank', 'width=900,height=700');
    if (!w) { alert('ポップアップがブロックされました'); return; }
    w.document.open();
    w.document.write(html);
    w.document.close();
  }

  function formatFreeHistory(history){
    const rows = (history || []).filter(h=>{
      return ['free_role_toggle','free_phase_advance','free_phase_back','free_rotation_reset','free_phase_set'].includes(h.type);
    });

    if (!rows.length) return '<div class="muted">FREE履歴なし</div>';

    const custSet = new Set();
    const setSet  = new Set();
    rows.forEach(h=>{
      setSet.add(Number(h.setNo||0));
      const d = h.detail || {};
      if (d.custNo) custSet.add(Number(d.custNo));
    });

    const custNos = Array.from(custSet).filter(n=>n>=1).sort((a,b)=>a-b);
    const setNos  = Array.from(setSet).filter(n=>n>=1).sort((a,b)=>a-b);

    const custColor = (c)=>{
      const hues = [210, 20, 120, 280, 45, 170, 320, 90];
      const h = hues[(c-1) % hues.length];
      return `hsl(${h} 80% 95%)`;
    };

    const fmt = (h)=>{
      const t = h.t || '';
      const setNo = h.setNo || '';
      const d = h.detail || {};

      if (h.type === 'free_rotation_reset'){
        return {custNo:0, setNo, time:t, kind:'reset', text:'🔄 FREE回転リセット'};
      }

      if (h.type === 'free_phase_advance' || h.type === 'free_phase_back' || h.type === 'free_phase_set'){
        const custNo = Number(d.custNo||0);
        const before = String(d.before||'').toUpperCase();
        const after  = String(d.after||'').toUpperCase();
        return {custNo, setNo, time:t, kind:'phase', text:`PHASE ${before}→${after}`};
      }

      if (h.type === 'free_role_toggle'){
        const custNo = Number(d.custNo||0);
        const role = String(d.role||'').toUpperCase();
        const b = (d.before ?? '');
        const a = (d.after  ?? '');
        const ph = d.free_after?.phase ? String(d.free_after.phase).toUpperCase() : '';
        return {custNo, setNo, time:t, kind:'role', text:`${role} ${b}→${a}（${ph}）`};
      }

      return {custNo:0, setNo, time:t, kind:'other', text:h.type};
    };

    const table = {};

    function pushCell(setNo, custNo, col, text){
      const s = String(setNo);
      const c = String(custNo);
      if (!table[s]) table[s] = {};
      if (!table[s][c]) table[s][c] = {FIRST:[], SECOND:[], THIRD:[], LOG:[]};
      table[s][c][col].push(text);
    }

    rows.map(fmt).forEach(x=>{
      const setNo = Number(x.setNo||0);
      if (!setNo) return;

      if (x.kind === 'reset'){
        pushCell(setNo, 0, 'LOG', `${x.time} ${x.text}`);
        return;
      }

      if (!x.custNo) return;

      if (x.kind === 'phase'){
        pushCell(setNo, x.custNo, 'LOG', `${x.time} ${x.text}`);
        return;
      }

      if (x.kind === 'role'){
        const m = x.text.match(/^(FIRST|SECOND|THIRD)/);
        const col = m ? m[1] : 'LOG';
        pushCell(setNo, x.custNo, col, `${x.time} ${x.text}`);
        return;
      }

      pushCell(setNo, x.custNo, 'LOG', `${x.time} ${x.text}`);
    });

    const head = `
      <style>
        .freeGridWrap{border:1px solid #eee;border-radius:12px;overflow:hidden}
        table.freeGrid{width:100%;border-collapse:separate;border-spacing:0;font-size:13px}
        .freeGrid th,.freeGrid td{border-bottom:1px solid #f0f0f0;padding:10px 8px;vertical-align:top}
        .freeGrid thead th{position:sticky;top:0;background:#fafafa;z-index:2}
        .freeGrid .setHead{background:#111;color:#fff;font-weight:300}
        .freeGrid .custHead{font-weight:900;white-space:nowrap}
        .freeGrid .cell{white-space:pre-wrap;line-height:1.35}
        .freeGrid .log{color:#444}
        .freeGrid .resetRow td{background:#fff6db}
        .freeGrid .muted{color:#777;font-size:12px}
        .colFirst{background:rgba(46,107,220,.04)}
        .colSecond{background:rgba(44,157,111,.04)}
        .colThird{background:rgba(106,70,214,.04)}
      </style>
    `;

    const blocks = setNos.map(setNo=>{
      const sKey = String(setNo);
      const custRows = custNos.map(custNo=>{
        const cKey = String(custNo);
        const cell = (col)=>{
          const arr = table?.[sKey]?.[cKey]?.[col] || [];
          if (!arr.length) return `<div class="muted">—</div>`;
          return `<div class="cell">${escapeHtml(arr.join('\n'))}</div>`;
        };
        return `
          <tr>
            <td class="custHead" style="background:${custColor(custNo)}">客${custNo}</td>
            <td class="colFirst">${cell('FIRST')}</td>
            <td class="colSecond">${cell('SECOND')}</td>
            <td class="colThird">${cell('THIRD')}</td>
            <td class="log">${cell('LOG')}</td>
          </tr>
        `;
      }).join('');

      const resetLogs = (table?.[sKey]?.['0']?.LOG || []);
      const resetRow = resetLogs.length ? `
        <tr class="resetRow">
          <td class="custHead">全体</td>
          <td colspan="4"><div class="cell">${escapeHtml(resetLogs.join('\n'))}</div></td>
        </tr>
      ` : '';

      return `
        <div class="freeGridWrap" style="margin:12px 0;">
          <table class="freeGrid">
            <thead>
              <tr><th class="setHead" colspan="3">セット${setNo}</th></tr>
              <tr>
                <th style="width:90px;">客</th>
                <th>FIRST</th>
                <th>SECOND</th>
                <th>THIRD</th>
                <th>PHASE/ログ</th>
              </tr>
            </thead>
            <tbody>
              ${resetRow}
              ${custRows || `<tr><td colspan="5" class="muted">このセットにFREE操作がありません</td></tr>`}
            </tbody>
          </table>
        </div>
      `;
    }).join('');

    return head + blocks;
  }

  function escapeHtml(s){
    return String(s)
      .replaceAll('&','&amp;')
      .replaceAll('<','&lt;')
      .replaceAll('>','&gt;')
      .replaceAll('"','&quot;')
      .replaceAll("'","&#039;");
  }

  function showServerError(msg){
    serverErrEl.textContent = msg || 'サーバ計算に失敗しました';
    serverErrCard.style.display = '';
    serverCard.style.display = 'none';
  }

  function renderServerResult(result){
    serverErrCard.style.display = 'none';

    const g = result.grand || {};
    serverSummaryEl.innerHTML = `
      <div class="row2">
        <div><span class="pill">税別小計</span><div style="font-weight:900;font-size:18px;">${Number(g.subtotal_ex_tax||0).toLocaleString()}円</div></div>
        <div><span class="pill">消費税</span><div style="font-weight:900;font-size:18px;">${Number(g.tax||0).toLocaleString()}円</div></div>
        <div><span class="pill">税込合計</span><div style="font-weight:900;font-size:22px;">${Number(g.total||0).toLocaleString()}円</div></div>
        <div><span class="pill">割引</span><div style="font-weight:900;font-size:18px;">${Number(g.discount||0).toLocaleString()}円</div></div>
      </div>
      <div class="hr"></div>
      <div class="muted">
        セット ${Number(g.set_total||0).toLocaleString()}
        / VIP ${Number(g.vip_total||0).toLocaleString()}
        / 指名 ${Number(g.shimei_total||0).toLocaleString()}
        / DR ${Number(g.drink_total||0).toLocaleString()}
      </div>
    `;

    serverPtEl.innerHTML = ``;
    serverHistoryEl.innerHTML = ``;

    serverCard.style.display = '';
  }

  async function calcOnServer(){
    const payload = buildPayload();

    // ★ haruto_core版：JSON API に寄せる（/public/api/ticket_calc.php がある前提）
    const r = await fetch('/wbss/public/api/ticket_calc.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      credentials:'same-origin',
      body: JSON.stringify({ payload }),
    });

    const txt = await r.text();
    if (!r.ok) throw new Error(`HTTP ${r.status}: ${txt.slice(0, 300)}`);
    if (!txt.trim()) throw new Error('Empty response');

    let j;
    try { j = JSON.parse(txt); } catch (e) { throw new Error('Non-JSON response: ' + txt.slice(0, 300)); }
    if (!j.ok) throw new Error(j.error || 'calc failed');

    // ★ あなたの haruto_core 版 ticket_calc.php は {ok:true, bill:{...}} のはずなので吸収
    if (j.result) {
      renderServerResult(j.result);
      return j.result;
    }
    if (j.bill) {
      renderServerResult({ grand:{
        subtotal_ex_tax: j.bill.subtotal_ex ?? 0,
        tax: j.bill.tax ?? 0,
        total: j.bill.total ?? 0,
        discount: j.bill.discount ?? 0,
        set_total: j.bill.set_total ?? 0,
        vip_total: j.bill.vip_total ?? 0,
        shimei_total: j.bill.shimei_total ?? 0,
        drink_total: j.bill.drink_total ?? 0,
      }});
      return j.bill;
    }

    throw new Error('Unknown calc response shape');
  }

  async function loadFromDB(ticketId){
    // ★ haruto_core 版：/public/api/ticket_get.php がある前提
    const r = await fetch(`/wbss/public/api/ticket_get.php?ticket_id=${encodeURIComponent(ticketId)}`, {cache:'no-store'});
    const txt = await r.text();
    if (!r.ok) throw new Error(`load HTTP ${r.status}: ${txt.slice(0,200)}`);

    let j;
    try { j = JSON.parse(txt); } catch(e){ throw new Error('load Non-JSON: ' + txt.slice(0,200)); }
    if (!j.ok) throw new Error(j.error || 'load failed');

    state.sets = j.payload.sets || [];
    state.history = j.payload.history || [];
    state.ui.selected_set_index = 0;

    startTimeEl.value = j.payload.start_time || "20:00";
    discountEl.value = String(j.payload.discount || 0);

    state.sets.forEach(s=>normalizeCustomers(s));
    syncSetTimes();
    render(true);
  }

  async function saveToDB(){
    const payload = buildPayload();

    // ★ haruto_core 版：/public/api/ticket_save.php がある前提
    const r = await fetch('/wbss/public/api/ticket_save.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      credentials:'same-origin',
      body: JSON.stringify({ ticket_id: (TICKET_ID||0), payload }),
    });

    const txt = await r.text();
    if (!r.ok) throw new Error(`save HTTP ${r.status}: ${txt.slice(0,200)}`);

    let j;
    try { j = JSON.parse(txt); } catch(e){ throw new Error('save Non-JSON: ' + txt.slice(0,200)); }
    if (!j.ok) throw new Error(j.error || 'save failed');

    return j;
  }

  let silentSaveTimer = null;
  function requestSilentSave(reason=''){
    if (!(window.TICKET_ID > 0)) return;
    if (silentSaveTimer) clearTimeout(silentSaveTimer);
    silentSaveTimer = window.setTimeout(async ()=>{
      try {
        await saveToDB();
      } catch (err) {
        console.warn('silent save failed:', reason, err);
      }
    }, 250);
  }

  function applyReadonlyIfClosed(){
    if ((window.TICKET_STATUS || '') !== 'closed') return;

    const allowIds = new Set(['freeHistBtn','goIndex2Btn','previewBtn','serverCalcBtn','serverCalcBtn_dup','serverCalcBtn_head']);
    document.querySelectorAll('input, select, button').forEach(el=>{
      if (allowIds.has(el.id)) return;
      el.disabled = true;
      el.classList?.add('disabled');
    });

    const badge = document.getElementById('statusBadge');
    if (badge) {
      badge.classList.add('closed');
      badge.innerHTML = `状態: <b>精算済</b>`;
    }
  }

  function render(full=true){
    setTabsEl.innerHTML = '';
    state.sets.forEach((s, i)=>{
      const seatLabel = seatLabelById(s.seat_id || 0);
      const tabDrinkSum = (s.drinks||[]).reduce((a,d)=>a + (d.amount|0), 0);
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'tabXL setTabCard' + (i === state.ui.selected_set_index ? ' on' : '');
      b.innerHTML = `
        <span class="setTabCardTitle">セット${i+1}</span>
        <span class="setTabCardMeta">席 ${escapeHtml(seatLabel)} / ドリンク ${tabDrinkSum.toLocaleString()}円</span>
      `;
      b.title = buildFreeStatusText(s);
      b.addEventListener('click', ()=> selectSetTab(i));
      setTabsEl.appendChild(b);
    });

    const idx = state.ui.selected_set_index;
    const s = state.sets[idx];
    renderGlobalQuickTools(s, idx);
    if (!s) { setPaneEl.innerHTML = `<div class="muted">セットがありません</div>`; return; }

    normalizeCustomers(s);
    syncSetTimes();
    // ★追加：ladies_people は 0〜guestN に収める（guest減った時の事故防止）
    {
      const guestN = Math.max(0, s.guest_people|0);
      const lp = (s.ladies_people|0);
      s.ladies_people = Math.max(0, Math.min(guestN, lp));
    }
    let shMap = {};
    for (let c=1;c<= (s.guest_people|0); c++){
      const cust = s.customers[String(c)];
      if (!cust) continue;
      if (cust.mode === 'shimei' && cust.shimei && typeof cust.shimei === 'object'){
        Object.keys(cust.shimei).forEach(no=>{
          const knd = (cust.shimei[no] === 'hon') ? 'hon' : 'jounai';
          if (shMap[no] === 'hon') return;
          shMap[no] = knd;
        });
      }
    }
    const shimeiCount = Object.keys(shMap).length;
    const charge = Math.max(s.guest_people|0, shimeiCount);
    const drinkSum = (s.drinks||[]).reduce((a,d)=>a + (d.amount|0), 0);
    const activeWorkTab = (state.ui.work_tab === 'customer' || state.ui.work_tab === 'drink') ? state.ui.work_tab : 'set';

    setPaneEl.innerHTML = `
    <div class="setPaneTop">
      <div>
        <span class="badgeMini2">編集中 セット${idx + 1}</span>
        <span class="badgeMini2">席 ${escapeHtml(seatLabelById(s.seat_id || 0))}</span>
      </div>
      <div class="muted">ここでは選んだセットの内容を編集します</div>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
          <div style="min-width:180px;">
            <label style="margin:0 0 4px;">移動先の席</label>
            <select id="seatMoveTargetSel">
              ${seatOptionsHtml(s.seat_id || 0)}
            </select>
          </div>
          <button type="button" class="btn2" id="seatMoveBtn">席移動 → 新セット</button>

          <button type="button" class="btn2" id="delSetBtn">このセット削除</button>
        </div>

      </div>

      <div class="hr"></div>

      <div class="cashierDashTabs" role="tablist" aria-label="会計作業切り替え">
        <button
          type="button"
          class="cashierDashTab ${activeWorkTab === 'set' ? 'is-active' : ''}"
          data-work-tab="set"
          role="tab"
          aria-selected="${activeWorkTab === 'set' ? 'true' : 'false'}"
        >
          <span class="cashierDashTabIcon">🪑</span>
          <span class="cashierDashTabText">
            <span class="cashierDashTabTitle">基本設定</span>
            <span class="cashierDashTabMeta">人数・VIP・種別を編集</span>
          </span>
        </button>
        <button
          type="button"
          class="cashierDashTab ${activeWorkTab === 'customer' ? 'is-active' : ''}"
          data-work-tab="customer"
          role="tab"
          aria-selected="${activeWorkTab === 'customer' ? 'true' : 'false'}"
        >
          <span class="cashierDashTabIcon">👥</span>
          <span class="cashierDashTabText">
            <span class="cashierDashTabTitle">客ごとのモード</span>
            <span class="cashierDashTabMeta">FREE・指名の切替</span>
          </span>
        </button>
        <button
          type="button"
          class="cashierDashTab ${activeWorkTab === 'drink' ? 'is-active' : ''}"
          data-work-tab="drink"
          role="tab"
          aria-selected="${activeWorkTab === 'drink' ? 'true' : 'false'}"
        >
          <span class="cashierDashTabIcon">🥃</span>
          <span class="cashierDashTabText">
            <span class="cashierDashTabTitle">ドリンク（都度追加）</span>
            <span class="cashierDashTabMeta">評価先・金額・履歴</span>
          </span>
        </button>
      </div>

      <div class="workTabPane ${activeWorkTab === 'set' ? 'active' : ''}" id="workPaneSet">
        <div class="row2">
          <div>
            <div class="guestLadiesRow">
              <div class="guestBox">
                <label>ゲスト人数（来店人数）</label>
                <input type="number" id="guest_people" min="0" value="${s.guest_people}">
              </div>

              <div class="ladiesBox">
                <label class="small muted" style="display:flex; gap:8px; align-items:center; user-select:none; margin-bottom:6px;">
                  <input type="checkbox" id="ladies_enabled" class="chkMini"${ (s.ladies_people|0) > 0 ? 'checked' : '' }>
                  レディース料金
                </label>

                <div id="ladiesWrap"
                    style="display:${(s.ladies_people|0) > 0 ? 'flex' : 'none'}; gap:8px; align-items:center; flex-wrap:wrap;">
                  <span class="small muted">人数</span>

                  <div class="stepper">
                    <button type="button" class="stepBtn" id="ladiesMinus">−</button>
                    <input
                      type="text"
                      id="ladies_people"
                      class="stepVal"
                      value="${s.ladies_people||0}"
                      readonly
                      inputmode="numeric"
                    >
                    <button type="button" class="stepBtn" id="ladiesPlus">＋</button>
                  </div>

                  <button type="button" class="btnMini" id="ladiesMax">MAX</button>
                  <button type="button" class="btnMini" id="ladiesClear">0</button>
                </div>
              </div>
            </div>
          </div>

          <div>
            <div class="row2">
              <div>
                <label>VIP（+10000）</label>
                <select id="vip">
                  <option value="0" ${s.vip ? "" : "selected"}>OFF</option>
                  <option value="1" ${s.vip ? "selected" : ""}>ON</option>
                </select>
              </div>

              <div>
                <label>セット種別</label>
                <select id="kindSel">
                  <option value="normal50" ${s.kind==='normal50'?'selected':''}>通常50分 7000</option>
                  <option value="half25" ${s.kind==='half25'?'selected':''}>ハーフ25分 3500</option>
                  <option value="pack_douhan" ${s.kind==='pack_douhan'?'selected':''} ${idx>=1?'disabled':''}>同伴 13000</option>
                </select>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="workTabPane ${activeWorkTab === 'customer' ? 'active' : ''}" id="workPaneCustomer">
        <h2>客ごとのモード</h2>
        <div class="muted small">FREE=席付き / 指名=客ごと（○→★→解除）</div>
        <div class="tabs wrap" id="custTabs"></div>
        <div class="pane" id="custPane"></div>
      </div>

      <div class="workTabPane ${activeWorkTab === 'drink' ? 'active' : ''}" id="workPaneDrink">
        <h2>ドリンク（都度追加）</h2>
        <div class="muted small" id="currentAssignHint"></div>
        <div>
          <label>評価先</label>
          <select id="payer_select"></select>
          <div class="muted small" style="margin-top:6px;">※ 今最後に付いてる人が最上段に出ます</div>
        </div>
        <div class="drinkRowTight">
          <div>
            <label>金額（税別）</label>
            <div class="amtRow">
              <input type="number" id="drink_amount" class="amtInput" min="0" value="0" inputmode="numeric">
              <div class="amtBtns">
                <button type="button" class="btnAmt" id="btnAmt1000">+1000</button>
                <button type="button" class="btnAmt" id="btnAmt1500">+1500</button>
                <button type="button" class="btnAmt" id="btnAmtClear">クリア</button>
              </div>
            </div>
          </div>
          <div>
            <label>操作</label>
            <button type="button" class="btn b-green" id="addDrinkBtn">+ ドリンク追加</button>
          </div>
        </div>

        <div class="hr"></div>

        <h2>このセットのドリンク一覧</h2>
        <div id="drinkList"></div>
      </div>
    `;

    document.getElementById('delSetBtn').addEventListener('click', ()=>{
      const removed = state.sets.splice(idx, 1)[0];
      logEvent('set_remove', idx+1, {removed});
      state.ui.selected_set_index = Math.max(0, Math.min(state.ui.selected_set_index, state.sets.length-1));
      syncSetTimes();
      render(true);
      requestSilentSave('set_remove');
    });

    const seatMoveBtn = document.getElementById('seatMoveBtn');
    if (seatMoveBtn){
      seatMoveBtn.addEventListener('click', ()=>{
        const seatId = Number((document.getElementById('seatMoveTargetSel')?.value) || 0);
        seatMoveAsNewSet(idx, seatId);
      });
    }

    document.getElementById('guest_people').addEventListener('change', (e)=> setGuestPeople(idx, e.target.value));
    document.getElementById('vip').addEventListener('change', (e)=> setVip(idx, e.target.value === '1'));
    document.getElementById('kindSel').addEventListener('change', (e)=> setKindForSet(idx, e.target.value));
    setPaneEl.querySelectorAll('[data-work-tab]').forEach(btn=>{
      btn.addEventListener('click', ()=> selectWorkTab(btn.getAttribute('data-work-tab') || 'set'));
    });
    // =========================
    // レディース ステッパー
    // =========================
    const btnMinus = document.getElementById('ladiesMinus');
    const btnPlus  = document.getElementById('ladiesPlus');
    const btnMax   = document.getElementById('ladiesMax');
    const btnClear = document.getElementById('ladiesClear');

    function setLadies(val){
      const gn = Math.max(0, s.guest_people|0);
      const before = s.ladies_people|0;

      s.ladies_people = Math.max(0, Math.min(gn, val|0));

      if (before !== s.ladies_people){
        logEvent('ladies_people_change', idx+1, {
          before,
          after: s.ladies_people,
          guest_people: gn
        });
      }

      render(true);
    }

    if (btnMinus) btnMinus.addEventListener('click', ()=> setLadies((s.ladies_people|0) - 1));
    if (btnPlus)  btnPlus.addEventListener('click',  ()=> setLadies((s.ladies_people|0) + 1));
    if (btnMax)   btnMax.addEventListener('click',   ()=> setLadies((s.guest_people|0)));
    if (btnClear) btnClear.addEventListener('click', ()=> setLadies(0));
    // =========================
    // レディース（checkbox + 人数）
    // =========================
    const ladiesEnabledEl = document.getElementById('ladies_enabled');
    const ladiesWrapEl    = document.getElementById('ladiesWrap');
    const ladiesPeopleEl  = document.getElementById('ladies_people');

    function clampLadies(){
      const gn = Math.max(0, s.guest_people|0);
      if (s.ladies_people == null) s.ladies_people = 0;
      s.ladies_people = Math.max(0, Math.min(gn, (s.ladies_people|0)));
    }
    function syncLadiesUI(){
      clampLadies();
      const on = (s.ladies_people|0) > 0;
      if (ladiesEnabledEl) ladiesEnabledEl.checked = on;
      if (ladiesWrapEl) ladiesWrapEl.style.display = on ? 'flex' : 'none';
      if (ladiesPeopleEl) ladiesPeopleEl.value = String(s.ladies_people|0);
    }

    if (ladiesEnabledEl && ladiesWrapEl && ladiesPeopleEl){
      // 初期同期
      syncLadiesUI();

      ladiesEnabledEl.addEventListener('change', ()=>{
        const gn = Math.max(0, s.guest_people|0);
        if (ladiesEnabledEl.checked) {
          // ONにしたら最低1（ゲスト0なら0）
          s.ladies_people = (gn > 0) ? Math.max(1, s.ladies_people|0) : 0;
        } else {
          s.ladies_people = 0;
        }
        syncLadiesUI();
        logEvent('ladies_toggle', idx+1, {after: s.ladies_people|0});
        render(true); // 画面と金額再計算を確実に
      });
    }


    const custTabs = document.getElementById('custTabs');
    const custPane = document.getElementById('custPane');
    const guestN = Math.max(0, s.guest_people|0);

    if (guestN === 0) {
      custTabs.innerHTML = '';
      custPane.innerHTML = `<div class="muted">ゲスト人数が0のため表示なし</div>`;
    } else {
      const selectedCust = s.customer_tab_selected || 1;

      custTabs.innerHTML = '';
      for (let c=1;c<=guestN;c++){
        const tb = document.createElement('button');
        tb.type = 'button';
        tb.className = 'tabXL' + (c===selectedCust ? ' on' : '');
        tb.textContent = `客${c}`;
        tb.addEventListener('click', ()=> selectCustomerTab(idx, c));
        custTabs.appendChild(tb);
      }

      const custNo = selectedCust;
      const custObj = getCustomer(s, custNo);
      const mode = custObj.mode;

      const modeHtml = `
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
          <button type="button" class="btn b-cyan" id="modeFreeBtn">FREE（席付き）</button>
          <button type="button" class="btn b-purple" id="modeShimeiBtn">指名</button>
          <span class="badgeMini2">現在: ${mode === 'shimei' ? '指名' : 'FREE'}</span>
        </div>
      `;

      if (mode === 'shimei') {
        const map = custObj.shimei || {};
        const selectedTags = Object.keys(map).sort((a,b)=>parseInt(a)-parseInt(b)).map(no=>{
          const knd = map[no];
          return `${knd==='hon'?'★本':'○場内'}${no}`;
        }).join(' / ') || 'なし';

        custPane.innerHTML = `
          ${modeHtml}
          <div class="warnBox on" style="display:block;">客${custNo} は指名モードです（席付きは編集不可）</div>
          <div class="muted small" style="margin:8px 0;">店番タップで「○→★→解除」</div>
          <div class="grid30" id="custShimeiGrid"></div>
          <div class="muted small" style="margin-top:8px;">選択: ${selectedTags}</div>
        `;

        document.getElementById('modeFreeBtn').addEventListener('click', ()=>{
          setCustomerMode(s, custNo, 'free');
          render(false);
        });
        document.getElementById('modeShimeiBtn').addEventListener('click', ()=>{
          setCustomerMode(s, custNo, 'shimei');
          render(false);
        });

        const grid = document.getElementById('custShimeiGrid');
        const candNos = getCandidateNos();
        if (!candNos.length){
          grid.innerHTML = `<div class="muted">出勤確定キャストがいません（出勤登録で確定ONしてください）</div>`;
        } else {
          candNos.forEach(no=>{
            const b = document.createElement('button');
            b.type='button';

            const cur = (custObj.shimei||{})[String(no)] || null;
            const on = !!cur;
            b.className = 'noBtn' + (on ? ' on' : '');
            b.textContent = cur ? (cur==='hon' ? `★${no}` : `○${no}`) : String(no);
            b.title = candidateTitle(no);

            b.addEventListener('click', ()=> cycleCustomerShimei(s, custNo, no));
            grid.appendChild(b);
          });
        }

        const hint = document.getElementById('currentAssignHint');
        hint.textContent = `客${custNo}：指名モード`;

      } else {
        const box = custObj.free || {first:0,second:0,third:0,phase:'first'};
        const phase = box.phase || 'first';
        const phaseInfoMap = {
          first:  { label:'FIRST',  cardClass:'blockBlue',   gridId:'free_phase_grid', hint:'※ SECOND/THIRD のときはロック', current: box.first || 0 },
          second: { label:'SECOND', cardClass:'blockGreen',  gridId:'free_phase_grid', hint:'※ THIRD のときはロック', current: box.second || 0 },
          third:  { label:'THIRD',  cardClass:'blockPurple', gridId:'free_phase_grid', hint:'※ 現在進行中', current: box.third || 0 },
        };
        const currentPhaseInfo = phaseInfoMap[phase] || phaseInfoMap.first;

        const curNo = getCurrentAssignedCastNoForCustomer(s, custNo);
        const hint = document.getElementById('currentAssignHint');
        hint.textContent = curNo
          ? `客${custNo}：今は ${phase.toUpperCase()}（最後に付いてる店番 ${curNo}）`
          : `客${custNo}：今は ${phase.toUpperCase()}（まだ店番が入っていません）`;

        custPane.innerHTML = `
          ${modeHtml}

          <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin:12px 0;">
            <div style="font-weight:1100;">フリー回転</div>
            <div class="phaseTabs">
              <button type="button" class="phaseBtn ${phase==='first'?'on':''}"  id="phaseTabFirst">FIRST</button>
              <button type="button" class="phaseBtn ${phase==='second'?'on':''}" id="phaseTabSecond">SECOND</button>
              <button type="button" class="phaseBtn ${phase==='third'?'on':''}"  id="phaseTabThird">THIRD</button>
            </div>
            <span class="badgeMini2">現在: ${phase.toUpperCase()}</span>
          </div>

          <div class="hr"></div>

          <div class="blockSafe ${currentPhaseInfo.cardClass}">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
              <div style="font-weight:1100;">${currentPhaseInfo.label}</div>
              ${currentPhaseInfo.current ? `<span class="badgeMini2">店番${currentPhaseInfo.current}</span>` : `<span class="muted">—</span>`}
            </div>
            <div class="muted" style="margin:6px 0 8px;">${currentPhaseInfo.hint}</div>
            <div class="grid30" id="${currentPhaseInfo.gridId}"></div>
          </div>
        `;

        document.getElementById('modeFreeBtn').addEventListener('click', ()=>{
          setCustomerMode(s, custNo, 'free');
          render(false);
        });
        document.getElementById('modeShimeiBtn').addEventListener('click', ()=>{
          setCustomerMode(s, custNo, 'shimei');
          render(false);
        });

        document.getElementById('phaseTabFirst')?.addEventListener('click', ()=> setFreePhase(idx, custNo, 'first'));
        document.getElementById('phaseTabSecond')?.addEventListener('click', ()=> setFreePhase(idx, custNo, 'second'));
        document.getElementById('phaseTabThird')?.addEventListener('click', ()=> setFreePhase(idx, custNo, 'third'));

        function fillRole(roleKey, gridId, currentNo){
          const grid = document.getElementById(gridId);
          const locked = isLocked(phase, roleKey);
          grid.innerHTML = '';

          const candNos = getCandidateNos();
          if (!candNos.length){
            grid.innerHTML = `<div class="muted">出勤確定キャストがいません</div>`;
            return;
          }

          candNos.forEach(no=>{
            const b = document.createElement('button');
            b.type='button';
            b.className = 'noBtn' + ((currentNo===no) ? ' on' : '') + (locked ? ' disabled' : '');
            b.textContent = String(no);
            b.title = candidateTitle(no);
            if (!locked) b.addEventListener('click', ()=> toggleFreeRoleForCustomer(idx, custNo, roleKey, no));
            grid.appendChild(b);
          });
        }

        fillRole(phase, currentPhaseInfo.gridId, currentPhaseInfo.current);
      }
    }

    const payerSel = document.getElementById('payer_select');
    const groups = buildPayerOptions(s);

    payerSel.innerHTML = '';
    groups.forEach(g=>{
      const og = document.createElement('optgroup');
      og.label = g.label;
      g.items.forEach(it=>{
        const op = document.createElement('option');
        op.value = it.v;
        op.textContent = it.t;
        og.appendChild(op);
      });
      payerSel.appendChild(og);
    });

    if (state.ui.last_payer_sel) payerSel.value = state.ui.last_payer_sel;
    payerSel.addEventListener('change', ()=>{ state.ui.last_payer_sel = payerSel.value; });

    const amtEl = document.getElementById('drink_amount');
    document.getElementById('btnAmt1000').addEventListener('click', ()=>{
      amtEl.value = String((parseInt(amtEl.value||"0",10) || 0) + 1000);
    });
    document.getElementById('btnAmt1500').addEventListener('click', ()=>{
      amtEl.value = String((parseInt(amtEl.value||"0",10) || 0) + 1500);
    });
    document.getElementById('btnAmtClear').addEventListener('click', ()=>{ amtEl.value = "0"; });

    document.getElementById('addDrinkBtn').addEventListener('click', ()=>{
      const amount = parseInt(amtEl.value || "0", 10) || 0;
      const v = payerSel.value || '';
      const parts = v.split(':');
      const payerType = parts[0] || '';
      let payerId = '';

      if (payerType === 'cust') payerId = parts[1] || '';
      else if (payerType === 'shimei') payerId = parts[1] || '';
      else if (payerType === 'free') payerId = (parts[1] || '0') + ':' + (parts[2] || '');
      else return;

      addDrink(idx, amount, payerType, payerId, null);
      amtEl.value = "0";
    });

    const drinkListEl = document.getElementById('drinkList');
    const drinks = Array.isArray(s.drinks) ? s.drinks : [];

    if (!drinks.length) {
      drinkListEl.innerHTML = `<div class="muted">まだありません</div>`;
    } else {
      drinkListEl.innerHTML = drinks.map((d, di)=>{
        const label = (() => {
          if (d.payer_type === 'cust') return `客${d.payer_id}`;
          if (d.payer_type === 'shimei') return `指名 店番${d.payer_id}`;
          if (d.payer_type === 'free') return `フリー ${d.payer_id}`;
          return `${d.payer_type}:${d.payer_id}`;
        })();

        return `
          <div class="drinkRow">
            <div class="badgeMini">${label}</div>
            <div class="money key"><strong>${(d.amount|0).toLocaleString()}</strong> 円</div>
            <div class="muted small">${d.meta ? escapeHtml(JSON.stringify(d.meta)) : ''}</div>
            <button type="button" class="btn2" data-di="${di}">削除</button>
          </div>
        `;
      }).join('');

      drinkListEl.querySelectorAll('button[data-di]').forEach(btn=>{
        btn.addEventListener('click', ()=>{
          const di = parseInt(btn.getAttribute('data-di') || "0", 10);
          removeDrink(idx, di);
        });
      });
    }
  }

  document.getElementById('startBtn').addEventListener('click', startTimer);
  document.getElementById('stopBtn').addEventListener('click', ()=> stopTimer(true));
  document.getElementById('freeHistBtn').addEventListener('click', openFreeHistoryWindow);

  document.getElementById('addSetBtn').addEventListener('click', ()=>{
    addSet({ carryCustomers:true, resetFreeRotation:true });
    state.ui.selected_set_index = state.sets.length - 1;
    logEvent('set_add', state.ui.selected_set_index + 1, {resetFreeRotation:true});
    render(true);
    requestSilentSave('set_add');
  });

  document.getElementById('previewBtn').addEventListener('click', ()=>{
    const res = computePreview();
    renderPreview(res);
  });

  document.getElementById('billForm').addEventListener('submit', async (e)=>{
    e.preventDefault();
    try{
      await saveToDB();
      alert('保存しました');
    }catch(err){
      alert('保存失敗: ' + err.message);
    }
  });
(async ()=>{
  try {
    try {
      await loadCastCandidates();
    } catch (e) {
      console.warn('loadCastCandidates failed:', e);
      state.cast_candidates = [];
    }

    if (TICKET_ID) {
      await loadFromDB(TICKET_ID);
      // ★DBから取れたけど sets が空だった場合の保険（ここが安全）
      if (!state.sets.length) {
        addSet({ guest_people: 1 });
        syncSetTimes();
        render(true);
      }
    } else {
      addSet({ guest_people: 1 });
      syncSetTimes();
      render(true);
    }

    if (window.AUTO_PREVIEW) {
      const res = computePreview();
      renderPreview(res);
    }

    applyReadonlyIfClosed();

  } catch (err) {
    // ここで保険（画面が真っ白にならない）
    console.error(err);
    if (!state.sets.length) {
      addSet({ guest_people: 1 });
      syncSetTimes();
      render(true);
    }
    alert('初期化失敗: ' + err.message);
  }
})();

window.STORE_ID = <?= (int)$storeId ?>;
window.BUSINESS_DATE = <?= json_encode((string)$business_date, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
window.AUTO_PREVIEW = <?= $auto_preview ? 'true' : 'false' ?>;
</script>
<div id="payDrawerBackdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.25); z-index:9998;"></div>

<aside id="payDrawer" style="
  position:fixed; top:0; right:0; height:100vh;
  width:min(72vw, 980px);
  min-width:720px;
  transform:translateX(110%);
  transition:transform .18s ease;
  background:var(--card);
  color:var(--text);
  border-left:1px solid var(--line);
  box-shadow:-12px 0 30px rgba(16,24,40,.15);
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
(() => {
  if (window.__PAY_DRAWER_WIRED__) return;
  window.__PAY_DRAWER_WIRED__ = true;

  const closeBtn = document.getElementById('closeBtn');
  if (!closeBtn) return;

  const drawer   = document.getElementById('payDrawer');
  const backdrop = document.getElementById('payDrawerBackdrop');
  const frame    = document.getElementById('payFrame');
  const sub      = document.getElementById('payDrawerSub');
  const openNewTab = document.getElementById('payOpenNewTab');
  const btnClose = document.getElementById('payDrawerClose');

  const canDrawer = drawer && backdrop && frame && sub && openNewTab && btnClose;

  const qs = new URLSearchParams(location.search);
  const ticketId = qs.get('ticket_id') || '';
  const storeId = qs.get('store_id') || window.STORE_ID || '';
  const businessDate = qs.get('business_date') || window.BUSINESS_DATE || '';

function openDrawer(url){
  // iOS対策：一旦クリアしてから入れる
  frame.src = 'about:blank';
  setTimeout(() => { frame.src = url; }, 0);

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

  if (canDrawer) {
    btnClose.addEventListener('click', closeDrawer);
    backdrop.addEventListener('click', closeDrawer);
    window.addEventListener('keydown', (e)=>{ if (e.key === 'Escape') closeDrawer(); });
  }

  closeBtn.type = 'button';

  closeBtn.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();

    if (!canDrawer) {
      alert('Drawer not initialized.');
      return;
    }

    const url =
      `/wbss/public/cashier/payments.php`
      + `?ticket_id=${ticketId}`
      + `&store_id=${storeId}`
      + `&business_date=${businessDate}`
      + `&embed=1`;

    openDrawer(url);
  });
})();
</script>
</body>
</html>
