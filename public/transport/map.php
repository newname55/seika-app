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
$focusCastId = 0;
$returnTo = '';
$returnStoreId = 0;
$returnCastId = 0;
$canViewAllStores = false;

try {
  $stores = transport_allowed_stores($pdo);
  $canViewAllStores = transport_map_can_view_all_stores() && count($stores) > 1;
  $initialFilters = transport_map_filters_from_request($pdo, $_GET);
  $focusCastId = max(0, (int)($_GET['cast_id'] ?? 0));
  $returnTo = trim((string)($_GET['return_to'] ?? ''));
  $returnStoreId = max(0, (int)($_GET['return_store_id'] ?? 0));
  $returnCastId = max(0, (int)($_GET['return_cast_id'] ?? 0));
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
  'initialFilters' => $initialFilters,
  'statusOptions' => transport_map_status_definitions(),
  'directionOptions' => transport_map_direction_options(),
  'storeShortLabels' => transport_map_store_short_labels(),
  'csrfToken' => csrf_token(),
  'focusCastId' => $focusCastId,
  'currentStoreId' => $selectedStoreId,
];

$routeReturnUrl = '';
if ($returnTo === 'routes' && $selectedStoreId > 0) {
  $routeReturnUrl = '/wbss/public/transport_routes.php?store_id=' . (int)$selectedStoreId
    . '&business_date=' . urlencode($businessDate)
    . ($returnStoreId > 0 ? '&focus_store_id=' . $returnStoreId : '')
    . ($returnCastId > 0 ? '&focus_cast_id=' . $returnCastId : '');
}

$rightHtml = '';
if ($selectedStoreId === 0 && $canViewAllStores) {
  $rightHtml .= '<a class="btn" href="/wbss/public/transport/map_screen.php?store_id=all&business_date=' . urlencode($businessDate) . '">TV表示</a> ';
}
if ($selectedStoreId > 0) {
  if ($routeReturnUrl !== '') {
    $rightHtml .= '<a class="btn btn-primary" href="' . h($routeReturnUrl) . '">ルートへ戻る</a> ';
  }
  $rightHtml .= '<a class="btn" href="/wbss/public/transport/map_screen.php?store_id=' . (int)$selectedStoreId . '&business_date=' . urlencode($businessDate) . '">TV表示</a> ';
  $rightHtml .= '<a class="btn" href="/wbss/public/transport/driver_location.php?store_id=' . (int)$selectedStoreId . '">現在地送信</a> ';
  $rightHtml .= '<a class="btn" href="/wbss/public/store_transport_bases.php?store_id=' . (int)$selectedStoreId . '">拠点設定</a> ';
  $rightHtml .= '<a class="btn" href="/wbss/public/transport_routes.php?store_id=' . (int)$selectedStoreId . '&business_date=' . urlencode($businessDate) . '">送迎ルート</a>';
}

render_page_start('送迎マップ');
render_header('送迎マップ', [
  'back_href' => $routeReturnUrl !== '' ? $routeReturnUrl : ($selectedStoreId > 0 ? '/wbss/public/transport_routes.php?store_id=' . (int)$selectedStoreId : '/wbss/public/dashboard.php'),
  'back_label' => '← 送迎導線へ',
  'right_html' => $rightHtml,
]);
?>
<link rel="stylesheet" href="/wbss/public/assets/css/transport-map.css?v=20260327u">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" crossorigin="">
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" crossorigin="">

<div class="page">
  <div class="admin-wrap transportMapPage transportShell">
    <?php if ($err !== ''): ?>
      <div class="card transportMapAlert transportMapAlertError"><?= h($err) ?></div>
    <?php endif; ?>

    <div class="pageHero dashboardStyleHero">
      <div class="rowTop">
        <div class="titleWrap">
          <div class="title">今日の送迎対象を方面と密集で把握</div>
          <div class="heroMeta">
            <span class="heroChip">店舗 <b><?= h($storeName !== '' ? $storeName : '-') ?></b></span>
            <span class="heroChip">業務日 <b><?= h($businessDate !== '' ? $businessDate : '-') ?></b></span>
            <span class="heroChip">地図と一覧が連動</span>
          </div>
        </div>
      </div>
      <div class="subInfo">
        <div class="muted">未割当、方面の偏り、店舗からの距離感を一画面で見られる初期版です。住所未ジオコードの対象も一覧に残し、あとから補完できる構成にしています。</div>
      </div>
    </div>

    <section class="transportMapFilters transportPanel">
      <form id="transportMapFilterForm" class="transportMapFilterForm" method="get" action="/wbss/public/transport/map.php">
        <div class="transportMapFilterBlock transportMapFilterBlock-primary">
          <div class="transportMapFilterBlockHead">
            <span class="transportMapFilterBlockTitle">基本条件</span>
            <span class="transportMapFilterBlockHint">対象日と時間帯を先に決めます</span>
          </div>
          <div class="transportMapFilterRow transportMapFilterRow-primary">
            <label class="field field-store">
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

            <label class="field field-date">
              <span class="fieldLabel">業務日</span>
              <input class="sel" type="date" name="business_date" value="<?= h($businessDate) ?>">
            </label>

            <label class="field field-time">
              <span class="fieldLabel">時間From</span>
              <input class="sel" type="time" name="time_from" value="<?= h(substr((string)($initialFilters['time_from'] ?? ''), 0, 5)) ?>">
            </label>

            <label class="field field-time">
              <span class="fieldLabel">時間To</span>
              <input class="sel" type="time" name="time_to" value="<?= h(substr((string)($initialFilters['time_to'] ?? ''), 0, 5)) ?>">
            </label>
          </div>
        </div>

        <div class="transportMapFilterBlock transportMapFilterBlock-secondary">
          <div class="transportMapFilterBlockHead">
            <span class="transportMapFilterBlockTitle">絞り込み</span>
            <span class="transportMapFilterBlockHint">未対応や担当別の確認に使います</span>
          </div>
          <div class="transportMapFilterRow transportMapFilterRow-secondary">
            <label class="field field-status">
              <span class="fieldLabel">ステータス</span>
              <select class="sel" name="status">
                <option value="">すべて</option>
                <?php foreach (transport_map_status_definitions() as $status => $meta): ?>
                  <option value="<?= h($status) ?>" <?= ((string)($initialFilters['status'] ?? '') === $status) ? 'selected' : '' ?>>
                    <?= h((string)$meta['label']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>

            <label class="field field-driver">
              <span class="fieldLabel">ドライバー</span>
              <select class="sel" name="driver_user_id" id="transportMapDriver">
                <option value="0">すべて</option>
                <?php foreach (($driversByStore[$selectedStoreId > 0 ? $selectedStoreId : 'all'] ?? []) as $driver): ?>
                  <?php $driverId = (int)($driver['id'] ?? 0); ?>
                  <option value="<?= $driverId ?>" <?= ((int)($initialFilters['driver_user_id'] ?? 0) === $driverId) ? 'selected' : '' ?>>
                    <?= h((string)($driver['name'] ?? '')) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>

            <label class="field field-direction">
              <span class="fieldLabel">方面</span>
              <select class="sel" name="direction_bucket">
                <option value="">すべて</option>
                <?php foreach (transport_map_direction_options() as $direction): ?>
                  <option value="<?= h($direction) ?>" <?= ((string)($initialFilters['direction_bucket'] ?? '') === $direction) ? 'selected' : '' ?>>
                    <?= h($direction) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>

            <label class="field transportMapCheckField">
              <span class="fieldLabel">絞り込み</span>
              <label class="checkWrap">
                <input type="checkbox" name="unassigned_only" value="1" <?= ((int)($initialFilters['unassigned_only'] ?? 0) === 1) ? 'checked' : '' ?>>
                <span>未割当のみ</span>
              </label>
            </label>

            <div class="transportMapFilterActions">
              <button type="submit" class="btn">条件反映</button>
              <button type="button" class="btn btn-primary" id="transportMapReload">再読込</button>
            </div>
          </div>
        </div>
      </form>
    </section>

    <section class="transportMapSummaryGrid">
      <article class="transportMapSummaryCard transportPanel">
        <span class="transportMapSummaryLabel">今日の件数</span>
        <b class="transportMapSummaryValue" data-summary-total>0</b>
      </article>
      <article class="transportMapSummaryCard transportPanel transportMapSummaryWarn">
        <span class="transportMapSummaryLabel">未割当</span>
        <b class="transportMapSummaryValue" data-summary-unassigned>0</b>
      </article>
      <article class="transportMapSummaryCard transportPanel">
        <span class="transportMapSummaryLabel">割当済</span>
        <b class="transportMapSummaryValue" data-summary-assigned>0</b>
      </article>
      <article class="transportMapSummaryCard transportPanel">
        <span class="transportMapSummaryLabel">完了</span>
        <b class="transportMapSummaryValue" data-summary-done>0</b>
      </article>
      <article class="transportMapSummaryCard transportPanel">
        <span class="transportMapSummaryLabel">地図表示可</span>
        <b class="transportMapSummaryValue" data-summary-mappable>0</b>
      </article>
      <article class="transportMapSummaryCard transportPanel">
        <span class="transportMapSummaryLabel">座標未登録</span>
        <b class="transportMapSummaryValue" data-summary-without-coords>0</b>
      </article>
    </section>

    <section class="transportMapMetaGrid">
      <article class="transportPanel transportMapMetaCard">
        <div class="transportMapMetaHead">
          <div class="cardTitle">方面別件数</div>
          <div class="muted">偏りを即確認</div>
        </div>
        <div class="transportMapChipList" data-direction-summary>
          <span class="transportMapEmptyInline">読み込み待ちです</span>
        </div>
      </article>
      <article class="transportPanel transportMapMetaCard">
        <div class="transportMapMetaHead">
          <div class="cardTitle">ドライバー別件数</div>
          <div class="muted">未割当判断と表示切替</div>
        </div>
        <div class="transportMapChipList" data-driver-summary>
          <span class="transportMapEmptyInline">読み込み待ちです</span>
        </div>
        <div class="transportMapDriverVisibility">
          <div class="transportMapDriverVisibilityHead">
            <span class="transportMapMiniHint">地図表示のON / OFF</span>
            <span class="transportMapMiniHint" data-vehicle-updated>車両更新 待機中</span>
          </div>
          <div class="transportMapDriverToggleList" data-driver-toggles>
            <span class="transportMapEmptyInline">車両送信が始まると切替できます</span>
          </div>
        </div>
      </article>
    </section>

    <section class="transportMapLayout">
      <div class="transportPanel transportMapListPanel">
        <div class="transportMapListHead">
          <div>
            <div class="cardTitle">送迎一覧</div>
            <div class="muted">行を押すと地図で対象を強調し、その場でドライバー割当もできます</div>
          </div>
          <div class="transportMapListLegend">
            <?php foreach (transport_map_status_definitions() as $status => $meta): ?>
              <span class="transportMapLegendItem">
                <i style="background:<?= h((string)$meta['color']) ?>"></i>
                <?= h((string)$meta['label']) ?>
              </span>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="transportMapSuggestActions">
          <button type="button" class="btn" id="transportMapAutoAssign">自動提案</button>
          <button type="button" class="btn btn-primary" id="transportMapConfirmSuggestions">提案を確定</button>
          <span class="transportMapSuggestStatus" id="transportMapSuggestStatus">未割当へ提案を出せます</span>
        </div>
        <div class="transportMapList" id="transportMapList">
          <div class="transportMapEmpty">データを読み込み中です。</div>
        </div>
      </div>

      <div class="transportPanel transportMapCanvasPanel">
        <div class="transportMapCanvasHead">
          <div>
            <div class="cardTitle">送迎マップ</div>
            <div class="muted">店舗位置と 5km / 10km 圏を表示します</div>
          </div>
          <div class="transportMapMapBadge" id="transportMapMapBadge">読込中</div>
        </div>
        <div id="transportMapCanvas" class="transportMapCanvas" aria-label="送迎マップ"></div>
        <div class="transportMapFootNote" id="transportMapFootNote">緯度経度がない対象は一覧のみ表示されます。</div>
      </div>
    </section>
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
<script src="/wbss/public/assets/js/transport-map.js?v=20260327aa"></script>
<?php render_page_end(); ?>
