<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';

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
    // store_select.php があるならそこへ（無ければ適宜変更）
    header('Location: /seika-app/public/store_select.php?next=' . urlencode('/seika-app/public/cast_week.php'));
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

/** 週計算（月曜起点） */
function week_start_ymd(string $ymd): string {
  $d = new DateTime($ymd, new DateTimeZone('Asia/Tokyo'));
  $dow = (int)$d->format('N'); // 1=Mon..7=Sun
  $d->modify('-' . ($dow - 1) . ' days');
  return $d->format('Y-m-d');
}
function week_dates(string $weekStartYmd): array {
  $d = new DateTime($weekStartYmd, new DateTimeZone('Asia/Tokyo'));
  $out = [];
  for ($i=0; $i<7; $i++) {
    $out[] = $d->format('Y-m-d');
    $d->modify('+1 day');
  }
  return $out;
}
function now_jst_ymd(): string {
  return (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
}

/** HH:MM -> HH:MM:00 */
function normalize_time_hm(?string $hm): ?string {
  $hm = trim((string)$hm);
  if ($hm === '') return null;
  if (!preg_match('/^\d{2}:\d{2}$/', $hm)) return null;
  [$hh, $mm] = array_map('intval', explode(':', $hm));
  if ($hh < 0 || $hh > 23 || $mm < 0 || $mm > 59) return null;
  return sprintf('%02d:%02d:00', $hh, $mm);
}

/** note に end を保持（将来用） */
function note_from_end(string $endHm): string {
  $endHm = trim($endHm);
  if ($endHm === '' || strtoupper($endHm) === 'LAST') return '';
  if (!preg_match('/^\d{2}:\d{2}$/', $endHm)) return '';
  return '#end=' . $endHm;
}
function read_end_from_note(?string $note): string {
  $note = (string)($note ?? '');
  if (preg_match('/#end=(\d{2}:\d{2}|LAST)\b/u', $note, $m)) {
    return strtoupper((string)$m[1]);
  }
  return 'LAST';
}
// ===== 店舗ごとの定休日（曜日）＋ 日別例外（store_closures）で営業判定 =====

function store_closed_dows(PDO $pdo, int $storeId): array {
  static $cache = [];
  if (isset($cache[$storeId])) return $cache[$storeId];

  $dows = [];
  try {
    $st = $pdo->prepare("SELECT dow FROM store_weekly_closed_days WHERE store_id=?");
    $st->execute([$storeId]);
    foreach (($st->fetchAll(PDO::FETCH_COLUMN) ?: []) as $v) {
      $d = (int)$v;
      if ($d >= 1 && $d <= 7) $dows[] = $d;
    }
  } catch (Throwable $e) {
    // テーブル未導入でも落とさない
  }

  return $cache[$storeId] = $dows;
}

function store_override_open(PDO $pdo, int $storeId, string $ymd): ?bool {
  // store_closures を「上書き」テーブルとして扱う（is_open=1:営業 / 0:休業）
  try {
    $st = $pdo->prepare("SELECT is_open FROM store_closures WHERE store_id=? AND closed_date=? LIMIT 1");
    $st->execute([$storeId, $ymd]);
    $v = $st->fetchColumn();
    if ($v === false) return null;
    return ((int)$v === 1);
  } catch (Throwable $e) {
    return null;
  }
}

function is_store_open(PDO $pdo, int $storeId, string $ymd): bool {
  // 1) 日別上書きが最優先（臨時営業/臨時休業）
  $ov = store_override_open($pdo, $storeId, $ymd);
  if ($ov !== null) return $ov;

  // 2) 曜日定休日ルール
  $d = new DateTime($ymd, new DateTimeZone('Asia/Tokyo'));
  $dow = (int)$d->format('N'); // 1..7
  $closed = store_closed_dows($pdo, $storeId);

  // closedが空なら「定休日なし」扱い
  return !in_array($dow, $closed, true);
}

$storeId = resolve_store_id($pdo);
$userId  = current_user_id_safe();
if ($userId <= 0) { http_response_code(401); exit('not logged in'); }

$week     = (string)($_GET['week'] ?? $_POST['week'] ?? now_jst_ymd());
$weekStart = week_start_ymd($week);
$weekDates = week_dates($weekStart); // 週の7日（読み込み範囲の基準）
$dates    = week_dates($weekStart);
// 営業日だけ表示（定休日は非表示、臨時営業日は表示）
$dates = array_values(array_filter($dates, function($ymd) use ($pdo, $storeId){
  return is_store_open($pdo, $storeId, $ymd);
}));
// 店名（任意）
$st = $pdo->prepare("SELECT name FROM stores WHERE id=? LIMIT 1");
$st->execute([$storeId]);
$storeName = (string)($st->fetchColumn() ?: ('#'.$storeId));

// ユーザー名
$st = $pdo->prepare("SELECT display_name, is_active FROM users WHERE id=? LIMIT 1");
$st->execute([$userId]);
$u = $st->fetch(PDO::FETCH_ASSOC);
$displayName = (string)($u['display_name'] ?? ('user#'.$userId));
$isActiveUser = (int)($u['is_active'] ?? 1);

// デフォ開始（cast_profiles があればそこから、無ければ 20:00）
$defaultStart = '20:00';
try {
  $st = $pdo->prepare("SELECT default_start_time FROM cast_profiles WHERE user_id=? AND store_id=? LIMIT 1");
  $st->execute([$userId, $storeId]);
  $v = $st->fetchColumn();
  if ($v !== false && $v !== null) $defaultStart = substr((string)$v, 0, 5);
} catch (Throwable $e) {}

// 前後週
$ws = new DateTime($weekStart, new DateTimeZone('Asia/Tokyo'));
$prev = (clone $ws)->modify('-7 day')->format('Y-m-d');
$next = (clone $ws)->modify('+7 day')->format('Y-m-d');

$msg = '';
$err = '';

/* =========================
   保存（本人の週7日分）
========================= */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  csrf_verify($_POST['csrf_token'] ?? null);

  if ($isActiveUser !== 1) {
    $err = '非在籍のため編集できません';
  } else {
    $pdo->beginTransaction();
    try {
      $up = $pdo->prepare("
        INSERT INTO cast_shift_plans
          (store_id, user_id, business_date, start_time, is_off, status, note, created_by_user_id)
        VALUES
          (?, ?, ?, ?, ?, 'planned', ?, ?)
        ON DUPLICATE KEY UPDATE
          start_time=VALUES(start_time),
          is_off=VALUES(is_off),
          status='planned',
          note=VALUES(note),
          created_by_user_id=VALUES(created_by_user_id),
          updated_at=NOW()
      ");

      foreach ($dates as $ymd) {
        $onKey = 'on_' . $ymd;
        $tmKey = 'time_' . $ymd;
        $enKey = 'end_'  . $ymd;

        $isOn = isset($_POST[$onKey]) && (string)$_POST[$onKey] === '1';
        $tHm  = trim((string)($_POST[$tmKey] ?? ''));
        $eHm  = trim((string)($_POST[$enKey] ?? 'LAST'));

        if (!$isOn) {
          // OFF（レコードを残す：is_off=1）
          $up->execute([$storeId, $userId, $ymd, null, 1, '', $userId ?: null]);
          continue;
        }

        $start = normalize_time_hm($tHm) ?? normalize_time_hm($defaultStart);
        $note  = note_from_end($eHm);

        $up->execute([$storeId, $userId, $ymd, $start, 0, $note, $userId ?: null]);
      }

      // ログ（テーブルが無い環境もあるので try）
      try {
        $lg = $pdo->prepare("
          INSERT INTO cast_shift_logs (store_id, user_id, action, payload_json, created_by_user_id)
          VALUES (?, ?, 'cast.shift.week_save', ?, ?)
        ");
        $lg->execute([$storeId, $userId, json_encode(['weekStart'=>$weekStart], JSON_UNESCAPED_UNICODE), $userId ?: null]);
      } catch (Throwable $e) {}

      $pdo->commit();
      $msg = '保存しました';
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $err = '保存失敗: ' . $e->getMessage();
    }
  }
}

/* =========================
   読み込み（本人の週）
========================= */
$plan = []; // [ymd] => ['on'=>bool,'time'=>'HH:MM','end'=>'LAST|HH:MM']
if ($weekDates) {
  $minD = $weekDates[0];
  $maxD = $weekDates[count($weekDates)-1];

  $st = $pdo->prepare("
    SELECT business_date, start_time, is_off, note
    FROM cast_shift_plans
    WHERE store_id=? AND user_id=? AND business_date BETWEEN ? AND ?
      AND status='planned'
  ");
  $st->execute([$storeId, $userId, $minD, $maxD]);

  foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
    $ymd = (string)$r['business_date'];
    $off = ((int)$r['is_off'] === 1);
    $t   = (!$off && $r['start_time'] !== null) ? substr((string)$r['start_time'], 0, 5) : '';
    $end = read_end_from_note($r['note'] ?? null);

    $plan[$ymd] = ['on' => !$off, 'time' => $t, 'end' => $end];
  }
}

// UI
render_page_start('出勤（キャスト）');
render_header('出勤（キャスト）', [
  'back_href' => '/seika-app/public/dashboard_cast.php',
  'back_label' => '← ダッシュボード',
  'right_html' => '',
]);

$dowJp = ['','月','火','水','木','金','土','日'];
?>
<div class="page">
  <div class="wrap">

    <div class="hero">
      <div class="heroTop">
        <div>
          <div class="h1">📅 出勤（週）</div>
          <div class="sub">店舗：<b><?= h($storeName) ?></b> / <?= h($displayName) ?></div>
        </div>

        <div class="weekNav">
          <a class="btn ghost" href="?week=<?= h($prev) ?>">← 前週</a>
          <span class="pill">週: <b><?= h($weekStart) ?></b></span>
          <a class="btn ghost" href="?week=<?= h($next) ?>">次週 →</a>
        </div>
      </div>

      <?php if ($msg): ?><div class="notice ok"><?= h($msg) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="notice ng"><?= h($err) ?></div><?php endif; ?>

      <div class="tools">
        <button class="toolBtn" type="button" onclick="fillDefault()">✨ 基本開始で埋める <small>(<?= h($defaultStart) ?>)</small></button>
        <button class="toolBtn" type="button" onclick="allOn()">✅ 全部ON</button>
        <button class="toolBtn" type="button" onclick="allOff()">🛌 全部OFF</button>
      </div>

      <div class="muted" style="margin-top:8px;">
        ONで開始時刻を入力。終了は「LAST」か時刻を選べます（例：01:30）。
      </div>
    </div>

    <form method="post" style="margin-top:12px;">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="week" value="<?= h($weekStart) ?>">

      <div class="list">
        <?php foreach ($dates as $ymd): ?>
          <?php
            $d = new DateTime($ymd, new DateTimeZone('Asia/Tokyo'));
            $dow = (int)$d->format('N'); // 1..7
            $dowJp = ['','月','火','水','木','金','土','日'][$dow];
            $dowClass = ['','mon','tue','wed','thu','fri','sat','sun'][$dow];

            $p = $plan[$ymd] ?? ['on'=>false,'time'=>'','end'=>'LAST'];
            $isOn = (bool)$p['on'];
            $t = $p['time'] !== '' ? $p['time'] : $defaultStart;
            $end = $p['end'] ?: 'LAST';
            $endIsLast = (strtoupper($end) === 'LAST');
          ?>
          <div class="day" data-ymd="<?= h($ymd) ?>">
            <div class="dayHead">
              <div class="dayTitle">
                <span class="date"><?= h($ymd) ?></span>
                <span class="dow <?= h($dowClass) ?>"><?= h($dowJp) ?></span>
              </div>

              <div class="onpill">
                <label class="switch">
                  <input type="checkbox"
                         name="on_<?= h($ymd) ?>"
                         value="1"
                         <?= $isOn ? 'checked' : '' ?>
                         onchange="toggleDay('<?= h($ymd) ?>', this.checked)">
                  <span class="onLabel <?= $isOn ? 'on' : 'off' ?> js-onlabel-<?= h($ymd) ?>"><?= $isOn ? 'ON' : 'OFF' ?></span>
                </label>
              </div>
            </div>

            <div class="dayBody">
              <div class="field">
                <div class="fieldTop">
                  <div class="lbl">開始</div>
                  <div class="hint">例: 20:00</div>
                </div>
                <input class="inp"
                       type="time"
                       id="time_<?= h($ymd) ?>"
                       name="time_<?= h($ymd) ?>"
                       value="<?= h($t) ?>"
                       step="60"
                       <?= $isOn ? '' : 'disabled' ?>>
              </div>

              <div class="field">
                <div class="fieldTop">
                  <div class="lbl">終了</div>
                  <div class="hint">LAST / 時刻</div>
                </div>

                <div class="endRow">
                  <button type="button"
                          class="mini <?= $endIsLast ? 'active' : '' ?>"
                          id="endbtn_last_<?= h($ymd) ?>"
                          onclick="setEndMode('<?= h($ymd) ?>','LAST')">LAST</button>

                  <button type="button"
                          class="mini <?= !$endIsLast ? 'active' : '' ?>"
                          id="endbtn_time_<?= h($ymd) ?>"
                          onclick="setEndMode('<?= h($ymd) ?>','TIME')">時刻</button>

                  <input class="inp"
                         type="time"
                         id="endtime_<?= h($ymd) ?>"
                         name="end_time_<?= h($ymd) ?>"
                         value="<?= !$endIsLast && preg_match('/^\d{2}:\d{2}$/',$end) ? h($end) : '' ?>"
                         step="60"
                         <?= ($isOn && !$endIsLast) ? '' : 'disabled' ?>>
                </div>

                <input type="hidden"
                       id="endmode_<?= h($ymd) ?>"
                       name="end_<?= h($ymd) ?>"
                       value="<?= h($endIsLast ? 'LAST' : ($end !== '' ? $end : 'LAST')) ?>">
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="saveBar">
        <button class="saveBtn" type="submit">💾 保存</button>
      </div>
    </form>

  </div>
</div>

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
  max-width: 920px;
  margin: 0 auto;
  padding: 14px 12px 40px;
}

.hero{
  background: linear-gradient(135deg, rgba(124,92,255,.15), rgba(255,95,162,.10));
  border:1px solid rgba(124,92,255,.18);
  border-radius: 18px;
  padding: 14px;
  box-shadow: var(--shadow2);
}

.heroTop{
  display:flex;
  align-items:flex-end;
  justify-content:space-between;
  gap:12px;
  flex-wrap:wrap;
}

.h1{
  font-weight:1000;
  font-size: 18px;
  color: var(--ink);
  display:flex;
  align-items:center;
  gap:8px;
}
.sub{
  margin-top:4px;
  font-size:12px;
  color: var(--muted);
}

.weekNav{
  display:flex;
  gap:10px;
  align-items:center;
  flex-wrap:wrap;
}
.pill{
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding:10px 12px;
  border-radius: 14px;
  border:1px solid var(--line);
  background: rgba(255,255,255,.8);
  backdrop-filter: blur(8px);
  box-shadow: var(--shadow2);
}
.btn{
  appearance:none;
  border:1px solid var(--line);
  background: rgba(255,255,255,.95);
  color: var(--ink);
  padding: 10px 14px;
  border-radius: 14px;
  font-weight:800;
  cursor:pointer;
  text-decoration:none;
  box-shadow: var(--shadow2);
}
.btn:active{ transform: translateY(1px); }
.btn.primary{
  border-color: rgba(124,92,255,.25);
  background: linear-gradient(135deg, rgba(124,92,255,.18), rgba(255,95,162,.12));
}
.btn.ghost{
  background: rgba(255,255,255,.6);
}
.btn:disabled{ opacity:.55; cursor:not-allowed; }

.notice{
  margin-top:10px;
  padding:10px 12px;
  border-radius:14px;
  border:1px solid var(--line);
  background: rgba(255,255,255,.85);
}
.notice.ok{ border-color: rgba(52,211,153,.35); background: rgba(52,211,153,.10); }
.notice.ng{ border-color: rgba(239,68,68,.35); background: rgba(239,68,68,.10); }

.tools{
  margin-top:10px;
  display:flex;
  gap:10px;
  flex-wrap:wrap;
}
.toolBtn{
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding:10px 12px;
  border-radius:14px;
  border:1px solid var(--line);
  background: rgba(255,255,255,.85);
  box-shadow: var(--shadow2);
  cursor:pointer;
  font-weight:800;
}
.toolBtn small{ font-weight:700; color: var(--muted); }

.list{
  margin-top: 12px;
  display:flex;
  flex-direction:column;
  gap:10px;
}

/* day card */
.day{
  border:1px solid var(--line);
  border-radius: 18px;
  background: rgba(255,255,255,.92);
  box-shadow: var(--shadow);
  overflow:hidden;
}

.dayHead{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  padding: 12px 12px 10px;
  border-bottom: 1px solid rgba(15,23,42,.06);
  background: linear-gradient(180deg, rgba(255,255,255,.95), rgba(255,255,255,.85));
}

.dayTitle{
  display:flex;
  align-items:baseline;
  gap:10px;
  flex-wrap:wrap;
}
.dayTitle .date{
  font-weight:1000;
  font-size:16px;
}
.dayTitle .dow{
  font-weight:1000;
  font-size:14px;
  padding:3px 10px;
  border-radius:999px;
  border:1px solid var(--line);
  background: rgba(255,255,255,.8);
}
.dow.mon{ color:#2563eb; border-color:rgba(37,99,235,.18); background: rgba(37,99,235,.06); }
.dow.tue{ color:#7c3aed; border-color:rgba(124,58,237,.18); background: rgba(124,58,237,.06); }
.dow.wed{ color:#059669; border-color:rgba(5,150,105,.18); background: rgba(5,150,105,.06); }
.dow.thu{ color:#0ea5e9; border-color:rgba(14,165,233,.18); background: rgba(14,165,233,.06); }
.dow.fri{ color:#f97316; border-color:rgba(249,115,22,.18); background: rgba(249,115,22,.06); }
.dow.sat{ color:#ec4899; border-color:rgba(236,72,153,.18); background: rgba(236,72,153,.06); }
.dow.sun{ color:#ef4444; border-color:rgba(239,68,68,.18); background: rgba(239,68,68,.06); }

.onpill{
  display:inline-flex;
  align-items:center;
  gap:10px;
  padding:8px 10px;
  border-radius: 999px;
  border:1px solid var(--line);
  background: rgba(255,255,255,.8);
}

.switch{
  display:flex;
  align-items:center;
  gap:10px;
  user-select:none;
}
.switch input{
  width:20px; height:20px;
  accent-color: var(--mint);
}

.onLabel{
  font-weight:1000;
}
.onLabel.on{ color:#059669; }
.onLabel.off{ color:#ef4444; }

.dayBody{
  padding: 12px;
  display:grid;
  grid-template-columns: 1fr 1fr;
  gap:10px;
}
@media (max-width: 520px){
  .dayBody{ grid-template-columns: 1fr; }
}

.field{
  border:1px solid var(--line);
  background: rgba(255,255,255,.85);
  border-radius: 16px;
  padding: 10px 10px;
}

.fieldTop{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  margin-bottom: 8px;
}
.fieldTop .lbl{
  font-size:12px;
  color: var(--muted);
  font-weight:900;
  letter-spacing:.02em;
}
.fieldTop .hint{
  font-size:12px;
  color: var(--muted);
  opacity:.9;
}

.inp{
  width:100%;
  padding: 12px 12px;
  border-radius: 14px;
  border:1px solid rgba(15,23,42,.12);
  background: #fff;
  font-size: 16px;
  font-weight:900;
  color: var(--ink);
}

.endRow{
  display:flex;
  gap:8px;
  align-items:center;
}
.endRow .mini{
  flex:0 0 auto;
  padding:10px 10px;
  border-radius: 14px;
  border:1px solid rgba(15,23,42,.12);
  background:#fff;
  font-weight:900;
  cursor:pointer;
}
.endRow .mini.active{
  border-color: rgba(124,92,255,.35);
  background: rgba(124,92,255,.10);
}
.endRow .inp{ flex:1 1 auto; }

.saveBar{
  position: sticky;
  bottom: 10px;
  margin-top: 14px;
  display:flex;
  justify-content:flex-end;
  gap:10px;
}
.saveBtn{
  padding: 12px 16px;
  border-radius: 16px;
  border:1px solid rgba(124,92,255,.28);
  background: linear-gradient(135deg, rgba(124,92,255,.22), rgba(255,95,162,.14));
  font-weight:1000;
  box-shadow: var(--shadow);
}

.muted{ color: var(--muted); font-size:12px; }
</style>
<script>
function toggleDay(ymd, isOn){
  const t = document.getElementById('time_'+ymd);
  const endMode = document.getElementById('endmode_'+ymd);
  const endTime = document.getElementById('endtime_'+ymd);
  const lbl = document.querySelector('.js-onlabel-'+CSS.escape(ymd));

  if (t) t.disabled = !isOn;

  // end は ON のときだけ有効（modeがTIMEのときは time入力も有効）
  const modeVal = endMode ? (endMode.value || 'LAST') : 'LAST';
  if (endMode) endMode.disabled = !isOn;
  if (endTime) endTime.disabled = (!isOn || modeVal === 'LAST');

  if (lbl){
    lbl.textContent = isOn ? 'ON' : 'OFF';
    lbl.classList.toggle('on', isOn);
    lbl.classList.toggle('off', !isOn);
  }
}

function setEndMode(ymd, mode){
  const endMode = document.getElementById('endmode_'+ymd);
  const endTime = document.getElementById('endtime_'+ymd);
  const bLast = document.getElementById('endbtn_last_'+ymd);
  const bTime = document.getElementById('endbtn_time_'+ymd);

  if (!endMode) return;

  if (mode === 'LAST'){
    endMode.value = 'LAST';
    if (endTime){ endTime.value = ''; endTime.disabled = true; }
    if (bLast) bLast.classList.add('active');
    if (bTime) bTime.classList.remove('active');
  } else {
    // TIME
    if (endTime && endTime.value && /^\d{2}:\d{2}$/.test(endTime.value)){
      endMode.value = endTime.value;
    } else {
      // 入力が空ならとりあえず LAST 相当（保存時はnoteが空）
      endMode.value = 'LAST';
    }
    if (endTime){ endTime.disabled = false; endTime.focus(); }
    if (bLast) bLast.classList.remove('active');
    if (bTime) bTime.classList.add('active');
  }
}

function allOn(){
  document.querySelectorAll('div.day').forEach(day=>{
    const ymd = day.getAttribute('data-ymd');
    const cb = day.querySelector('input[type="checkbox"][name="on_'+ymd+'"]');
    if (cb){ cb.checked = true; toggleDay(ymd, true); }
  });
}
function allOff(){
  document.querySelectorAll('div.day').forEach(day=>{
    const ymd = day.getAttribute('data-ymd');
    const cb = day.querySelector('input[type="checkbox"][name="on_'+ymd+'"]');
    if (cb){ cb.checked = false; toggleDay(ymd, false); }
  });
}
function fillDefault(){
  const base = <?= json_encode($defaultStart, JSON_UNESCAPED_UNICODE) ?> || '20:00';
  document.querySelectorAll('input[id^="time_"]').forEach(inp=>{
    if (!inp.disabled) inp.value = base;
  });
}

// TIMEモード時：time入力が変わったら hidden(endmode)に反映
document.querySelectorAll('input[id^="endtime_"]').forEach(inp=>{
  inp.addEventListener('change', ()=>{
    const ymd = inp.id.replace('endtime_','');
    const endMode = document.getElementById('endmode_'+ymd);
    const bLast = document.getElementById('endbtn_last_'+ymd);
    const bTime = document.getElementById('endbtn_time_'+ymd);
    if (inp.value && /^\d{2}:\d{2}$/.test(inp.value)){
      if (endMode) endMode.value = inp.value;
      if (bLast) bLast.classList.remove('active');
      if (bTime) bTime.classList.add('active');
    }
  });
});

// 初期：OFFの行は入力をdisable
document.querySelectorAll('div.day').forEach(day=>{
  const ymd = day.getAttribute('data-ymd');
  const cb = day.querySelector('input[type="checkbox"][name="on_'+ymd+'"]');
  if (cb) toggleDay(ymd, cb.checked);
});
</script>

<?php render_page_end(); ?>