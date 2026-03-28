<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/layout.php';
require_once __DIR__ . '/../../app/transport_map.php';

require_login();
require_role(['manager', 'admin', 'super_user', ROLE_ALL_STORE_SHIFT_VIEW]);

$pdo = db();
$err = '';
$stores = [];
$selectedStoreId = 0;
$storeName = '';
$businessDate = '';
$driversByStore = [];
$initialFilters = [];
$canViewAllStores = false;

try {
  $stores = transport_allowed_stores($pdo);
  $canViewAllStores = transport_map_can_view_all_stores() && count($stores) > 1;
  $initialFilters = transport_map_filters_from_request($pdo, $_GET);
  $selectedStoreId = (int)$initialFilters['store_id'];
  if ($selectedStoreId > 0) {
    $storeRow = transport_map_fetch_store_row($pdo, $selectedStoreId);
    $storeName = (string)($storeRow['name'] ?? '');
  } else {
    $storeName = '全店舗';
  }
  $businessDate = (string)$initialFilters['business_date'];

  foreach ($stores as $store) {
    $sid = (int)($store['id'] ?? 0);
    if ($sid <= 0) {
      continue;
    }
    $driversByStore[$sid] = transport_map_fetch_driver_options($pdo, $sid);
  }
  if ($canViewAllStores) {
    $driversByStore['all'] = transport_map_fetch_driver_options_for_stores($pdo, array_map(static fn(array $store): int => (int)($store['id'] ?? 0), $stores));
  }
} catch (Throwable $e) {
  $err = $e->getMessage();
}

$pageConfig = [
  'apiUrl' => '/wbss/public/api/transport_map.php',
  'autoAssignUrl' => '/wbss/public/api/transport/auto_assign.php',
  'optimizeRouteUrl' => '/wbss/public/api/transport/optimize_route.php',
  'pagePath' => '/wbss/public/transport/map_screen.php',
  'initialFilters' => $initialFilters,
  'statusOptions' => transport_map_status_definitions(),
  'directionOptions' => transport_map_direction_options(),
  'storeShortLabels' => transport_map_store_short_labels(),
  'csrfToken' => csrf_token(),
  'focusCastId' => 0,
  'currentStoreId' => $selectedStoreId,
];

$dashboardUrl = transport_map_dashboard_url($selectedStoreId);
$normalMapUrl = $selectedStoreId > 0
  ? '/wbss/public/transport/map.php?store_id=' . (int)$selectedStoreId . '&business_date=' . urlencode($businessDate)
  : ($canViewAllStores
      ? '/wbss/public/transport/map.php?store_id=all&business_date=' . urlencode($businessDate)
      : $dashboardUrl);

render_page_start('送迎マップ TV');
?>
<link rel="stylesheet" href="/wbss/public/assets/css/transport-map.css?v=20260327ah">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" crossorigin="">
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" crossorigin="">

<div class="page transportMapScreenViewport">
  <div class="admin-wrap transportMapPage transportMapScreenPage">
    <section class="transportMapScreenTopBar">
      <div class="transportMapScreenTopBarMain">
        <div class="transportMapScreenTopBarTitle">送迎マップ TV</div>
        <div class="transportMapScreenTopBarMeta">
          <span><?= h($storeName !== '' ? $storeName : '-') ?></span>
          <span><?= h($businessDate !== '' ? $businessDate : '-') ?></span>
          <span>60秒ごと更新</span>
        </div>
      </div>
      <div class="transportMapScreenTopBarActions">
        <a class="miniBtn" href="<?= h($dashboardUrl) ?>">ダッシュボード</a>
        <a class="miniBtn" href="<?= h($normalMapUrl) ?>">通常表示</a>
        <button type="button" class="miniBtn" id="transportMapAutoRefreshToggle">自動更新ON</button>
        <?php if ($selectedStoreId > 0): ?>
          <a class="miniBtn" href="/wbss/public/transport/driver_location.php?store_id=<?= (int)$selectedStoreId ?>">現在地送信</a>
        <?php endif; ?>
      </div>
    </section>

    <?php if ($err !== ''): ?>
      <div class="card transportMapAlert transportMapAlertError"><?= h($err) ?></div>
    <?php endif; ?>

    <section class="transportMapScreenTop transportPanel">
      <form id="transportMapFilterForm" class="transportMapScreenForm" method="get" action="/wbss/public/transport/map_screen.php">
        <label class="field transportMapScreenInlineField">
          <span class="fieldLabel">店舗</span>
          <select class="sel" name="store_id" id="transportMapStore">
            <?php if ($canViewAllStores): ?>
              <option value="all" <?= $selectedStoreId === 0 ? 'selected' : '' ?>>全店舗</option>
            <?php endif; ?>
            <?php foreach ($stores as $store): ?>
              <?php $sid = (int)($store['id'] ?? 0); ?>
              <option value="<?= $sid ?>" <?= $sid === $selectedStoreId ? 'selected' : '' ?>>
                <?= h((string)($store['name'] ?? '')) ?> (#<?= $sid ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label class="field transportMapScreenInlineField transportMapScreenInlineField-date">
          <span class="fieldLabel">営業日</span>
          <input class="sel" type="date" name="business_date" value="<?= h($businessDate) ?>">
        </label>

        <input type="hidden" name="time_from" value="<?= h((string)($initialFilters['time_from'] ?? '')) ?>">
        <input type="hidden" name="time_to" value="<?= h((string)($initialFilters['time_to'] ?? '')) ?>">
        <input type="hidden" name="status" value="<?= h((string)($initialFilters['status'] ?? '')) ?>">
        <input type="hidden" name="driver_user_id" value="<?= h((string)($initialFilters['driver_user_id'] ?? '0')) ?>">
        <input type="hidden" name="direction_bucket" value="<?= h((string)($initialFilters['direction_bucket'] ?? '')) ?>">
        <input type="hidden" name="unassigned_only" value="<?= h((string)($initialFilters['unassigned_only'] ?? '0')) ?>">

        <div class="transportMapScreenActions">
          <button type="submit" class="btn">条件反映</button>
          <button type="button" class="btn btn-primary" id="transportMapReload">再読込</button>
        </div>
      </form>
      <div class="transportMapScreenCompactTools">
        <div class="transportMapScreenCompactLabel">TV自動提案</div>
        <div class="transportMapScreenCompactActions">
          <button type="button" class="miniBtn" id="transportMapAutoAssign">自動提案</button>
          <button type="button" class="miniBtn" id="transportMapResetSuggestions">リセット</button>
          <button type="button" class="miniBtn miniBtn-primary" id="transportMapConfirmSuggestions">確定</button>
        </div>
      </div>
      <div class="transportMapSuggestStatus" id="transportMapSuggestStatus">未割当へ提案を出せます</div>
      <div class="transportMapSuggestRoutePanel" data-suggest-route-summary hidden>
        <div class="transportMapDriverVisibilityHead">
          <span class="transportMapMiniHint">提案ルート順</span>
          <span class="transportMapMiniHint">ドライバーごとの回収順</span>
        </div>
        <div class="transportMapSuggestRouteList" data-suggest-route-list></div>
      </div>
      <div class="transportMapDriverVisibility transportMapDriverVisibility--screen">
        <div class="transportMapDriverVisibilityHead">
          <span class="transportMapMiniHint">ドライバー表示切替</span>
          <span class="transportMapMiniHint" data-vehicle-updated>車両更新 待機中</span>
        </div>
        <div class="transportMapDriverToggleList" data-driver-toggles>
          <span class="transportMapEmptyInline">車両送信が始まると切替できます</span>
        </div>
      </div>
    </section>

    <section class="transportMapScreenCanvas transportPanel">
      <div class="transportMapCanvasHead transportMapCanvasHead--screen">
        <div class="transportMapMapBadge" id="transportMapMapBadge">読込中</div>
      </div>
      <div id="transportMapCanvas" class="transportMapCanvas transportMapCanvas--screen" aria-label="送迎マップ TV"></div>
    </section>

    <section class="transportMapScreenFooter transportPanel">
      <div class="transportMapScreenSummaryLine">
        <span class="transportMapScreenMetric">
          <span class="transportMapScreenMetricLabel">今日の件数</span>
          <b class="transportMapScreenMetricValue" data-summary-total>0</b>
        </span>
        <span class="transportMapScreenMetric transportMapScreenMetricWarn">
          <span class="transportMapScreenMetricLabel">未割当</span>
          <b class="transportMapScreenMetricValue" data-summary-unassigned>0</b>
        </span>
        <span class="transportMapScreenMetric">
          <span class="transportMapScreenMetricLabel">割当済</span>
          <b class="transportMapScreenMetricValue" data-summary-assigned>0</b>
        </span>
        <span class="transportMapScreenMetric">
          <span class="transportMapScreenMetricLabel">完了</span>
          <b class="transportMapScreenMetricValue" data-summary-done>0</b>
        </span>
      </div>
      <div class="transportMapScreenFooterRow">
        <div class="transportMapChipList" data-direction-summary>
          <span class="transportMapEmptyInline">方面別件数を読み込み中です</span>
        </div>
        <div class="transportMapFootNote" id="transportMapFootNote">緯度経度がない対象は地図に表示されません。</div>
      </div>
    </section>

    <div id="transportMapList" hidden></div>
  </div>
</div>

<script>
window.WBSS_TRANSPORT_MAP_CONFIG = <?= json_encode([
  'page' => $pageConfig,
  'driversByStore' => $driversByStore,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js" crossorigin=""></script>
<script src="/wbss/public/assets/js/transport-map.js?v=20260327an"></script>
<?php render_page_end(); ?>
