<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/layout.php';
require_once __DIR__ . '/../../app/transport_map.php';
require_once __DIR__ . '/../../app/transport_vehicle_location.php';

require_login();
require_role(['staff', 'manager', 'admin', 'super_user']);

$pdo = db();
$err = '';
$stores = [];
$storeId = 0;
$storeName = '';
$vehicleLabel = '';
$businessDate = '';
$assignedPickups = [];

try {
  $userId = (int)(current_user_id() ?? 0);
  $stores = transport_vehicle_allowed_stores($pdo, $userId);
  $storeId = transport_vehicle_resolve_store_id($pdo, $userId, (int)($_GET['store_id'] ?? 0));
  foreach ($stores as $store) {
    if ((int)($store['id'] ?? 0) === $storeId) {
      $storeName = (string)($store['name'] ?? '');
      break;
    }
  }
  $vehicleLabel = trim((string)($_GET['vehicle_label'] ?? ''));
  if ($storeId > 0) {
    $storeRow = transport_map_fetch_store_row($pdo, $storeId);
    $businessDate = transport_map_default_business_date($storeRow);
    $assignedRows = transport_map_fetch_rows($pdo, [
      'business_date' => $businessDate,
      'store_ids' => [$storeId],
      'status' => '',
      'driver_user_id' => $userId,
      'unassigned_only' => 0,
      'time_from' => '',
      'time_to' => '',
    ]);
    foreach ($assignedRows as $row) {
      $status = (string)($row['status'] ?? 'pending');
      if ($status === 'cancelled') {
        continue;
      }
      $pickupAddress = trim((string)($row['pickup_address'] ?? ''));
      $assignedPickups[] = [
        'cast_name' => trim((string)($row['pickup_name'] ?? '')) !== '' ? (string)$row['pickup_name'] : (string)($row['cast_name'] ?? '-'),
        'shop_tag' => (string)($row['shop_tag'] ?? ''),
        'pickup_time_from' => (string)($row['pickup_time_from'] ?? ''),
        'pickup_time_to' => (string)($row['pickup_time_to'] ?? ''),
        'pickup_address' => $pickupAddress,
        'pickup_note' => (string)($row['pickup_note'] ?? ''),
        'direction_bucket' => (string)($row['direction_bucket'] ?? ''),
        'status' => $status,
        'status_label' => transport_map_status_label($status),
        'status_color' => transport_map_status_color($status),
        'map_url' => $pickupAddress !== '' ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($pickupAddress) : '',
      ];
    }
  }
} catch (Throwable $e) {
  $err = $e->getMessage();
}

$pageConfig = [
  'apiUrl' => '/wbss/public/api/transport_driver_location.php',
  'csrfToken' => csrf_token(),
  'storeId' => $storeId,
  'vehicleLabel' => $vehicleLabel,
];

$rightHtml = '';
if ($storeId > 0 && (is_role('manager') || is_role('admin') || is_role('super_user'))) {
  $rightHtml = '<a class="btn" href="/wbss/public/transport/map.php?store_id=' . (int)$storeId . '">送迎マップへ</a>';
}

render_page_start('ドライバー現在地');
render_header('ドライバー現在地', [
  'back_href' => '/wbss/public/dashboard.php',
  'back_label' => '← ダッシュボード',
  'right_html' => $rightHtml,
]);
?>
<div class="page">
  <div class="admin-wrap driverLocationPage">
    <?php if ($err !== ''): ?>
      <div class="card driverLocationAlert driverLocationAlertError"><?= h($err) ?></div>
    <?php endif; ?>

    <div class="pageHero driverLocationHero">
      <div class="rowTop">
        <div class="titleWrap">
          <div class="title">現在地を送信して送迎マップに反映</div>
          <div class="heroMeta">
            <span class="heroChip">店舗 <b><?= h($storeName !== '' ? $storeName : '-') ?></b></span>
            <span class="heroChip">ドライバー <b><?= h((string)($_SESSION['display_name'] ?? $_SESSION['login_id'] ?? '-')) ?></b></span>
            <span class="heroChip">営業日 <b><?= h($businessDate !== '' ? $businessDate : '-') ?></b></span>
          </div>
        </div>
      </div>
      <div class="subInfo">
        <div class="muted">勤務中だけ位置送信をONにしてください。送信停止でいつでも止められます。</div>
      </div>
    </div>

    <section class="card driverLocationPanel">
      <form id="driverLocationForm" class="driverLocationForm">
        <label class="field">
          <span class="fieldLabel">店舗</span>
          <select class="sel" id="driverLocationStore" name="store_id">
            <?php foreach ($stores as $store): ?>
              <?php $sid = (int)($store['id'] ?? 0); ?>
              <option value="<?= $sid ?>" <?= $sid === $storeId ? 'selected' : '' ?>>
                <?= h((string)($store['name'] ?? '')) ?> (#<?= $sid ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label class="field">
          <span class="fieldLabel">車両名</span>
          <input class="sel" type="text" id="driverLocationVehicle" name="vehicle_label" maxlength="64" placeholder="例: 1号車" value="<?= h($vehicleLabel) ?>">
        </label>

        <div class="driverLocationActions">
          <button type="button" class="btn btn-primary" id="driverLocationStart">位置送信を開始</button>
          <button type="button" class="btn" id="driverLocationStop">停止</button>
        </div>
      </form>
    </section>

    <section class="driverLocationSummaryGrid">
      <article class="card driverLocationSummaryCard">
        <span>送信状態</span>
        <b id="driverLocationState">停止中</b>
      </article>
      <article class="card driverLocationSummaryCard">
        <span>最終送信</span>
        <b id="driverLocationLastSent">未送信</b>
      </article>
      <article class="card driverLocationSummaryCard">
        <span>GPS精度</span>
        <b id="driverLocationAccuracy">-</b>
      </article>
      <article class="card driverLocationSummaryCard">
        <span>現在地</span>
        <b id="driverLocationCoords">-</b>
      </article>
    </section>

    <section class="card driverLocationPanel">
      <div class="cardTitle">使い方</div>
      <ul class="driverLocationGuide">
        <li>出発前に車両名を入れて「位置送信を開始」を押します。</li>
        <li>送迎中は約15秒ごとに最新位置を送信します。</li>
        <li>勤務終了後や休憩時は「停止」を押してください。</li>
      </ul>
      <div class="driverLocationStatus" id="driverLocationMessage">位置送信の準備ができています。</div>
    </section>

    <section class="card driverLocationPanel">
      <div class="driverLocationAssignedHead">
        <div>
          <div class="cardTitle">今日の送迎確定</div>
          <div class="muted">このドライバーに割り当て済みの送迎対象を表示します。</div>
        </div>
        <div class="driverLocationAssignedMeta"><?= h($businessDate !== '' ? $businessDate : '-') ?> / <?= count($assignedPickups) ?>件</div>
      </div>

      <?php if ($assignedPickups === []): ?>
        <div class="driverLocationStatus">このドライバーに確定している送迎対象はまだありません。</div>
      <?php else: ?>
        <div class="driverLocationAssignedList">
          <?php foreach ($assignedPickups as $pickup): ?>
            <?php
              $nameParts = [];
              if ($pickup['shop_tag'] !== '') {
                $nameParts[] = '【' . $pickup['shop_tag'] . '】';
              }
              $nameParts[] = $pickup['cast_name'] !== '' ? $pickup['cast_name'] : '-';
              $timeLabel = trim(substr($pickup['pickup_time_from'], 0, 5));
              if (trim(substr($pickup['pickup_time_to'], 0, 5)) !== '') {
                $timeLabel .= ($timeLabel !== '' ? ' - ' : '') . trim(substr($pickup['pickup_time_to'], 0, 5));
              }
            ?>
            <article class="driverLocationAssignedCard">
              <div class="driverLocationAssignedCardHead">
                <div>
                  <div class="driverLocationAssignedName"><?= h(implode(' ', $nameParts)) ?></div>
                  <div class="driverLocationAssignedSub">
                    <?= h($timeLabel !== '' ? $timeLabel : '時間未設定') ?>
                    <?php if ($pickup['direction_bucket'] !== ''): ?>
                      / <?= h($pickup['direction_bucket']) ?>
                    <?php endif; ?>
                  </div>
                </div>
                <span class="driverLocationAssignedStatus" style="--driver-status-color:<?= h($pickup['status_color']) ?>">
                  <?= h($pickup['status_label']) ?>
                </span>
              </div>
              <div class="driverLocationAssignedAddress"><?= h($pickup['pickup_address'] !== '' ? $pickup['pickup_address'] : '住所未登録') ?></div>
              <?php if ($pickup['pickup_note'] !== ''): ?>
                <div class="driverLocationAssignedNote">メモ: <?= h($pickup['pickup_note']) ?></div>
              <?php endif; ?>
              <div class="driverLocationAssignedActions">
                <?php if ($pickup['map_url'] !== ''): ?>
                  <a class="btn btn-primary" href="<?= h($pickup['map_url']) ?>" target="_blank" rel="noopener noreferrer">Googleマップで開く</a>
                <?php else: ?>
                  <span class="driverLocationAssignedEmpty">住所がないため地図を開けません</span>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>
</div>

<style>
.driverLocationPage{ display:grid; gap:14px; padding-bottom:28px; }
.driverLocationHero,
.driverLocationPanel,
.driverLocationSummaryCard{
  border:1px solid rgba(15,23,42,.08);
  border-radius:18px;
  background:linear-gradient(135deg, rgba(255,255,255,.98), rgba(241,245,249,.92));
  box-shadow:0 12px 30px rgba(15,18,34,.08);
}
.driverLocationHero{ padding:18px; }
.driverLocationPanel{ padding:16px 18px; }
.driverLocationForm{ display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:12px; align-items:end; }
.driverLocationActions{ display:flex; gap:10px; align-items:center; }
.driverLocationActions .btn{ flex:1 1 0; min-height:44px; justify-content:center; }
.driverLocationSummaryGrid{ display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:10px; }
.driverLocationSummaryCard{ padding:16px; display:grid; gap:6px; }
.driverLocationSummaryCard span{ color:#64748b; font-size:12px; font-weight:700; }
.driverLocationSummaryCard b{ color:#0f172a; font-size:20px; line-height:1.2; }
.driverLocationAlert{ padding:14px 16px; }
.driverLocationAlertError{ border:1px solid rgba(239,68,68,.35); background:rgba(254,242,242,.95); color:#991b1b; }
.driverLocationGuide{ margin:0; padding-left:18px; color:#334155; display:grid; gap:6px; }
.driverLocationStatus{ margin-top:12px; padding:12px 14px; border-radius:14px; border:1px solid rgba(15,23,42,.10); background:#fff; color:#334155; font-size:13px; }
.driverLocationAssignedHead{ display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:12px; }
.driverLocationAssignedMeta{ color:#64748b; font-size:12px; font-weight:800; white-space:nowrap; }
.driverLocationAssignedList{ display:grid; gap:10px; }
.driverLocationAssignedCard{
  display:grid;
  gap:8px;
  padding:14px;
  border:1px solid rgba(15,23,42,.08);
  border-radius:16px;
  background:rgba(255,255,255,.82);
}
.driverLocationAssignedCardHead{ display:flex; align-items:flex-start; justify-content:space-between; gap:12px; }
.driverLocationAssignedName{ color:#0f172a; font-size:16px; font-weight:900; }
.driverLocationAssignedSub{ color:#64748b; font-size:12px; font-weight:700; margin-top:3px; }
.driverLocationAssignedStatus{
  display:inline-flex;
  align-items:center;
  min-height:28px;
  padding:0 10px;
  border-radius:999px;
  background:color-mix(in srgb, var(--driver-status-color) 12%, #fff);
  border:1px solid color-mix(in srgb, var(--driver-status-color) 28%, transparent);
  color:var(--driver-status-color);
  font-size:11px;
  font-weight:900;
  white-space:nowrap;
}
.driverLocationAssignedAddress{ color:#1e293b; font-size:14px; font-weight:700; word-break:break-word; }
.driverLocationAssignedNote{ color:#475569; font-size:12px; }
.driverLocationAssignedActions{ display:flex; align-items:center; gap:10px; }
.driverLocationAssignedActions .btn{ min-height:38px; }
.driverLocationAssignedEmpty{ color:#94a3b8; font-size:12px; font-weight:700; }
.driverLocationPage .field{ display:grid; gap:6px; min-width:0; }
.driverLocationPage .fieldLabel{ font-size:12px; font-weight:800; color:#475569; }
.driverLocationPage .sel{
  min-height:44px;
  padding:10px 12px;
  border-radius:12px;
  border:1px solid rgba(15,23,42,.12);
  background:#fff;
  color:#0f172a;
  font-size:14px;
  font-weight:700;
}
.driverLocationPage .btn{ min-height:44px; border-radius:12px; }
.driverLocationPage .btn-primary{ background:rgba(59,130,246,.12); border-color:rgba(59,130,246,.26); color:#1d4ed8; }
@media (max-width: 720px){
  .driverLocationForm,
  .driverLocationSummaryGrid{ grid-template-columns:1fr; }
  .driverLocationActions{ flex-direction:column; align-items:stretch; }
  .driverLocationAssignedHead,
  .driverLocationAssignedCardHead,
  .driverLocationAssignedActions{ flex-direction:column; align-items:flex-start; }
}
</style>

<script>
window.WBSS_DRIVER_LOCATION_CONFIG = <?= json_encode($pageConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
(function () {
  const config = window.WBSS_DRIVER_LOCATION_CONFIG || {};
  const form = document.getElementById('driverLocationForm');
  const startBtn = document.getElementById('driverLocationStart');
  const stopBtn = document.getElementById('driverLocationStop');
  const storeEl = document.getElementById('driverLocationStore');
  const vehicleEl = document.getElementById('driverLocationVehicle');
  const stateEl = document.getElementById('driverLocationState');
  const lastSentEl = document.getElementById('driverLocationLastSent');
  const accuracyEl = document.getElementById('driverLocationAccuracy');
  const coordsEl = document.getElementById('driverLocationCoords');
  const messageEl = document.getElementById('driverLocationMessage');
  let watchId = null;
  let lastSentAt = 0;
  let isSending = false;

  function setMessage(text) {
    if (messageEl) {
      messageEl.textContent = text;
    }
  }

  function setState(text) {
    if (stateEl) {
      stateEl.textContent = text;
    }
  }

  function stopWatching() {
    if (watchId !== null && navigator.geolocation) {
      navigator.geolocation.clearWatch(watchId);
      watchId = null;
    }
    setState('停止中');
    setMessage('位置送信を停止しました。');
  }

  async function sendPosition(position) {
    if (isSending) {
      return;
    }
    isSending = true;
    const payload = new URLSearchParams();
    payload.set('csrf_token', String(config.csrfToken || ''));
    payload.set('store_id', String(storeEl ? storeEl.value : config.storeId || '0'));
    payload.set('vehicle_label', String(vehicleEl ? vehicleEl.value : config.vehicleLabel || ''));
    payload.set('lat', String(position.coords.latitude));
    payload.set('lng', String(position.coords.longitude));
    payload.set('accuracy_m', String(position.coords.accuracy || ''));
    payload.set('heading_deg', position.coords.heading !== null ? String(position.coords.heading) : '');
    payload.set('speed_kmh', position.coords.speed !== null ? String(Math.max(0, Number(position.coords.speed || 0)) * 3.6) : '');
    payload.set('recorded_at', new Date().toISOString());

    try {
      const response = await fetch(config.apiUrl, {
        method: 'POST',
        cache: 'no-store',
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
        },
        body: payload.toString()
      });
      const json = await response.json().catch(function () { return {}; });
      if (!response.ok || !json.ok) {
        throw new Error(json.error || '位置送信に失敗しました');
      }
      lastSentAt = Date.now();
      setState('送信中');
      if (lastSentEl) {
        lastSentEl.textContent = String((json.saved && json.saved.recorded_at) || new Date().toLocaleTimeString());
      }
      if (accuracyEl) {
        accuracyEl.textContent = Math.round(position.coords.accuracy || 0) + 'm';
      }
      if (coordsEl) {
        coordsEl.textContent = position.coords.latitude.toFixed(5) + ', ' + position.coords.longitude.toFixed(5);
      }
      setMessage('現在地を送信しました。送迎マップに反映されます。');
    } catch (error) {
      setState('送信失敗');
      setMessage(String((error && error.message) || '位置送信に失敗しました'));
    } finally {
      isSending = false;
    }
  }

  function handlePosition(position) {
    if ((Date.now() - lastSentAt) < 15000) {
      return;
    }
    sendPosition(position);
  }

  function startWatching() {
    if (!navigator.geolocation) {
      setState('非対応');
      setMessage('この端末では位置情報が利用できません。');
      return;
    }
    if (watchId !== null) {
      return;
    }
    watchId = navigator.geolocation.watchPosition(handlePosition, function (error) {
      setState('送信失敗');
      setMessage('位置情報の取得に失敗しました: ' + error.message);
    }, {
      enableHighAccuracy: true,
      maximumAge: 5000,
      timeout: 10000,
    });
    setState('取得中');
    setMessage('位置情報を取得しています。');
  }

  if (startBtn) {
    startBtn.addEventListener('click', function () {
      startWatching();
    });
  }
  if (stopBtn) {
    stopBtn.addEventListener('click', function () {
      stopWatching();
    });
  }
  window.addEventListener('beforeunload', function () {
    stopWatching();
  });
})();
</script>
<?php render_page_end(); ?>
