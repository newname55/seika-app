<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';

require_login();
require_role(['cast']);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

/** CSRF（プロジェクト側があればそれを使う / 無ければ簡易） */
function csrf_token_local(): string {
  if (function_exists('csrf_token')) return (string)csrf_token();
  if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
  return (string)$_SESSION['csrf_token'];
}

$pdo = db();

/** 自分の user_id */
$me = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);

/** cast の所属店舗を1つ解決（セッション優先→DB） */
function resolve_cast_store_id(PDO $pdo, int $userId): int {
  $sid = (int)($_SESSION['store_id'] ?? 0);
  if ($sid > 0) return $sid;

  $st = $pdo->prepare("
    SELECT ur.store_id
    FROM user_roles ur
    JOIN roles r ON r.id = ur.role_id AND r.code = 'cast'
    WHERE ur.user_id = ?
      AND ur.store_id IS NOT NULL
    ORDER BY ur.store_id ASC
    LIMIT 1
  ");
  $st->execute([$userId]);
  $sid = (int)($st->fetchColumn() ?: 0);
  if ($sid > 0) $_SESSION['store_id'] = $sid;
  return $sid;
}

/** 営業日（business_day_start で日付をずらす） */
function business_date_for_store(string $businessDayStart, ?DateTimeImmutable $now = null): string {
  $tz = new DateTimeZone('Asia/Tokyo');
  $now = $now ?: new DateTimeImmutable('now', $tz);

  $cut = preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $businessDayStart) ? $businessDayStart : '06:00:00';
  if (strlen($cut) === 5) $cut .= ':00';

  $cutDT = new DateTimeImmutable($now->format('Y-m-d') . ' ' . $cut, $tz);
  $biz = ($now < $cutDT) ? $now->modify('-1 day') : $now;
  return $biz->format('Y-m-d');
}

/** ===== 今日状態を作る ===== */
$storeId = ($me > 0) ? resolve_cast_store_id($pdo, $me) : 0;

$storeName = '-';
$bizDate = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
$bizStart = '06:00:00';

$statusLabel = '未出勤';
$statusClass = 'st-none';
$clockIn = null;
$clockOut = null;

if ($storeId > 0) {
  $st = $pdo->prepare("SELECT name, business_day_start FROM stores WHERE id=? LIMIT 1");
  $st->execute([$storeId]);
  $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

  $storeName = (string)($row['name'] ?? ('#' . $storeId));
  $bizStart  = (string)($row['business_day_start'] ?? '06:00:00');
  $bizDate   = business_date_for_store($bizStart);

  // ★★★ ここで店舗コンテキストを確定させる ★★★
  sync_store_context($pdo, $storeId, $storeName);

  $st = $pdo->prepare("
    SELECT business_date, clock_in, clock_out, status
    FROM attendances
    WHERE user_id=? AND store_id=? AND business_date=?
    LIMIT 1
  ");
  $st->execute([$me, $storeId, $bizDate]);
  $a = $st->fetch(PDO::FETCH_ASSOC);

  if ($a) {
    $clockIn  = $a['clock_in'] ?? null;
    $clockOut = $a['clock_out'] ?? null;

    if (!empty($clockOut)) {
      $statusLabel = '退勤済';
      $statusClass = 'st-done';
    } elseif (!empty($clockIn)) {
      $statusLabel = '出勤中';
      $statusClass = 'st-working';
    } else {
      $statusLabel = '未出勤';
      $statusClass = 'st-none';
    }
  }
}
/** store.php / layout.php の“店舗表示”を確実に合わせる */
function sync_store_context(PDO $pdo, int $storeId, string $storeName): void {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();

  // 1) よくあるキーを全埋め
  $_SESSION['store_id'] = $storeId;
  $_SESSION['current_store_id'] = $storeId;
  $_SESSION['store_name'] = $storeName;

  $_SESSION['store'] = [
    'id'   => $storeId,
    'name' => $storeName,
  ];

  $_SESSION['store_selected'] = 1;

  // 2) さらにありがちな別名も埋める（layout/store の実装差を吸収）
  $_SESSION['selected_store_id'] = $storeId;
  $_SESSION['selected_store'] = ['id'=>$storeId, 'name'=>$storeName];
  $_SESSION['storeId'] = $storeId;

  // 3) store.php 側に「店舗確定」系の関数があるなら、引数数に合わせて呼ぶ
  //    （これをやるとヘッダーの #0 が直ることが多い）
  $call = function(string $fn, array $args) {
    if (!function_exists($fn)) return;
    try {
      $rf = new ReflectionFunction($fn);
      $need = $rf->getNumberOfRequiredParameters();
      $use = array_slice($args, 0, max($need, 0));
      $rf->invokeArgs($use);
    } catch (Throwable $e) {
      // ここで落とさない（同期だけは残す）
    }
  };

  // “よくある名前”を片っ端から（存在するやつだけ実行される）
  $call('set_current_store_id', [$storeId]);
  $call('set_selected_store_id', [$storeId]);
  $call('set_store_id', [$storeId]);
  $call('set_store_selected', [$storeId, $storeName]);

  // require_store_selected_safe / require_store_selected が PDO 必須になってても対応
  // storeId は入ってるので、ここで「store.phpの正規手順」を通してヘッダー用の状態を確定させる
  $call('require_store_selected_safe', [$pdo]);
  $call('require_store_selected', [$pdo]);
}

render_page_start('キャスト');
render_header('キャスト');
?>
<div class="page">
  <div class="admin-wrap" style="max-width:560px;">

    <!-- ✅ 今日の状態 + 出勤/退勤 -->
    <div class="card">
      <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;flex-wrap:wrap;">
        <div>
          <div style="font-weight:1000; font-size:18px;">📌 今日の状態</div>
          <div class="muted" style="margin-top:4px;">
            店舗：<b><?= h($storeName) ?></b>
            <?php if ($storeId > 0): ?>(#<?= (int)$storeId ?>)<?php endif; ?>
          </div>
          <div class="muted" style="margin-top:2px;font-size:12px;">
            営業日：<?= h($bizDate) ?>（切替 <?= h($bizStart) ?>）
          </div>
        </div>

        <div class="status <?= h($statusClass) ?>">
          <?= h($statusLabel) ?>
        </div>
      </div>

      <div class="muted" style="margin-top:10px; font-size:12px; line-height:1.6;">
        <?php if ($storeId <= 0): ?>
          所属店舗が見つかりません。管理者に「キャストの店舗所属」を設定してもらってください。
        <?php else: ?>
          <?php if ($clockIn): ?>出勤：<?= h((string)$clockIn) ?><br><?php endif; ?>
          <?php if ($clockOut): ?>退勤：<?= h((string)$clockOut) ?><br><?php endif; ?>
          <?php if (!$clockIn && !$clockOut): ?>まだ出勤記録がありません。<?php endif; ?>
        <?php endif; ?>
      </div>

      <!-- ✅ 出勤/退勤ボタン（本人LINEに位置情報要求） -->
      <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;">
        <button class="btn primary" type="button" onclick="geoReq('clock_in')" <?= $storeId<=0?'disabled':'' ?>>
          ✅ 出勤（LINEで位置情報）
        </button>
        <button class="btn" type="button" onclick="geoReq('clock_out')" <?= $storeId<=0?'disabled':'' ?>>
          🟦 退勤（LINEで位置情報）
        </button>
      </div>

      <div id="geoMsg" class="muted" style="margin-top:10px;"></div>

      <input type="hidden" id="csrf" value="<?= h(csrf_token_local()) ?>">
      <input type="hidden" id="store_id" value="<?= (int)$storeId ?>">
    </div>

    <div class="card" style="margin-top:14px;">
      <div style="font-weight:1000; font-size:18px;">👤 キャストメニュー</div>
      <div class="muted" style="margin-top:4px;">スマホ1画面で完結</div>
    </div>

    <div class="card-grid cast-grid" style="margin-top:14px;">
      <a class="card big" href="/seika-app/public/cast_my_schedule.php">
        <div class="icon">🗓</div>
        <b>今週の予定</b>
      </a>

      <a class="card big" href="/seika-app/public/cast_week.php">
        <div class="icon">📅</div>
        <b>出勤予定（提出）</b>
      </a>

      <a class="card big" href="/seika-app/public/customer.php">
        <div class="icon">📝</div>
        <b>顧客管理（営業ノート）</b>
      </a>
      <a class="card big" href="/seika-app/public/profile.php">
        <div class="icon">👤</div>
        <b>プロフィール</b>
      </a>

      <a class="card big" href="/seika-app/public/help.php">
        <div class="icon">❓</div>
        <b>ヘルプ</b>
      </a>
    </div>

  </div>
</div>

<style>
.card-grid{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:12px;
}
.card{
  padding:14px;
  border:1px solid var(--line);
  border-radius:14px;
  background:var(--cardA);
  text-decoration:none;
  color:inherit;
}
.card:hover{ transform:translateY(-2px); box-shadow:0 10px 24px rgba(0,0,0,.18); }
.card .icon{ font-size:22px; margin-bottom:6px; }
.cast-grid .card.big{ padding:22px; text-align:center; }
@media (max-width:420px){
  .card-grid{ grid-template-columns:1fr; }
}

/* 今日の状態バッジ */
.status{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  padding:10px 14px;
  border-radius:999px;
  border:1px solid var(--line);
  font-weight:1000;
  min-width:110px;
}
.st-none{ background: rgba(148,163,184,.18); }
.st-working{ background: rgba(34,197,94,.18); }
.st-done{ background: rgba(59,130,246,.18); }

.btn{
  display:inline-flex;align-items:center;justify-content:center;gap:8px;
  padding:12px 14px;border-radius:12px;border:1px solid var(--line);
  background:var(--cardA);color:inherit;cursor:pointer;font-weight:1000;
}
.btn.primary{background:rgba(59,130,246,.18);border-color:rgba(59,130,246,.35)}
.btn:disabled{opacity:.45;cursor:not-allowed}

.muted{opacity:.75;font-size:13px}
</style>

<script>
async function geoReq(action){
  const msg = document.getElementById('geoMsg');
  msg.textContent = 'LINEに送信中…';

  const body = new URLSearchParams();
  body.set('csrf_token', document.getElementById('csrf').value);
  body.set('store_id', document.getElementById('store_id').value);
  body.set('action', action);

  try{
    const res = await fetch('/seika-app/public/api/attendance_geo_request.php', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: body.toString()
    });
    const text = await res.text();
    let j = null;
    try { j = JSON.parse(text); } catch(e) {}

    if (!res.ok){
      msg.textContent = '送信失敗: ' + (j && j.error ? j.error : text);
      return;
    }

    if (j && j.ok){
      msg.textContent = '送信OK。LINEを開いて「位置情報を送る」を押してね。';
      return;
    }
    msg.textContent = '送信OK: ' + text;
  } catch(e){
    msg.textContent = '通信エラー: ' + (e && e.message ? e.message : String(e));
  }
}
</script>

<?php render_page_end(); ?>