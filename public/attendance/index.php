<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/layout.php';
require_once __DIR__ . '/../../app/attendance.php';

$storeLib = __DIR__ . '/../../app/store.php';
if (is_file($storeLib)) require_once $storeLib;

require_login();
require_role(['admin','manager','super_user']);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$pdo = db();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$store_id = (int)($_GET['store_id'] ?? 0);
if ($store_id <= 0 && function_exists('att_safe_store_id')) $store_id = (int)att_safe_store_id();

if ($store_id <= 0) {
  header('Location: /seika-app/public/store_select.php?next=' . urlencode($_SERVER['REQUEST_URI']));
  exit;
}

$store = att_fetch_store($pdo, $store_id);
$storeName = $store['name'] ?? ('#' . $store_id);

// 日付
$date = (string)($_GET['date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

$rows = att_get_daily_rows($pdo, $store_id, $date);

// =========================
// 予定（cast_shift_plans）を取得
// =========================
$plannedMap = [];   // [user_id] => 'HH:MM'
$plannedCount = 0;

$st = $pdo->prepare("
  SELECT user_id, start_time
  FROM cast_shift_plans
  WHERE store_id = :store_id
    AND business_date = :d
    AND status = 'planned'
    AND is_off = 0
    AND start_time IS NOT NULL
");
$st->execute([
  ':store_id' => $store_id,
  ':d' => $date,
]);

while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
  $uid = (int)$r['user_id'];
  $hm  = substr((string)$r['start_time'], 0, 5);
  $plannedMap[$uid] = $hm;
}
$plannedCount = count($plannedMap);

// KPI
$total = count($rows);
$inCount = 0;
$outCount = 0;
$lateCount = 0;

foreach ($rows as $r) {
  $in  = (string)($r['in_at'] ?? '');
  $out = (string)($r['out_at'] ?? '');
  if ($in !== '' && $out === '') $inCount++;
  if ($out !== '') $outCount++;
  if ((int)($r['is_late'] ?? 0) === 1) $lateCount++;
}


// =========================
// 予定の人を上に並べる
// =========================
usort($rows, function($a, $b) use ($plannedMap) {
  // user_id のキー名は実データに合わせる
$aid = (int)($a['cast_id'] ?? $a['user_id'] ?? $a['id'] ?? 0);
$bid = (int)($b['cast_id'] ?? $b['user_id'] ?? $b['id'] ?? 0);

  $ap = array_key_exists($aid, $plannedMap) ? 1 : 0;
  $bp = array_key_exists($bid, $plannedMap) ? 1 : 0;

  // ① 予定ありを上へ
  if ($ap !== $bp) return $bp <=> $ap;

  // ② 同じなら名前順（任意）
  $an = (string)($a['display_name'] ?? $a['name'] ?? '');
  $bn = (string)($b['display_name'] ?? $b['name'] ?? '');
  return strcmp($an, $bn);
});

$csrf = att_csrf_token();

$right = '
  <a class="btn" href="/seika-app/public/dashboard.php">ダッシュボード</a>
  <a class="btn" href="/seika-app/public/admin/index.php">管理</a>
';

render_page_start('出勤');
render_header('出勤', [
  'back_href' => '/seika-app/public/dashboard.php',
  'back_label' => '← ダッシュボード',
  'right_html' => $right,
]);
?>
<style>
.page .card, .card{
  border:1px solid var(--line);
  background: var(--cardA);
  border-radius: 16px;
}
.muted{ opacity:.75; font-size:12px; }
.h1{ font-weight:1000; font-size:18px; }
.sub{ margin-top:4px; font-size:12px; opacity:.75; }

.top{ display:flex; gap:12px; align-items:flex-end; justify-content:space-between; flex-wrap:wrap; }
.ctrl{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
.inp{
  padding:10px 12px; border-radius:12px;
  border:1px solid var(--line);
  background: var(--cardA);
  color: inherit;
}
.btn{
  display:inline-flex; align-items:center; justify-content:center;
  gap:8px;
  padding:10px 14px;
  border-radius:12px;
  border:1px solid var(--line);
  background: var(--cardA);
  color: inherit;
  text-decoration:none;
  cursor:pointer;
  user-select:none;
}
.btn:disabled{ opacity:.55; cursor:not-allowed; }
.btn.primary{ border-color: rgba(59,130,246,.45); background: rgba(59,130,246,.16); }
.btn.good{ border-color: rgba(34,197,94,.45); background: rgba(34,197,94,.14); }
.btn.warn{ border-color: rgba(245,158,11,.45); background: rgba(245,158,11,.18); }

.kpis{
  margin-top:12px;
  display:grid;
  grid-template-columns: repeat(4, minmax(140px, 1fr));
  gap:10px;
}
@media (max-width: 820px){ .kpis{ grid-template-columns: repeat(2, minmax(140px, 1fr)); } }
.kpi{
  border:1px solid var(--line);
  background: var(--cardB);
  border-radius:16px;
  padding:10px 12px;
}
.kpi .n{ font-weight:1000; font-size:20px; margin-top:4px; }

.dot{ width:10px; height:10px; border-radius:999px; display:inline-block; }
.dot.ok{ background: #22c55e; }
.dot.warn{ background: #f59e0b; }
.dot.pri{ background: #3b82f6; }

.list{
  margin-top:14px;
  display:grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  gap:10px;
}
@media (max-width: 720px){ .list{ grid-template-columns: 1fr; } }

.row{
  border:1px solid var(--line);
  background: var(--cardA);
  border-radius:16px;
  padding:12px;
}
.rowHead{ display:flex; gap:10px; align-items:flex-start; justify-content:space-between; }
.name{ font-weight:1000; font-size:16px; line-height:1.2; }
.tags{ margin-top:6px; display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.tag{
  font-size:12px;
  padding:4px 10px;
  border-radius:999px;
  border:1px solid var(--line);
  background: var(--cardB);
  white-space:nowrap;
}
.tag.in{ border-color: rgba(34,197,94,.45); background: rgba(34,197,94,.14); }
.tag.out{ border-color: rgba(59,130,246,.45); background: rgba(59,130,246,.16); }
.tag.late{ border-color: rgba(245,158,11,.45); background: rgba(245,158,11,.18); }

.times{
  margin-top:10px;
  display:grid;
  grid-template-columns: 1fr 1fr;
  gap:10px;
}
.tbox{
  border:1px solid var(--line);
  background: var(--cardB);
  border-radius:14px;
  padding:10px 12px;
}
.tbox .lbl{ font-size:12px; opacity:.75; }
.tbox .val{ margin-top:4px; font-weight:1000; }

.actions{
  margin-top:10px;
  display:grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap:8px;
}
@media (max-width: 420px){ .actions{ grid-template-columns: 1fr; } }

.memoWrap{ margin-top:10px; display:none; }
.memoWrap.open{ display:block; }
.memoRow{ display:flex; gap:8px; align-items:center; }
.memoRow .inp{ flex:1; }
.small{ font-size:12px; }

/* 状態でカード背景を薄く色分け */
.row.state-none{
  background: rgba(245, 158, 11, .08); /* 薄オレンジ */
  border-color: rgba(245, 158, 11, .18);
}
.row.state-in{
  background: rgba(59, 130, 246, .08); /* 薄水色 */
  border-color: rgba(59, 130, 246, .18);
}
.row.state-out{
  background: rgba(148, 163, 184, .12); /* 薄グレー */
  border-color: rgba(148, 163, 184, .22);
}

/* 右上：勤務時間 */
.worktime{
  font-size:12px;
  font-weight:1000;
  opacity:.9;
  padding:6px 10px;
  border-radius:999px;
  border:1px solid var(--line);
  background: var(--cardB);
  white-space:nowrap;
}
/* ===== 時刻手動入力（IN/OUT） ===== */
.timeGrid{
  margin-top: 10px;
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}

/* 手動IN/OUT時刻：2段レイアウト（JSクラスはそのまま） */
.timeEdit{
  display:block; /* memoRowがflexでも崩れないように */
}
.timeEdit .timeLine{
  display:flex;
  gap:8px;
  align-items:center;
}
.timeEdit .timeLine .inp{
  flex:1;
  min-width:0; /* はみ出し防止 */
}
.timeEdit .timeLine .btn{
  flex:0 0 auto;
  white-space:nowrap;
  padding:10px 12px; /* 既存のbtnに合わせつつ短め */
}
.badge-row{display:flex;gap:8px;align-items:center;justify-content:flex-end}
.badge{display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:999px;font-size:12px;border:1px solid rgba(0,0,0,.08)}
.badge-plan{background:rgba(34,197,94,.12);border-color:rgba(34,197,94,.25);color:#166534;font-weight:700}
.badge-none{background:rgba(0,0,0,.04);color:rgba(0,0,0,.55)}
</style>

<div class="page">
  <div class="card" style="padding:14px;">
    <div class="top">
      <div>
        <div class="h1">🟢 出勤一覧</div>
        <div class="sub">店舗：<b><?= h($storeName) ?></b> / 日付：<b><?= h($date) ?></b></div>
      </div>

      <div class="ctrl">
        <form method="get" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin:0;">
          <input type="hidden" name="store_id" value="<?= (int)$store_id ?>">
          <input class="inp" type="date" name="date" value="<?= h($date) ?>">
          <button class="btn primary" type="submit">表示</button>
        </form>
        <a class="btn" href="/seika-app/public/attendance/index.php?store_id=<?= (int)$store_id ?>">今日</a>
      </div>
    </div>

    <div class="kpis">
      <div class="kpi">
        <div class="muted"><span class="dot ok"></span> 予定</div>
        <div class="n"><?= (int)$plannedCount ?></div>
      </div>

      <div class="kpi">
        <div class="muted"><span class="dot ok"></span> 出勤中</div>
        <div class="n js-kpi-in"><?= (int)$inCount ?></div>
      </div>

      <div class="kpi">
        <div class="muted"><span class="dot pri"></span> 退勤済</div>
        <div class="n js-kpi-out"><?= (int)$outCount ?></div>
      </div>

      <div class="kpi">
        <div class="muted"><span class="dot warn"></span> 遅刻</div>
        <div class="n js-kpi-late"><?= (int)$lateCount ?></div>
      </div>
    </div>

    <div class="muted" style="margin-top:10px;">
      基本は<b>見るだけ</b>。<b>例外</b>の時だけ「例外対応」を開いて修正してください（履歴は監査ログに残ります）。
    </div>
  </div>

  <div class="list">
    <?php if (!$rows): ?>
      <div class="card" style="padding:14px;">
        <div class="muted">キャストが見つかりません。先に「管理 → キャスト編集」で登録してください。</div>
      </div>
    <?php endif; ?>

    <?php foreach ($rows as $r): ?>
      <?php
        $in = (string)($r['in_at'] ?? '');
        $out = (string)($r['out_at'] ?? '');
        $late = ((int)($r['is_late'] ?? 0) === 1);

        $stateTag = '未出勤';
        $stateKey = 'none';
        $stateClass = '';

        if ($in !== '' && $out === '') { $stateTag='出勤中'; $stateKey='in'; $stateClass='in'; }
        if ($out !== '') { $stateTag='退勤'; $stateKey='out'; $stateClass='out'; }

        $planned = (string)($r['planned_start'] ?? '');
        $plannedDisp = $planned !== '' ? substr($planned,0,5) : '—';

        $inDisp = $in !== '' ? substr($in, 11, 5) : '—';
        $outDisp = $out !== '' ? substr($out, 11, 5) : '—';

        // time input 用（HH:MM）
        $inVal = $in !== '' ? substr($in, 11, 5) : '';
        $outVal = $out !== '' ? substr($out, 11, 5) : '';

        $castId = (int)$r['cast_id'];
        $employment = (string)($r['employment'] ?? '');

        // 勤務時間（サーバ側の初期表示）
        $workDisp = '';
        if ($in !== '' && $out !== '') {
          try {
            $inTs = strtotime($in);
            $outTs = strtotime($out);
            if ($inTs !== false && $outTs !== false) {
              if ($outTs < $inTs) $outTs += 86400;
              $mins = (int)floor(($outTs - $inTs) / 60);
              $h = intdiv($mins, 60);
              $m = $mins % 60;
              $workDisp = sprintf('%d:%02d', $h, $m);
            }
          } catch (Throwable $e) {}
        }
      ?>
      <div class="row state-<?= h($stateKey) ?>"
           data-cast-id="<?= $castId ?>"
           data-state="<?= h($stateKey) ?>"
           data-late="<?= $late ? '1':'0' ?>"
      >
        <div class="rowHead">
          <div>
            <div class="name">
              <?= h((string)$r['name']) ?>
              <?php if ((string)($r['shop_tag'] ?? '') !== ''): ?>
                <span class="tag"><?= h((string)$r['shop_tag']) ?></span>
              <?php endif; ?>
            </div>

            <div class="tags">
              <span class="tag <?= h($stateClass) ?> js-state"><?= h($stateTag) ?></span>
              <?php if ($late): ?><span class="tag late js-late">遅刻</span><?php endif; ?>
              <?php if ($employment !== ''): ?><span class="tag js-emp"><?= h($employment) ?></span><?php endif; ?>
            </div>
          </div>

          <div style="text-align:right; display:flex; flex-direction:column; gap:8px; align-items:flex-end;">
            <?php
              $uid = (int)($r['cast_id'] ?? $r['user_id'] ?? $r['id'] ?? 0);
              $planHm = $plannedMap[$uid] ?? null;
            ?>
            <div class="badge-row">
              <?php if ($planHm !== null): ?>
                <span class="badge badge-plan">予定 <?= h($planHm) ?></span>
              <?php else: ?>
                <span class="badge badge-none">予定 —</span>
              <?php endif; ?>
            </div>

            <div class="worktime js-work"><?= h($workDisp !== '' ? ('勤務 '.$workDisp) : '') ?></div>
          </div>
        </div><!-- /rowHead -->

        <div class="times">
          <div class="tbox">
            <div class="lbl">IN</div>
            <div class="val js-in"><?= h($inDisp) ?></div>
          </div>
          <div class="tbox">
            <div class="lbl">OUT</div>
            <div class="val js-out"><?= h($outDisp) ?></div>
          </div>
        </div>

        <div style="margin-top:10px;">
          <button class="btn js-memo-toggle" type="button" style="width:100%;">例外対応（メモ / 手動修正）</button>

          <div class="memoWrap">
            <div class="actions">
              <button class="btn good js-in-btn" type="button">出勤（IN）</button>
              <button class="btn primary js-out-btn" type="button">退勤（OUT）</button>
              <button class="btn warn js-late-btn" type="button">遅刻</button>
            </div>

            <!-- ✅ 時刻手動入力（2段：IN / OUT） -->
            <div class="memoRow timeEdit" style="margin-top:10px;">
              <div class="timeLine">
                <input class="inp js-time-in" type="time" value="<?= h($inVal) ?>" step="60" style="min-width:110px;">
                <button class="btn primary js-time-in-save" type="button">出勤保存</button>
              </div>

              <div class="timeLine" style="margin-top:8px;">
                <input class="inp js-time-out" type="time" value="<?= h($outVal) ?>" step="60" style="min-width:110px;">
                <button class="btn primary js-time-out-save" type="button">退勤保存</button>
              </div>
            </div>

            <div class="muted small" style="margin-top:6px;">
              空にして保存するとクリアできます（操作は監査ログに残ります）
            </div>

            <div class="memoRow" style="margin-top:10px;">
              <input class="inp js-memo" type="text" maxlength="255" placeholder="例）21:30到着 / 体調不良など" value="<?= h((string)($r['memo'] ?? '')) ?>">
              <button class="btn primary js-memo-save" type="button">メモ保存</button>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
(function(){
  const csrf = <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>;
  const storeId = <?= (int)$store_id ?>;
  const date = <?= json_encode($date, JSON_UNESCAPED_UNICODE) ?>;

  async function post(mode, castId, extra){
    const fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('mode', mode);
    fd.append('cast_id', String(castId));
    fd.append('date', date);
    if(extra){
      for(const k of Object.keys(extra)) fd.append(k, extra[k]);
    }

    const res = await fetch('/seika-app/public/api/attendance_toggle.php?store_id=' + encodeURIComponent(storeId), {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
    });

    const text = await res.text();
    let j = null;
    try { j = JSON.parse(text); } catch(e) {}

    if(!res.ok){
      throw new Error((j && (j.error || j.message)) ? (j.error || j.message) : ('HTTP ' + res.status + ': ' + text.slice(0,200)));
    }
    if(!j || !j.ok){
      throw new Error((j && (j.error || j.message)) ? (j.error || j.message) : ('Bad response: ' + text.slice(0,200)));
    }
    return j;
  }

  function calcWork(inAt, outAt){
    if(!inAt || !outAt) return '';
    const inHM  = String(inAt).slice(11,16);
    const outHM = String(outAt).slice(11,16);
    if(inHM.length !== 5 || outHM.length !== 5) return '';

    const [ih, im] = inHM.split(':').map(n=>parseInt(n,10));
    const [oh, om] = outHM.split(':').map(n=>parseInt(n,10));
    if(Number.isNaN(ih)||Number.isNaN(im)||Number.isNaN(oh)||Number.isNaN(om)) return '';

    let inMin = ih*60 + im;
    let outMin = oh*60 + om;
    if(outMin < inMin) outMin += 24*60; // 日跨ぎ

    const diff = outMin - inMin;
    const h = Math.floor(diff/60);
    const m = diff % 60;
    return `勤務 ${h}:${String(m).padStart(2,'0')}`;
  }

  function updateKpis(){
    const rows = Array.from(document.querySelectorAll('.row'));
    let inCount = 0, outCount = 0, lateCount = 0;

    for(const r of rows){
      const st = r.dataset.state || 'none';
      const late = r.dataset.late === '1';
      if(st === 'in') inCount++;
      if(st === 'out') outCount++;
      if(late) lateCount++;
    }

    const elTotal = document.querySelector('.js-kpi-total');
    const elIn = document.querySelector('.js-kpi-in');
    const elOut = document.querySelector('.js-kpi-out');
    const elLate = document.querySelector('.js-kpi-late');

    if(elTotal) elTotal.textContent = String(rows.length);
    if(elIn) elIn.textContent = String(inCount);
    if(elOut) elOut.textContent = String(outCount);
    if(elLate) elLate.textContent = String(lateCount);
  }

  function renderRow(el, data){
    const inAt  = data.in_at  ? String(data.in_at)  : '';
    const outAt = data.out_at ? String(data.out_at) : '';

    // 表示
    el.querySelector('.js-in').textContent  = inAt  ? inAt.slice(11,16)  : '—';
    el.querySelector('.js-out').textContent = outAt ? outAt.slice(11,16) : '—';

    // state
    const isIn  = !!inAt && !outAt;
    const isOut = !!outAt;
    const stateKey = isOut ? 'out' : (isIn ? 'in' : 'none');
    const stateText = isOut ? '退勤' : (isIn ? '出勤中' : '未出勤');

    // late
    const late = (data.is_late === 1 || data.is_late === '1');

    // dataset / card class（色 & KPI）
    el.dataset.state = stateKey;
    el.dataset.late = late ? '1' : '0';
    el.classList.remove('state-in','state-out','state-none');
    el.classList.add('state-' + stateKey);

    // tags（雇用タグ等は壊さない）
    const tags = el.querySelector('.tags');
    const old = Array.from(tags.querySelectorAll('.tag'));
    const keep = old.filter(x => x.classList.contains('late') || (x.textContent && x.textContent.trim() !== '未出勤' && x.textContent.trim() !== '出勤中' && x.textContent.trim() !== '退勤'));
    tags.innerHTML = '';

    const stateSpan = document.createElement('span');
    stateSpan.className = 'tag ' + (stateKey === 'out' ? 'out' : (stateKey === 'in' ? 'in' : ''));
    stateSpan.textContent = stateText;
    tags.appendChild(stateSpan);

    if (late){
      const lateSpan = document.createElement('span');
      lateSpan.className = 'tag late';
      lateSpan.textContent = '遅刻';
      tags.appendChild(lateSpan);
    }

    for(const x of keep){
      if(x.classList.contains('late')) continue;
      const sp = document.createElement('span');
      sp.className = x.className;
      sp.textContent = x.textContent;
      tags.appendChild(sp);
    }

    // time inputs
    const tin = el.querySelector('.js-time-in');
    const tout = el.querySelector('.js-time-out');
    if (tin)  tin.value  = inAt  ? inAt.slice(11,16)  : '';
    if (tout) tout.value = outAt ? outAt.slice(11,16) : '';

    // worktime
    const w = el.querySelector('.js-work');
    if (w) w.textContent = calcWork(inAt, outAt);

    // KPI即更新
    updateKpis();
  }

  document.querySelectorAll('.row').forEach(row=>{
    const castId = parseInt(row.getAttribute('data-cast-id'), 10);

    row.querySelector('.js-in-btn').addEventListener('click', async ()=>{
      const btn = row.querySelector('.js-in-btn');
      try{
        btn.disabled = true;
        const j = await post('in', castId);
        renderRow(row, j);
      }catch(e){ alert('出勤(IN)失敗: ' + e.message); }
      finally{ btn.disabled = false; }
    });

    row.querySelector('.js-out-btn').addEventListener('click', async ()=>{
      const btn = row.querySelector('.js-out-btn');
      try{
        btn.disabled = true;
        const j = await post('out', castId);
        renderRow(row, j);
      }catch(e){ alert('退勤(OUT)失敗: ' + e.message); }
      finally{ btn.disabled = false; }
    });

    row.querySelector('.js-late-btn').addEventListener('click', async ()=>{
      const btn = row.querySelector('.js-late-btn');
      try{
        btn.disabled = true;
        const j = await post('late', castId);
        renderRow(row, j);
      }catch(e){ alert('遅刻更新失敗: ' + e.message); }
      finally{ btn.disabled = false; }
    });

    const memoWrap = row.querySelector('.memoWrap');
    row.querySelector('.js-memo-toggle').addEventListener('click', ()=>{
      memoWrap.classList.toggle('open');
    });

    row.querySelector('.js-memo-save').addEventListener('click', async ()=>{
      const btn = row.querySelector('.js-memo-save');
      try{
        btn.disabled = true;
        const memo = row.querySelector('.js-memo').value || '';
        const j = await post('memo', castId, {memo});
        renderRow(row, j);
      }catch(e){ alert('メモ保存失敗: ' + e.message); }
      finally{ btn.disabled = false; }
    });

    // set_time: IN
    row.querySelector('.js-time-in-save').addEventListener('click', async ()=>{
      const btn = row.querySelector('.js-time-in-save');
      try{
        btn.disabled = true;
        const t = row.querySelector('.js-time-in').value || '';
        const j = await post('set_time', castId, {target:'in', time:t});
        renderRow(row, j);
      }catch(e){ alert('IN時刻反映失敗: ' + e.message); }
      finally{ btn.disabled = false; }
    });

    // set_time: OUT
    row.querySelector('.js-time-out-save').addEventListener('click', async ()=>{
      const btn = row.querySelector('.js-time-out-save');
      try{
        btn.disabled = true;
        const t = row.querySelector('.js-time-out').value || '';
        const j = await post('set_time', castId, {target:'out', time:t});
        renderRow(row, j);
      }catch(e){ alert('OUT時刻反映失敗: ' + e.message); }
      finally{ btn.disabled = false; }
    });
  });

  // 初期KPIをDOM基準で整合させる
  updateKpis();
})();
</script>

<?php render_page_end(); ?>