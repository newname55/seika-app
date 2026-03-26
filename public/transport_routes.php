<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/store_access.php';
require_once __DIR__ . '/../app/service_transport.php';

require_login();
require_role(['manager', 'admin', 'super_user']);

$pdo = db();
$msg = '';
$err = '';
$userId = (int)(current_user_id() ?? 0);

try {
  $stores = transport_allowed_stores($pdo);
  $allowedStoreIds = array_values(array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $stores));
  $requestedStoreId = (int)($_GET['store_id'] ?? $_POST['store_id'] ?? 0);
  $selectedStoreId = $requestedStoreId > 0 ? transport_resolve_store_id($pdo, $requestedStoreId) : 0;
  $storeIdForHeader = $selectedStoreId > 0 ? $selectedStoreId : ((int)($_SESSION['__store_id'] ?? 0));
} catch (Throwable $e) {
  $stores = [];
  $allowedStoreIds = [];
  $selectedStoreId = 0;
  $storeIdForHeader = 0;
  $err = $e->getMessage();
}

$businessDate = trim((string)($_GET['business_date'] ?? $_POST['business_date'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate)) {
  $businessDate = (new DateTime('today', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
}
$arrivalTime = trim((string)($_GET['arrival_time'] ?? $_POST['arrival_time'] ?? '20:00'));
if (!preg_match('/^\d{2}:\d{2}$/', $arrivalTime)) {
  $arrivalTime = '20:00';
}
$onlyMissingAddress = ((string)($_GET['only_missing_address'] ?? '0') === '1');
$onlyMissingCoords = ((string)($_GET['only_missing_coords'] ?? '0') === '1');
$vehicleCount = transport_normalize_positive_int($_GET['vehicle_count'] ?? $_POST['vehicle_count'] ?? 1, 1, 1, 20);
$maxPassengers = transport_normalize_positive_int($_GET['max_passengers'] ?? $_POST['max_passengers'] ?? 6, 6, 1, 6);
$focusStoreId = max(0, (int)($_GET['focus_store_id'] ?? 0));
$focusCastId = max(0, (int)($_GET['focus_cast_id'] ?? 0));

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string)($_POST['action'] ?? '') === 'generate_route') {
  try {
    csrf_verify($_POST['csrf_token'] ?? null);
    $targetStoreId = transport_resolve_store_id($pdo, (int)($_POST['store_id'] ?? 0));
    $routePlanId = transport_generate_and_save_route($pdo, $targetStoreId, $businessDate, $arrivalTime, $userId, $vehicleCount, $maxPassengers);
    header('Location: /wbss/public/transport_route_detail.php?id=' . $routePlanId);
    exit;
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$targetStoreIds = $selectedStoreId > 0 ? [$selectedStoreId] : $allowedStoreIds;
$groups = [];
$savedPlans = [];
if ($targetStoreIds !== []) {
  try {
    $groups = transport_fetch_route_candidates($pdo, $businessDate, $targetStoreIds);
    $savedPlans = transport_fetch_route_plans($pdo, $businessDate, $targetStoreIds);
  } catch (Throwable $e) {
    if ($err === '') {
      $err = $e->getMessage();
    }
  }
}

foreach ($groups as &$group) {
  $allCasts = (array)($group['casts'] ?? []);
  $filtered = array_values(array_filter($allCasts, static function (array $cast) use ($onlyMissingAddress, $onlyMissingCoords): bool {
    if ($onlyMissingAddress && !empty($cast['has_address'])) {
      return false;
    }
    if ($onlyMissingCoords && !empty($cast['has_coords'])) {
      return false;
    }
    return true;
  }));
  $group['casts_filtered'] = $filtered;
  $group['filtered_count'] = count($filtered);
}
unset($group);

$routeTotals = [
  'stores' => count($groups),
  'casts' => 0,
  'coord_ready' => 0,
  'missing_address' => 0,
  'missing_coords' => 0,
  'saved' => count($savedPlans),
];
foreach ($groups as $group) {
  $routeTotals['casts'] += (int)($group['filtered_count'] ?? 0);
  foreach ((array)($group['casts_filtered'] ?? []) as $cast) {
    if (!empty($cast['has_coords'])) {
      $routeTotals['coord_ready']++;
    } else {
      $routeTotals['missing_coords']++;
    }
    if (empty($cast['has_address'])) {
      $routeTotals['missing_address']++;
    }
  }
}

$savedByStore = [];
foreach ($savedPlans as $plan) {
  $savedByStore[(int)$plan['store_id']][] = $plan;
}

$headerActions = '<a class="btn" href="/wbss/public/dashboard.php">ダッシュボード</a>';
if ($selectedStoreId > 0) {
  $headerActions = '<a class="btn" href="/wbss/public/cast_transport_profiles.php?store_id=' . (int)$selectedStoreId . '">送迎設定へ</a> '
    . '<a class="btn" href="/wbss/public/transport/map.php?store_id=' . (int)$selectedStoreId . '&business_date=' . urlencode($businessDate) . '">送迎マップへ</a> '
    . '<a class="btn" href="/wbss/public/store_transport_bases.php?store_id=' . (int)$selectedStoreId . '">拠点設定へ</a> '
    . $headerActions;
}

render_page_start('送迎ルート');
render_header('送迎ルート', [
  'back_href' => $selectedStoreId > 0
    ? '/wbss/public/manager_today_schedule.php?store_id=' . (int)$selectedStoreId . '&business_date=' . urlencode($businessDate)
    : '/wbss/public/dashboard.php',
  'back_label' => '← 戻る',
  'right_html' => $headerActions,
]);
?>
<div class="page">
  <div class="admin-wrap transportRoutesPage transportShell">
    <?php if ($msg !== ''): ?>
      <div class="card noticeOk"><?= h($msg) ?></div>
    <?php endif; ?>
    <?php if ($err !== ''): ?>
      <div class="card" style="border-color:#ef4444"><?= h($err) ?></div>
    <?php endif; ?>

    <div class="pageHero">
      <div class="rowTop">
        <div class="titleWrap">
          <div class="title">送迎マップで全体確認した後に、ここでルートを下書き化</div>
          <div class="heroMeta">
            <span class="heroChip">営業日 <b><?= h($businessDate) ?></b></span>
            <span class="heroChip">到着目安 <b><?= h($arrivalTime) ?></b></span>
            <span class="heroChip">対象店舗 <b><?= count($groups) ?></b></span>
            <span class="heroChip">車両 <b><?= $vehicleCount ?> 台</b></span>
            <span class="heroChip">定員 <b><?= $maxPassengers ?> 名/台</b></span>
            <?php if ($onlyMissingAddress): ?><span class="heroChip">住所未登録のみ</span><?php endif; ?>
            <?php if ($onlyMissingCoords): ?><span class="heroChip">座標未登録のみ</span><?php endif; ?>
          </div>
        </div>
      </div>
      <div class="subInfo">
        <div class="muted">まず送迎マップで未割当や偏りを見て、その後ここで候補を絞って下書きルートを作る流れを想定しています。緯度経度がある対象を中心に、迎車順のたたき台を作成します。</div>
      </div>
    </div>

    <section class="transportRouteQuickActions transportPanel">
      <div class="transportRouteQuickCard">
        <div>
          <div class="cardTitle">先に送迎マップで未割当と密集地点を確認</div>
          <div class="muted">担当付けや方面の偏りを見てからルートを作ると、下書きの修正が減ります。</div>
        </div>
        <div class="transportRouteQuickButtons">
          <?php if ($selectedStoreId > 0): ?>
            <a class="btn btn-primary" href="/wbss/public/transport/map.php?store_id=<?= (int)$selectedStoreId ?>&business_date=<?= urlencode($businessDate) ?>">送迎マップを開く</a>
          <?php endif; ?>
          <a class="btn" href="/wbss/public/cast_transport_profiles.php?store_id=<?= (int)$selectedStoreId ?>">送迎設定を確認</a>
        </div>
      </div>
    </section>

    <section class="transportRouteSummaryGrid">
      <article class="transportRouteSummaryCard transportPanel">
        <span>候補人数</span>
        <b><?= (int)$routeTotals['casts'] ?></b>
      </article>
      <article class="transportRouteSummaryCard transportPanel">
        <span>座標あり</span>
        <b><?= (int)$routeTotals['coord_ready'] ?></b>
      </article>
      <article class="transportRouteSummaryCard transportPanel transportRouteSummaryWarn">
        <span>座標不足</span>
        <b><?= (int)$routeTotals['missing_coords'] ?></b>
      </article>
      <article class="transportRouteSummaryCard transportPanel transportRouteSummaryWarn">
        <span>住所不足</span>
        <b><?= (int)$routeTotals['missing_address'] ?></b>
      </article>
      <article class="transportRouteSummaryCard transportPanel">
        <span>保存済み下書き</span>
        <b><?= (int)$routeTotals['saved'] ?></b>
      </article>
    </section>

    <section class="transportToolbar transportPanel">
      <form method="get" class="transportRouteFilterForm">
        <div class="transportRouteFilterBlock">
          <div class="transportRouteFilterHead">
            <span class="transportRouteFilterTitle">基本条件</span>
            <span class="muted">対象店舗と到着条件を決めます</span>
          </div>
          <div class="transportRouteFilterGrid transportRouteFilterGrid-primary">
            <label class="field field-store">
              <span class="fieldLabel">店舗</span>
              <select name="store_id" class="sel">
                <option value="0" <?= $selectedStoreId === 0 ? 'selected' : '' ?>>全店舗</option>
                <?php foreach ($stores as $store): ?>
                  <?php $sid = (int)($store['id'] ?? 0); ?>
                  <option value="<?= $sid ?>" <?= $sid === $selectedStoreId ? 'selected' : '' ?>>
                    <?= h((string)($store['name'] ?? '')) ?> (#<?= $sid ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </label>

            <label class="field field-date">
              <span class="fieldLabel">営業日</span>
              <input class="sel" type="date" name="business_date" value="<?= h($businessDate) ?>">
            </label>

            <label class="field field-time">
              <span class="fieldLabel">到着</span>
              <input class="sel" type="time" name="arrival_time" value="<?= h($arrivalTime) ?>">
            </label>

            <label class="field field-count">
              <span class="fieldLabel">車両台数</span>
              <input class="sel" type="number" min="1" max="20" name="vehicle_count" value="<?= $vehicleCount ?>">
            </label>

            <label class="field field-count">
              <span class="fieldLabel">最大人数</span>
              <input class="sel" type="number" min="1" max="6" name="max_passengers" value="<?= $maxPassengers ?>">
            </label>
          </div>
        </div>

        <div class="transportRouteFilterBlock">
          <div class="transportRouteFilterHead">
            <span class="transportRouteFilterTitle">不足データの確認</span>
            <span class="muted">整備が必要な対象だけに絞れます</span>
          </div>
          <div class="transportRouteFilterGrid transportRouteFilterGrid-secondary">
            <label class="checkInline transportRouteCheck">
              <input type="checkbox" name="only_missing_address" value="1" <?= $onlyMissingAddress ? 'checked' : '' ?>>
              <span>住所未登録だけ</span>
            </label>

            <label class="checkInline transportRouteCheck">
              <input type="checkbox" name="only_missing_coords" value="1" <?= $onlyMissingCoords ? 'checked' : '' ?>>
              <span>座標未登録だけ</span>
            </label>

            <div class="transportRouteFilterActions">
              <button class="btn">条件反映</button>
            </div>
          </div>
        </div>
      </form>
    </section>

    <div class="transportRouteGroupList">
      <?php foreach ($groups as $group): ?>
        <?php
          $storeId = (int)($group['store_id'] ?? 0);
          $previewSet = transport_build_vehicle_route_set(
            (array)($group['casts_filtered'] ?? []),
            (array)($group['base'] ?? []),
            (array)($group['dispatch_base'] ?? $group['base'] ?? []),
            $vehicleCount,
            $maxPassengers
          );
          $previewRoutes = [];
          foreach ((array)($previewSet['routes'] ?? []) as $previewRoute) {
            $previewRoutes[] = transport_assign_planned_times($previewRoute, $businessDate, $arrivalTime);
          }
          $unavailableReasons = (array)($previewSet['unavailable_reasons'] ?? []);
        ?>
        <section
          class="card routeGroupCard transportPanel<?= ($focusStoreId > 0 && $focusStoreId === $storeId) ? ' is-focused-store' : '' ?>"
          id="route-store-<?= $storeId ?>"
          data-route-store-id="<?= $storeId ?>"
        >
          <div class="routeGroupHead">
            <div>
              <div class="cardTitle"><?= h((string)($group['store_name'] ?? '')) ?> (#<?= $storeId ?>)</div>
              <div class="muted">
                予定 <?= (int)$group['stats']['planned_count'] ?> 名 /
                住所あり <?= (int)$group['stats']['address_ready_count'] ?> 名 /
                座標あり <?= (int)$group['stats']['coord_ready_count'] ?> 名
              </div>
              <?php if ($onlyMissingAddress || $onlyMissingCoords): ?>
                <div class="muted">絞り込み後 <?= (int)($group['filtered_count'] ?? 0) ?> 名を表示</div>
              <?php endif; ?>
            </div>
            <div class="routeGroupActions">
              <a class="btn" href="/wbss/public/transport/map.php?store_id=<?= $storeId ?>&business_date=<?= urlencode($businessDate) ?>&return_to=routes&return_store_id=<?= $storeId ?>">送迎マップを見る</a>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="generate_route">
                <input type="hidden" name="store_id" value="<?= $storeId ?>">
                <input type="hidden" name="business_date" value="<?= h($businessDate) ?>">
                <input type="hidden" name="arrival_time" value="<?= h($arrivalTime) ?>">
                <input type="hidden" name="vehicle_count" value="<?= $vehicleCount ?>">
                <input type="hidden" name="max_passengers" value="<?= $maxPassengers ?>">
                <button type="submit" class="btn btn-primary">下書きルート生成</button>
              </form>
            </div>
          </div>

          <div class="routeSummaryRow">
            <div class="routeSummaryItem"><span>出発</span><b><?= h((string)(($group['dispatch_base']['name'] ?? '') !== '' ? $group['dispatch_base']['name'] : '-')) ?></b></div>
            <div class="routeSummaryItem"><span>到着</span><b><?= h((string)($group['base']['name'] ?? '-')) ?></b></div>
            <div class="routeSummaryItem"><span>使用車両</span><b><?= (int)($previewSet['used_vehicle_count'] ?? 0) ?> / <?= $vehicleCount ?> 台</b></div>
            <div class="routeSummaryItem"><span>合計距離</span><b><?= $previewSet['total_distance_km'] !== null ? h((string)$previewSet['total_distance_km']) . ' km' : '-' ?></b></div>
            <div class="routeSummaryItem"><span>合計時間</span><b><?= $previewSet['total_duration_min'] !== null ? (int)$previewSet['total_duration_min'] . ' 分' : '-' ?></b></div>
          </div>

          <?php if ($unavailableReasons !== []): ?>
            <div class="routeAlertBox">
              <div class="routePaneTitle">計算できない理由</div>
              <ul class="routeReasonList">
                <?php foreach ($unavailableReasons as $reason): ?>
                  <li><?= h((string)$reason) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <div class="routeColumns">
            <div class="routePane">
              <div class="routePaneTitle">候補キャスト</div>
              <div class="routeCastList">
                <?php foreach (($group['casts_filtered'] ?? []) as $cast): ?>
                  <?php
                    $castUserId = (int)($cast['user_id'] ?? 0);
                    $castMapUrl = '/wbss/public/transport/map.php?store_id=' . $storeId
                      . '&business_date=' . urlencode($businessDate)
                      . '&cast_id=' . $castUserId
                      . '&return_to=routes'
                      . '&return_store_id=' . $storeId
                      . '&return_cast_id=' . $castUserId;
                  ?>
                  <div class="routeCastItem<?= ($focusCastId > 0 && $focusCastId === $castUserId) ? ' is-focused-cast' : '' ?>" data-route-cast-id="<?= $castUserId ?>">
                    <div class="routeCastMain">
                      <div>
                        <b><?= h((string)(($cast['shop_tag'] !== '' ? '【' . $cast['shop_tag'] . '】 ' : '') . (string)$cast['display_name'])) ?></b>
                      </div>
                      <a class="routeCastMapLink" href="<?= h($castMapUrl) ?>">地図で確認</a>
                    </div>
                    <div class="routeCastFlags">
                      <span class="badge mode <?= (($cast['pickup_target'] ?? 'primary') === 'secondary') ? 'secondary' : ((($cast['pickup_target'] ?? 'primary') === 'self') ? 'self' : 'primary') ?>"><?= h((string)($cast['pickup_target_label'] ?? '基本')) ?></span>
                      <span class="badge <?= !empty($cast['has_address']) ? 'ok' : 'ng' ?>"><?= !empty($cast['has_address']) ? '住所' : '住所なし' ?></span>
                      <span class="badge <?= !empty($cast['has_coords']) ? 'ok' : 'ng' ?>"><?= !empty($cast['has_coords']) ? '座標' : '座標なし' ?></span>
                    </div>
                    <div class="muted"><?= h(substr((string)$cast['start_time'], 0, 5)) ?> / <?= h((string)($cast['pickup_address'] !== '' ? $cast['pickup_address'] : '住所未登録')) ?></div>
                    <?php if ((string)($cast['pickup_note'] ?? '') !== ''): ?>
                      <div class="muted">メモ: <?= h((string)$cast['pickup_note']) ?></div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
                <?php if (($group['casts_filtered'] ?? []) === []): ?>
                  <div class="routeCastEmpty">この条件に一致するキャストはいません。</div>
                <?php endif; ?>
              </div>
            </div>

            <div class="routePane">
              <div class="routePaneTitle">最短候補プレビュー</div>
              <?php if ($previewRoutes === []): ?>
                <div class="muted">店舗座標かキャスト座標が足りないため、まだ生成できません。</div>
              <?php else: ?>
                <div class="vehiclePreviewList">
                  <?php foreach ($previewRoutes as $preview): ?>
                    <div class="vehiclePreviewCard">
                      <div class="vehiclePreviewHead">
                        <b><?= h((string)($preview['vehicle_label'] ?? '1号車')) ?></b>
                        <span class="muted"><?= h((string)($preview['algorithm'] ?? '-')) ?> / <?= (int)($preview['cast_count'] ?? 0) ?> 名</span>
                      </div>
                      <ol class="routePreviewList">
                        <?php foreach ((array)($preview['stops'] ?? []) as $stop): ?>
                          <?php
                            $stopCastId = (int)($stop['cast_user_id'] ?? 0);
                            $stopMapUrl = '/wbss/public/transport/map.php?store_id=' . $storeId
                              . '&business_date=' . urlencode($businessDate)
                              . '&cast_id=' . $stopCastId
                              . '&return_to=routes'
                              . '&return_store_id=' . $storeId
                              . '&return_cast_id=' . $stopCastId;
                          ?>
                          <li>
                            <div class="routeStopMain">
                              <div class="routeStopMainText">
                                <b><?= h((string)(((string)($stop['shop_tag'] ?? '') !== '' ? '【' . (string)$stop['shop_tag'] . '】 ' : '') . (string)$stop['display_name'])) ?></b>
                                <span class="muted"><?= h((string)($stop['planned_at'] ?? '')) ?></span>
                              </div>
                              <?php if ($stopCastId > 0): ?>
                                <a class="routeCastMapLink" href="<?= h($stopMapUrl) ?>">地図で確認</a>
                              <?php endif; ?>
                            </div>
                            <div class="muted">
                              <?= h((string)($stop['pickup_address'] ?? '')) ?>
                              <?php if ((string)$stop['stop_type'] === 'store_arrival'): ?> / 店舗到着<?php endif; ?>
                              <?php if ((string)$stop['stop_type'] === 'dispatch_origin'): ?> / 車の出発地点<?php endif; ?>
                            </div>
                            <div class="muted">
                              前地点から <?= h((string)($stop['distance_km_from_prev'] ?? 0)) ?> km / <?= (int)($stop['travel_minutes_from_prev'] ?? 0) ?> 分
                            </div>
                          </li>
                        <?php endforeach; ?>
                      </ol>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <?php if (!empty($savedByStore[$storeId])): ?>
            <div class="savedRoutes">
              <div class="routePaneTitle">保存済み下書き</div>
              <div class="savedRouteList">
                <?php foreach ($savedByStore[$storeId] as $plan): ?>
                  <a class="savedRouteLink" href="/wbss/public/transport_route_detail.php?id=<?= (int)$plan['id'] ?>">
                    <?= h((string)($plan['vehicle_label'] ?? '1号車')) ?> / #<?= (int)$plan['id'] ?> / <?= h((string)$plan['created_at']) ?> / <?= h((string)$plan['total_distance_km']) ?> km / <?= (int)($plan['total_duration_min'] ?? 0) ?> 分 / 定員 <?= (int)($plan['max_passengers'] ?? 6) ?> 名
                  </a>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>
        </section>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<style>
.transportShell{ display:grid; gap:14px; padding-bottom:28px; }
.transportPanel{
  border:1px solid rgba(15,23,42,.08);
  border-radius:18px;
  background:linear-gradient(135deg, rgba(255,255,255,.98), rgba(241,245,249,.92));
  box-shadow:0 12px 30px rgba(15,18,34,.08);
}
.pageHero{
  margin-bottom:0;
  padding:18px;
  border:1px solid rgba(15,23,42,.08);
  border-radius:18px;
  background:linear-gradient(135deg, rgba(255,255,255,.98), rgba(241,245,249,.92));
  box-shadow:0 12px 30px rgba(15,18,34,.08);
}
.rowTop{ display:flex; align-items:flex-start; gap:16px; justify-content:space-between; }
.titleWrap{ display:flex; flex-direction:column; gap:10px; align-items:flex-end; }
.title{ font-weight:1000; font-size:28px; line-height:1.1; letter-spacing:.01em; color:#0f172a; }
.heroMeta{ display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; }
.heroChip{ display:inline-flex; align-items:center; gap:6px; padding:8px 12px; border-radius:999px; border:1px solid rgba(15,23,42,.10); background:#ffffff; color:#334155; font-size:13px; }
.subInfo{ margin-top:10px; }
.transportToolbar{ padding:16px 18px; }
.transportRoutesPage .btn{ min-height:44px; border-radius:12px; }
.transportRoutesPage .btn-primary{ background:rgba(59,130,246,.12); border-color:rgba(59,130,246,.26); color:#1d4ed8; }
.transportRoutesPage .sel{
  min-height:44px;
  padding:10px 12px;
  border-radius:12px;
  border:1px solid rgba(15,23,42,.12);
  background:#fff;
  color:#0f172a;
  font-size:14px;
  font-weight:700;
}
.transportRoutesPage .field{ display:grid; gap:6px; min-width:0; }
.transportRoutesPage .fieldLabel{ font-size:12px; font-weight:800; color:#475569; }
.transportRouteQuickActions{ padding:16px 18px; }
.transportRouteQuickCard{ display:flex; justify-content:space-between; gap:14px; align-items:center; flex-wrap:wrap; }
.transportRouteQuickButtons{ display:flex; gap:10px; flex-wrap:wrap; }
.transportRouteSummaryGrid{ display:grid; grid-template-columns:repeat(5, minmax(0, 1fr)); gap:10px; }
.transportRouteSummaryCard{ padding:16px; display:grid; gap:6px; background:#fff; }
.transportRouteSummaryCard span{ color:#64748b; font-size:12px; font-weight:700; }
.transportRouteSummaryCard b{ color:#0f172a; font-size:24px; line-height:1; }
.transportRouteSummaryWarn{ border-color:rgba(239,68,68,.24); background:linear-gradient(180deg, rgba(254,242,242,.96), rgba(255,255,255,.98)); }
.transportRouteFilterForm{ display:grid; grid-template-columns:1fr; gap:12px; }
.transportRouteFilterBlock{ display:grid; gap:10px; padding:12px; border:1px solid rgba(15,23,42,.08); border-radius:16px; background:rgba(255,255,255,.72); }
.transportRouteFilterHead{ display:flex; justify-content:space-between; gap:10px; align-items:center; flex-wrap:wrap; }
.transportRouteFilterTitle{ color:#0f172a; font-size:13px; font-weight:900; }
.transportRouteFilterGrid{ display:grid; grid-template-columns:repeat(12, minmax(0, 1fr)); gap:12px; align-items:end; }
.transportRouteFilterGrid-primary .field-store{ grid-column:span 3; }
.transportRouteFilterGrid-primary .field-date{ grid-column:span 3; }
.transportRouteFilterGrid-primary .field-time{ grid-column:span 2; }
.transportRouteFilterGrid-primary .field-count{ grid-column:span 2; }
.transportRouteFilterGrid-secondary .transportRouteCheck{ grid-column:span 3; }
.transportRouteFilterActions{ grid-column:span 3; display:flex; justify-content:flex-end; }
.transportRouteFilterActions .btn{ min-width:160px; justify-content:center; }
.checkInline{
  display:inline-flex;
  align-items:center;
  gap:8px;
  min-height:44px;
  padding:0 12px;
  border:1px solid rgba(15,23,42,.12);
  border-radius:12px;
  background:#fff;
  color:#334155;
  font-size:13px;
  font-weight:700;
}
.transportRoutesPage .transportRouteGroupList{ display:grid; gap:14px; }
.routeGroupCard{ display:grid; gap:14px; padding:18px; }
.routeGroupCard.is-focused-store{
  border-color:rgba(59,130,246,.28);
  box-shadow:0 0 0 2px rgba(59,130,246,.12), 0 12px 30px rgba(15,18,34,.08);
}
.routeGroupHead{ display:flex; justify-content:space-between; gap:12px; align-items:flex-start; }
.routeGroupActions{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.routeSummaryRow{ display:grid; grid-template-columns:repeat(5, minmax(0, 1fr)); gap:10px; }
.routeSummaryItem{ background:#fff; border:1px solid rgba(15,23,42,.08); border-radius:16px; padding:12px; display:grid; gap:6px; }
.routeSummaryItem span{ color:#64748b; font-size:12px; }
.routeSummaryItem b{ color:#0f172a; }
.routeAlertBox{ border:1px solid rgba(245,158,11,.28); border-radius:16px; padding:12px 14px; background:rgba(245,158,11,.08); display:grid; gap:8px; }
.routeReasonList{ margin:0; padding-left:18px; color:#64748b; display:grid; gap:4px; }
.routeColumns{ display:grid; grid-template-columns:1.05fr .95fr; gap:14px; }
.routePane{ display:grid; gap:10px; }
.routePaneTitle{ font-weight:900; font-size:14px; letter-spacing:.02em; color:#0f172a; }
.routeCastList,
.savedRouteList{ display:grid; gap:8px; }
.vehiclePreviewList{ display:grid; gap:10px; }
.vehiclePreviewCard{ border:1px solid rgba(15,23,42,.08); border-radius:16px; padding:12px; background:rgba(255,255,255,.82); display:grid; gap:10px; }
.vehiclePreviewHead{ display:flex; justify-content:space-between; gap:10px; align-items:center; }
.routeCastItem{ border:1px solid rgba(15,23,42,.08); border-radius:14px; padding:12px; display:grid; gap:6px; background:rgba(255,255,255,.82); }
.routeCastItem.is-focused-cast{
  border-color:rgba(59,130,246,.28);
  box-shadow:0 0 0 2px rgba(59,130,246,.10) inset;
  background:linear-gradient(180deg, rgba(239,246,255,.95), rgba(255,255,255,.92));
}
.routeCastMain{ display:flex; justify-content:space-between; gap:10px; align-items:flex-start; }
.routeCastMapLink{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-height:30px;
  padding:6px 10px;
  border-radius:999px;
  border:1px solid rgba(59,130,246,.18);
  background:rgba(59,130,246,.08);
  color:#1d4ed8;
  font-size:11px;
  font-weight:800;
  text-decoration:none;
  white-space:nowrap;
}
.badge.mode.primary{ border-color:rgba(96,165,250,.35); background:rgba(96,165,250,.12); }
.badge.mode.secondary{ border-color:rgba(244,114,182,.35); background:rgba(244,114,182,.12); }
.badge.mode.self{ border-color:rgba(34,197,94,.35); background:rgba(34,197,94,.12); }
.routeCastEmpty{ border:1px dashed rgba(15,23,42,.16); border-radius:14px; padding:14px; color:#64748b; background:rgba(248,250,252,.82); }
.routeCastFlags{ display:flex; gap:8px; flex-wrap:wrap; }
.routePreviewList{ margin:0; padding-left:20px; display:grid; gap:10px; }
.routeStopMain{ display:flex; justify-content:space-between; gap:12px; }
.routeStopMainText{ display:grid; gap:2px; }
.savedRouteLink{ display:block; padding:12px 14px; border:1px solid rgba(15,23,42,.08); border-radius:14px; text-decoration:none; color:inherit; background:rgba(255,255,255,.82); }
@media (max-width: 900px){
  .transportRouteSummaryGrid,
  .transportRouteFilterGrid,
  .routeColumns,
  .routeSummaryRow{ grid-template-columns:1fr; }
  .transportRouteFilterGrid-primary .field-store,
  .transportRouteFilterGrid-primary .field-date,
  .transportRouteFilterGrid-primary .field-time,
  .transportRouteFilterGrid-primary .field-count,
  .transportRouteFilterGrid-secondary .transportRouteCheck,
  .transportRouteFilterActions{
    grid-column:auto;
  }
  .transportRouteFilterActions .btn{ width:100%; }
}
@media (max-width: 640px){
  .transportRouteQuickCard,
  .routeGroupHead,
  .routeStopMain,
  .routeCastMain{ flex-direction:column; align-items:stretch; }
}
</style>
<script>
(function () {
  const focusStoreId = <?= (int)$focusStoreId ?>;
  const focusCastId = <?= (int)$focusCastId ?>;
  if (!focusStoreId && !focusCastId) {
    return;
  }

  window.addEventListener('load', function () {
    const storeEl = focusStoreId ? document.querySelector('[data-route-store-id="' + focusStoreId + '"]') : null;
    const castEl = focusCastId ? document.querySelector('[data-route-cast-id="' + focusCastId + '"]') : null;
    const target = castEl || storeEl;
    if (!target) {
      return;
    }
    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
  });
})();
</script>
<?php render_page_end(); ?>
