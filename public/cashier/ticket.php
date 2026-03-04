<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/layout.php';

$storeLib = __DIR__ . '/../../app/store.php';
if (is_file($storeLib)) require_once $storeLib;

require_login();
require_role(['admin','manager','staff','super_user']); // 必要なら調整

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$pdo = db();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$ticket_id = (int)($_GET['ticket_id'] ?? 0);
$barcode   = trim((string)($_GET['barcode'] ?? ''));

/** barcode から ticket_id 解決（tickets.barcode_value を使用） */
if ($ticket_id <= 0 && $barcode !== '') {
  $st = $pdo->prepare("SELECT id FROM tickets WHERE barcode_value=? LIMIT 1");
  $st->execute([$barcode]);
  $ticket_id = (int)($st->fetchColumn() ?: 0);
}
if ($ticket_id <= 0) {
  header('Location: /seika-app/public/cashier/index.php');
  exit;
}

/** 伝票取得（haruto_core の columns に合わせる） */
$st = $pdo->prepare("
  SELECT
    id, store_id, business_date,
    paper_slip_no, paper_ticket_no,
    barcode_value,
    status,
    totals_snapshot,
    opened_at, closed_at, locked_at, locked_by,
    created_by,
    created_at, updated_at
  FROM tickets
  WHERE id=?
  LIMIT 1
");
$st->execute([$ticket_id]);
$ticket = $st->fetch(PDO::FETCH_ASSOC);
if (!$ticket) {
  header('Location: /seika-app/public/cashier/index.php');
  exit;
}

$store_id = (int)$ticket['store_id'];

/** 店舗名 */
$storeName = '#'.$store_id;
try {
  $st = $pdo->prepare("SELECT name, business_day_start FROM stores WHERE id=? LIMIT 1");
  $st->execute([$store_id]);
  $store = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  if (!empty($store['name'])) $storeName = (string)$store['name'];
} catch (Throwable $e) { /* 無視 */ }

/** totals_snapshot から payload/bill を復元（無ければ初期化） */
$snap = [];
if (!empty($ticket['totals_snapshot'])) {
  $x = json_decode((string)$ticket['totals_snapshot'], true);
  if (is_array($x)) $snap = $x;
}

$payload = $snap['payload'] ?? [];
if (!is_array($payload)) $payload = [];

$payload += [
  'start_time' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('H:i'),
  'discount' => 0,
  'sets' => [[
    'kind' => 'full50',     // full50 / half25
    'guest_people' => 1,
    'vip' => 0,
    'seat_id' => 0,
    'started_at' => '',
    'ends_at' => '',
    'customers' => new stdClass(),
    'drinks' => [],
  ]],
  'shimei' => [
    // { cast_user_id, shimei_type: normal|jounai, set_kind: full50|half25, people }
  ],
  'history' => [],
];

$bill = $snap['bill'] ?? [];
if (!is_array($bill)) $bill = [];
$bill += [
  'subtotal_ex' => 0,
  'tax' => 0,
  'total' => 0,
  'shimei_fee' => 0,
  'shimei_hon' => 0,
];

/** キャスト一覧（取れなければ空でも動くようにする） */
$casts = [];
try {
  // ※ users / user_roles / roles の構造が違っても、失敗しても ticket.php は表示されるようにしてる
  $st = $pdo->prepare("
    SELECT u.id, COALESCE(u.name, u.display_name, u.username, CONCAT('user#',u.id)) AS name
    FROM user_roles ur
    JOIN roles r ON r.id=ur.role_id AND r.code='cast'
    JOIN users u ON u.id=ur.user_id
    WHERE ur.store_id=?
    ORDER BY name ASC
  ");
  $st->execute([$store_id]);
  $casts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $casts = [];
}

/** 右上 */
$right = '
  <a class="btn" href="/seika-app/public/cashier/index.php?store_id='.(int)$store_id.'">一覧</a>
  <a class="btn" href="/seika-app/public/dashboard.php">ダッシュボード</a>
';

render_page_start('伝票');
render_header('伝票', [
  'back_href' => '/seika-app/public/cashier/index.php?store_id=' . (int)$store_id,
  'back_label' => '← 一覧',
  'right_html' => $right,
]);

?>
<style>
.card{ border:1px solid var(--line); background:var(--cardA); border-radius:16px; }
.muted{ opacity:.75; }
.inp{
  width:100%;
  padding:10px 12px; border-radius:12px; border:1px solid var(--line);
  background: var(--cardA); color:inherit;
}
.btn{
  display:inline-flex; align-items:center; justify-content:center; gap:8px;
  padding:10px 14px; border-radius:12px; border:1px solid var(--line);
  background:var(--cardA); color:inherit; text-decoration:none; cursor:pointer;
}
.btn.primary{ border-color: rgba(59,130,246,.45); background: rgba(59,130,246,.16); }
.btn.good{ border-color: rgba(34,197,94,.45); background: rgba(34,197,94,.14); }
.btn.warn{ border-color: rgba(245,158,11,.45); background: rgba(245,158,11,.18); }

.grid2{ display:grid; grid-template-columns: 1fr 1fr; gap:10px; }
.grid3{ display:grid; grid-template-columns: 1fr 1fr 1fr; gap:10px; }
@media (max-width: 820px){ .grid2, .grid3{ grid-template-columns: 1fr; } }

.badge{
  display:inline-flex; align-items:center; justify-content:center;
  padding:6px 10px; border-radius:999px; border:1px solid var(--line);
  font-weight:1000; font-size:12px;
}
.badge.open{ background: rgba(34,197,94,.12); border-color: rgba(34,197,94,.25); }
.badge.locked{ background: rgba(245,158,11,.12); border-color: rgba(245,158,11,.25); }
.badge.paid{ background: rgba(59,130,246,.12); border-color: rgba(59,130,246,.25); }
.badge.void{ background: rgba(239,68,68,.12); border-color: rgba(239,68,68,.25); }

.shimeiRow{
  border:1px solid var(--line);
  background: var(--cardB);
  border-radius:14px;
  padding:10px;
  display:grid;
  grid-template-columns: 1.2fr 1fr 1fr 1fr auto;
  gap:8px;
  align-items:center;
}
@media (max-width: 820px){
  .shimeiRow{ grid-template-columns: 1fr 1fr; }
  .shimeiRow .full{ grid-column: 1 / -1; }
}
.smallbtn{ padding:8px 10px; border-radius:12px; }
</style>

<div class="page">
  <!-- ヘッダ -->
  <div class="card" style="padding:14px;">
    <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:flex-start;">
      <div>
        <div style="font-weight:1000;font-size:18px;">🧾 伝票 #<?= (int)$ticket_id ?></div>
        <div class="muted" style="margin-top:4px;font-size:12px;">
          店舗：<b><?= h($storeName) ?></b> /
          営業日：<b><?= h((string)$ticket['business_date']) ?></b> /
          紙：<b><?= h((string)($ticket['paper_slip_no'] ?? '—')) ?></b> /
          barcode：<b><?= h((string)($ticket['barcode_value'] ?? '—')) ?></b>
        </div>
        <div class="muted" style="margin-top:2px;font-size:12px;">
          opened_at: <?= h((string)$ticket['opened_at']) ?> / updated_at: <?= h((string)$ticket['updated_at']) ?>
        </div>
      </div>

      <div style="text-align:right;min-width:220px;">
        <?php $stt=(string)$ticket['status']; ?>
        <div class="badge <?= h($stt) ?>"><?= h($stt) ?></div>
        <div class="muted" style="font-size:12px;margin-top:10px;">合計</div>
        <div style="font-weight:1000;font-size:22px;" id="sumTotal">¥<?= number_format((int)$bill['total']) ?></div>
        <div class="muted" style="font-size:12px;">(税 ¥<span id="sumTax"><?= number_format((int)$bill['tax']) ?></span>)</div>
        <div class="muted" style="font-size:12px;margin-top:6px;">
          指名料 ¥<span id="sumShimeiFee"><?= number_format((int)$bill['shimei_fee']) ?></span> /
          指名本数 <span id="sumShimeiHon"><?= h((string)$bill['shimei_hon']) ?></span>
        </div>
      </div>
    </div>
  </div>

  <!-- 基本 -->
  <div class="card" style="padding:14px;margin-top:14px;">
    <div class="grid2">
      <label>
        <div class="muted">開始時刻</div>
        <input class="inp" id="start_time" type="time" step="60" value="<?= h((string)$payload['start_time']) ?>">
      </label>
      <label>
        <div class="muted">値引き（円）</div>
        <input class="inp" id="discount" type="number" inputmode="numeric" min="0" value="<?= (int)$payload['discount'] ?>">
      </label>
    </div>

    <div class="grid3" style="margin-top:10px;">
      <label>
        <div class="muted">人数（セット1）</div>
        <input class="inp" id="guest_people" type="number" inputmode="numeric" min="0" value="<?= (int)($payload['sets'][0]['guest_people'] ?? 1) ?>">
      </label>
      <label>
        <div class="muted">VIP（セット1）</div>
        <select class="inp" id="vip">
          <option value="0" <?= empty($payload['sets'][0]['vip'])?'selected':'' ?>>OFF</option>
          <option value="1" <?= !empty($payload['sets'][0]['vip'])?'selected':'' ?>>ON</option>
        </select>
      </label>
      <label>
        <div class="muted">種別（セット1）</div>
        <select class="inp" id="kind">
          <?php
            $k = (string)($payload['sets'][0]['kind'] ?? 'full50');
            $opts = [
              'full50'=>'通常50',
              'half25'=>'ハーフ25',
            ];
            foreach ($opts as $key=>$lab){
              $sel = ($k===$key) ? 'selected' : '';
              echo '<option value="'.h($key).'" '.$sel.'>'.h($lab).'</option>';
            }
          ?>
        </select>
      </label>
    </div>
  </div>

  <!-- 指名 -->
  <div class="card" style="padding:14px;margin-top:14px;">
    <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-end;flex-wrap:wrap;">
      <div>
        <div style="font-weight:1000;">🎯 指名</div>
        <div class="muted" style="font-size:12px;margin-top:4px;">
          ルール：フル=指名料1000円（本指名1本/場内0.5本）、ハーフ=指名料500円（本指名0.5本/場内0.5本）
        </div>
      </div>
      <button class="btn good" type="button" id="btnAddShimei">＋ 指名追加</button>
    </div>

    <div id="shimeiList" style="margin-top:12px;"></div>
  </div>

  <!-- 操作 -->
  <div class="card" style="padding:14px;margin-top:14px;">
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <button class="btn primary" type="button" id="btnCalc">計算</button>
      <button class="btn primary" type="button" id="btnSave">保存</button>
      <a class="btn" href="/seika-app/public/cashier/ticket.php?ticket_id=<?= (int)$ticket_id ?>">再読込</a>
    </div>
    <div class="muted" id="msg" style="margin-top:10px;"></div>
  </div>
</div>

<script>
(function(){
  const ticketId = <?= (int)$ticket_id ?>;

  const CASTS = <?= json_encode($casts, JSON_UNESCAPED_UNICODE) ?>;
  const INITIAL = <?= json_encode($payload, JSON_UNESCAPED_UNICODE) ?>;

  let state = {
    start_time: INITIAL.start_time || '20:00',
    discount: parseInt(INITIAL.discount || 0, 10) || 0,
    sets: Array.isArray(INITIAL.sets) ? INITIAL.sets : [],
    shimei: Array.isArray(INITIAL.shimei) ? INITIAL.shimei : [],
    history: Array.isArray(INITIAL.history) ? INITIAL.history : [],
  };

  function readBaseInputs(){
    state.start_time = document.getElementById('start_time').value || '20:00';
    state.discount = parseInt(document.getElementById('discount').value || '0', 10) || 0;

    const guest_people = parseInt(document.getElementById('guest_people').value || '1', 10) || 1;
    const vip = document.getElementById('vip').value === '1' ? 1 : 0;
    const kind = document.getElementById('kind').value || 'full50';

    state.sets = [{
      kind,
      guest_people,
      vip,
      seat_id: 0,
      started_at: "",
      ends_at: "",
      customers: {},
      drinks: []
    }];
  }

  function buildPayload(){
    readBaseInputs();
    return {
      start_time: state.start_time,
      discount: state.discount,
      sets: state.sets,
      shimei: state.shimei,
      history: state.history,
    };
  }

  async function callApi(path, bodyObj){
    const res = await fetch(path, {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      credentials: 'same-origin',
      body: JSON.stringify(bodyObj),
    });
    const text = await res.text();
    let j=null; try{ j=JSON.parse(text); }catch(e){}
    if(!res.ok) throw new Error((j && (j.error || j.message)) ? (j.error || j.message) : ('HTTP '+res.status+': '+text.slice(0,200)));
    return j || {};
  }

  const msg = document.getElementById('msg');

  function castOptionsHtml(selectedId){
    if(!Array.isArray(CASTS) || CASTS.length===0){
      return `<option value="">（キャスト一覧取得失敗：IDを手入力してください）</option>`;
    }
    const opts = CASTS.map(c=>{
      const id = String(c.id);
      const name = String(c.name || ('user#'+id));
      const sel = (String(selectedId)===id) ? 'selected' : '';
      return `<option value="${id}" ${sel}>${escapeHtml(name)} (#${id})</option>`;
    });
    return `<option value="">選択</option>` + opts.join('');
  }

  function escapeHtml(s){
    return String(s)
      .replaceAll('&','&amp;')
      .replaceAll('<','&lt;')
      .replaceAll('>','&gt;')
      .replaceAll('"','&quot;')
      .replaceAll("'","&#039;");
  }

  function renderShimei(){
    const wrap = document.getElementById('shimeiList');
    wrap.innerHTML = '';

    if(!state.shimei || state.shimei.length===0){
      wrap.innerHTML = `<div class="muted">指名なし</div>`;
      return;
    }

    state.shimei.forEach((e, idx)=>{
      const castId = e.cast_user_id ?? '';
      const type = e.shimei_type ?? 'normal';
      const setKind = e.set_kind ?? (state.sets?.[0]?.kind || 'full50');
      const people = e.people ?? 1;

      const row = document.createElement('div');
      row.className = 'shimeiRow';

      row.innerHTML = `
        <div class="full">
          <div class="muted" style="font-size:12px;">キャスト</div>
          <select class="inp js-cast">${castOptionsHtml(castId)}</select>
          <div class="muted" style="font-size:11px;margin-top:4px;">一覧が出ない場合：下のID手入力を使ってください</div>
          <input class="inp js-cast-id" type="number" inputmode="numeric" placeholder="cast_user_id" value="${castId!==''?String(castId):''}" style="margin-top:6px;">
        </div>

        <div>
          <div class="muted" style="font-size:12px;">種類</div>
          <select class="inp js-type">
            <option value="normal" ${type==='normal'?'selected':''}>本指名</option>
            <option value="jounai" ${type==='jounai'?'selected':''}>場内</option>
          </select>
        </div>

        <div>
          <div class="muted" style="font-size:12px;">セット</div>
          <select class="inp js-setkind">
            <option value="full50" ${setKind==='full50'?'selected':''}>通常50</option>
            <option value="half25" ${setKind==='half25'?'selected':''}>ハーフ25</option>
          </select>
        </div>

        <div>
          <div class="muted" style="font-size:12px;">人数分</div>
          <input class="inp js-people" type="number" inputmode="numeric" min="1" value="${parseInt(people||1,10)||1}">
        </div>

        <div>
          <button class="btn warn smallbtn js-del" type="button">削除</button>
        </div>
      `;

      // handlers
      const selCast = row.querySelector('.js-cast');
      const inpCastId = row.querySelector('.js-cast-id');
      selCast.addEventListener('change', ()=>{
        inpCastId.value = selCast.value;
        state.shimei[idx].cast_user_id = selCast.value ? parseInt(selCast.value,10) : '';
      });
      inpCastId.addEventListener('input', ()=>{
        const v = inpCastId.value.trim();
        state.shimei[idx].cast_user_id = v==='' ? '' : parseInt(v,10);
      });

      row.querySelector('.js-type').addEventListener('change', (ev)=>{
        state.shimei[idx].shimei_type = ev.target.value;
      });
      row.querySelector('.js-setkind').addEventListener('change', (ev)=>{
        state.shimei[idx].set_kind = ev.target.value;
      });
      row.querySelector('.js-people').addEventListener('input', (ev)=>{
        const v = parseInt(ev.target.value||'1',10)||1;
        state.shimei[idx].people = Math.max(1, v);
      });
      row.querySelector('.js-del').addEventListener('click', ()=>{
        state.shimei.splice(idx,1);
        renderShimei();
      });

      wrap.appendChild(row);
    });
  }

  document.getElementById('btnAddShimei').addEventListener('click', ()=>{
    // デフォは「今選択中のセット種別」に合わせる
    const currentSetKind = document.getElementById('kind').value || 'full50';
    state.shimei.push({
      cast_user_id: '',
      shimei_type: 'normal',
      set_kind: currentSetKind,
      people: 1,
    });
    renderShimei();
  });

  document.getElementById('btnCalc').addEventListener('click', async ()=>{
    try{
      msg.textContent = '計算中…';
      const payload = buildPayload();
      const j = await callApi('/seika-app/public/api/ticket_calc.php', {payload});
      if(j && j.ok){
        const b = j.bill || {};
        document.getElementById('sumTotal').textContent = '¥' + (b.total || 0).toLocaleString();
        document.getElementById('sumTax').textContent = (b.tax || 0).toLocaleString();
        document.getElementById('sumShimeiFee').textContent = (b.shimei_fee || 0).toLocaleString();
        document.getElementById('sumShimeiHon').textContent = String(b.shimei_hon ?? 0);
        msg.textContent = '計算OK';
      }else{
        msg.textContent = '計算失敗';
      }
    }catch(e){
      msg.textContent = '計算エラー: ' + e.message;
    }
  });

  document.getElementById('btnSave').addEventListener('click', async ()=>{
    try{
      msg.textContent = '保存中…';
      const payload = buildPayload();
      const j = await callApi('/seika-app/public/api/ticket_save.php', {ticket_id: ticketId, payload});
      if(j && j.ok){
        // ticket_save 側が totals/tax を返す想定（返さなくても壊れない）
        if (typeof j.total !== 'undefined') document.getElementById('sumTotal').textContent = '¥' + (j.total || 0).toLocaleString();
        if (typeof j.tax !== 'undefined') document.getElementById('sumTax').textContent = (j.tax || 0).toLocaleString();
        if (typeof j.shimei_fee !== 'undefined') document.getElementById('sumShimeiFee').textContent = (j.shimei_fee || 0).toLocaleString();
        if (typeof j.shimei_hon !== 'undefined') document.getElementById('sumShimeiHon').textContent = String(j.shimei_hon ?? 0);
        msg.textContent = '保存OK（totals_snapshot に反映）';
      }else{
        msg.textContent = '保存失敗';
      }
    }catch(e){
      msg.textContent = '保存エラー: ' + e.message;
    }
  });

  // 初期表示
  renderShimei();
})();
</script>

<?php render_page_end(); ?>