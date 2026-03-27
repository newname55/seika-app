(function () {
  'use strict';

  const configRoot = window.WBSS_TRANSPORT_MAP_CONFIG || {};
  const pageConfig = configRoot.page || {};
  const driversByStore = configRoot.driversByStore || {};
  const storeShortLabels = pageConfig.storeShortLabels || {};
  const apiUrl = pageConfig.apiUrl || '/wbss/public/api/transport_map.php';
  const autoAssignUrl = pageConfig.autoAssignUrl || '/wbss/public/api/transport/auto_assign.php';
  const optimizeRouteUrl = pageConfig.optimizeRouteUrl || '/wbss/public/api/transport/optimize_route.php';
  const pagePath = pageConfig.pagePath || window.location.pathname;
  const statusOptions = pageConfig.statusOptions || {};
  const focusCastId = Number(pageConfig.focusCastId || 0);
  const autoRefreshMs = 60000;
  const form = document.getElementById('transportMapFilterForm');
  const reloadButton = document.getElementById('transportMapReload');
  const storeSelect = document.getElementById('transportMapStore');
  const driverSelect = document.getElementById('transportMapDriver');
  const listEl = document.getElementById('transportMapList');
  const mapBadgeEl = document.getElementById('transportMapMapBadge');
  const footNoteEl = document.getElementById('transportMapFootNote');
  const driverToggleEl = document.querySelector('[data-driver-toggles]');
  const vehicleUpdatedEl = document.querySelector('[data-vehicle-updated]');
  const autoAssignButton = document.getElementById('transportMapAutoAssign');
  const resetSuggestionsButton = document.getElementById('transportMapResetSuggestions');
  const confirmSuggestionsButton = document.getElementById('transportMapConfirmSuggestions');
  const suggestStatusEl = document.getElementById('transportMapSuggestStatus');
  const suggestRoutePanelEl = document.querySelector('[data-suggest-route-summary]');
  const suggestRouteListEl = document.querySelector('[data-suggest-route-list]');
  const autoRefreshToggleButton = document.getElementById('transportMapAutoRefreshToggle');

  if (!form) {
    return;
  }

  const summaryRefs = {
    total: document.querySelector('[data-summary-total]'),
    unassigned: document.querySelector('[data-summary-unassigned]'),
    assigned: document.querySelector('[data-summary-assigned]'),
    done: document.querySelector('[data-summary-done]'),
    mappable: document.querySelector('[data-summary-mappable]'),
    withoutCoords: document.querySelector('[data-summary-without-coords]'),
    direction: document.querySelector('[data-direction-summary]'),
    driver: document.querySelector('[data-driver-summary]')
  };

  let map = null;
  let clusterLayer = null;
  let rangeLayer = null;
  let storeLayer = null;
  let vehicleLayer = null;
  let connectionLayer = null;
  let routeLayer = null;
  let suggestionLayer = null;
  let markerById = new Map();
  let rowById = new Map();
  let itemById = new Map();
  let itemIdByCastId = new Map();
  let activeId = null;
  let lastFetchSeq = 0;
  let autoRefreshTimer = null;
  let autoRefreshEnabled = true;
  let hiddenDriverIds = new Set();
  let suggestionById = new Map();

  function ensureMap() {
    if (map || typeof L === 'undefined') {
      return;
    }
    map = L.map('transportMapCanvas', {
      zoomControl: true,
      scrollWheelZoom: true
    }).setView([34.665, 133.919], 12);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    clusterLayer = L.markerClusterGroup({
      maxClusterRadius: 48,
      spiderfyOnMaxZoom: true,
      showCoverageOnHover: false
    });
    map.addLayer(clusterLayer);

    rangeLayer = L.layerGroup().addTo(map);
    storeLayer = L.layerGroup().addTo(map);
    vehicleLayer = L.layerGroup().addTo(map);
    connectionLayer = L.layerGroup().addTo(map);
    routeLayer = L.layerGroup().addTo(map);
    suggestionLayer = L.layerGroup().addTo(map);
  }

  function updateDriverOptions() {
    if (!driverSelect || !storeSelect) {
      return;
    }
    const storeId = String(storeSelect.value || '0');
    const selected = driverSelect.value;
    const options = driversByStore[storeId] || driversByStore.all || [];
    const fragments = ['<option value="0">すべて</option>'];
    options.forEach(function (driver) {
      const id = String(driver.id || 0);
      const selectedAttr = selected === id ? ' selected' : '';
      fragments.push('<option value="' + escapeHtml(id) + '"' + selectedAttr + '>' + escapeHtml(driver.name || '') + '</option>');
    });
    driverSelect.innerHTML = fragments.join('');
    if (!options.some(function (driver) { return String(driver.id || 0) === selected; })) {
      driverSelect.value = '0';
    }
  }

  function serializeForm() {
    const formData = new FormData(form);
    const params = new URLSearchParams();
    formData.forEach(function (value, key) {
      if (value === '' || value === null) {
        return;
      }
      params.set(key, String(value));
    });
    if (!params.has('unassigned_only')) {
      params.set('unassigned_only', '0');
    }
    const rawStoreId = String(params.get('store_id') || '').trim();
    const normalizedStoreId = rawStoreId === 'all' || rawStoreId === '*' ? rawStoreId : String(Number(rawStoreId || 0));
    if (normalizedStoreId === '' || normalizedStoreId === '0' || normalizedStoreId === 'NaN') {
      const fallbackStoreId = Number(pageConfig.currentStoreId || 0)
        || Number((window.__transportBase && window.__transportBase.store_id) || 0)
        || (Array.isArray(window.__transportBases) && window.__transportBases.length === 1
          ? Number((window.__transportBases[0] && window.__transportBases[0].store_id) || 0)
          : 0);
      if (fallbackStoreId > 0) {
        params.set('store_id', String(fallbackStoreId));
      }
    }
    if (!params.has('business_date') || String(params.get('business_date') || '').trim() === '') {
      const fallbackBusinessDate = String((pageConfig.initialFilters && pageConfig.initialFilters.business_date) || '');
      if (fallbackBusinessDate !== '') {
        params.set('business_date', fallbackBusinessDate);
      }
    }
    return params;
  }

  function setSummaryText(ref, value) {
    if (ref) {
      ref.textContent = String(value);
    }
  }

  function renderSummary(summary) {
    setSummaryText(summaryRefs.total, summary.total || 0);
    setSummaryText(summaryRefs.unassigned, summary.unassigned || 0);
    setSummaryText(summaryRefs.assigned, summary.assigned || 0);
    setSummaryText(summaryRefs.done, summary.done || 0);
    setSummaryText(summaryRefs.mappable, summary.mappable || 0);
    setSummaryText(summaryRefs.withoutCoords, summary.without_coords || 0);

    renderChipSummary(summaryRefs.direction, summary.by_direction || {}, '方面データなし');
    renderChipSummary(summaryRefs.driver, summary.by_driver || {}, 'ドライバー割当なし');
  }

  function renderChipSummary(target, data, emptyText) {
    if (!target) {
      return;
    }
    const entries = Object.entries(data || {});
    if (!entries.length) {
      target.innerHTML = '<span class="transportMapEmptyInline">' + escapeHtml(emptyText) + '</span>';
      return;
    }
    target.innerHTML = entries.map(function (entry) {
      return '<span class="transportMapChip"><b>' + escapeHtml(entry[0]) + '</b><span>' + escapeHtml(String(entry[1])) + '件</span></span>';
    }).join('');
  }

  function renderList(items) {
    if (!listEl) {
      return;
    }
    markerById = markerById || new Map();
    rowById = new Map();
    itemById = new Map();
    itemIdByCastId = new Map();

    if (!items.length) {
      listEl.innerHTML = '<div class="transportMapEmpty">条件に一致する送迎対象はありません。</div>';
      return;
    }

    listEl.innerHTML = items.map(function (item) {
      itemById.set(item.id, item);
      if (Number(item.cast_id || 0) > 0) {
        itemIdByCastId.set(Number(item.cast_id), item.id);
      }
      const shopTag = buildStoreScopedTag(item.store_id, item.shop_tag, item.cast_id);
      const tagText = shopTag ? '【' + shopTag + '】' : '';
      const displayLabel = (tagText ? tagText + ' ' : '') + (item.display_name || item.cast_name || '-');
      const status = statusOptions[item.status] || {};
      const unassignedClass = item.driver_user_id === null ? ' is-unassigned' : '';
      const attentionBadge = item.driver_user_id === null ? '<span class="transportMapAttentionTag">要割当</span>' : '';
      const noCoordsClass = item.has_coords ? '' : ' is-disabled';
      const timeText = formatTimeRange(item.pickup_time_from, item.pickup_time_to);
      const noteText = item.note_exists ? 'メモあり' : 'メモなし';
      const distanceText = item.distance_km !== null ? item.distance_km.toFixed(1) + 'km' : '距離未計算';
      const driverText = item.driver_name || '未割当';
      const addressText = item.pickup_address_short || '住所未登録';
      const sourceBadge = item.source_type === 'shift_plan' ? '<span class="transportMapSourceTag">勤務予定由来</span>' : '';
      const suggestion = suggestionById.get(item.id) || null;
      const suggestionClass = suggestion ? ' is-suggested' : '';
      const suggestionHtml = suggestion ? buildSuggestionHtml(suggestion) : '';
      return '' +
        '<article class="transportMapRow' + unassignedClass + noCoordsClass + suggestionClass + '" data-row-id="' + escapeHtml(String(item.id)) + '">' +
          '<div class="transportMapRowHead">' +
            '<div>' +
              '<div class="transportMapRowNameWrap">' +
                '<div class="transportMapRowName">' + escapeHtml(displayLabel) + '</div>' +
                attentionBadge +
                sourceBadge +
              '</div>' +
              '<div class="transportMapRowMeta">' + escapeHtml(timeText) + ' / ' + escapeHtml(item.direction_bucket || '未分類') + ' / ' + escapeHtml(distanceText) + '</div>' +
            '</div>' +
            '<span class="transportMapStatusPill" style="--status-color:' + escapeHtml(status.color || '#475569') + '">' + escapeHtml(item.status_label || item.status || '-') + '</span>' +
          '</div>' +
          '<div class="transportMapRowGrid">' +
            '<span><b>住所</b>' + escapeHtml(addressText) + '</span>' +
            '<span><b>ドライバー</b>' + escapeHtml(driverText) + '</span>' +
            '<span><b>方面</b>' + escapeHtml(item.area_name || item.direction_bucket || '未分類') + '</span>' +
            '<span><b>メモ</b>' + escapeHtml(noteText) + '</span>' +
          '</div>' +
          '<div class="transportMapAssignRow">' +
            '<label class="transportMapAssignField">' +
              '<span>ドライバー</span>' +
              buildDriverSelectHtml(item.store_id, item.driver_user_id) +
            '</label>' +
            '<label class="transportMapAssignField">' +
              '<span>ステータス</span>' +
              buildStatusSelectHtml(item.status) +
            '</label>' +
            '<button type="button" class="btn miniBtn transportMapSaveBtn" data-save-assignment="' + escapeHtml(String(item.id)) + '">保存</button>' +
          '</div>' +
          suggestionHtml +
          '<div class="transportMapRowActions">' +
            '<span class="transportMapMiniHint">' + (item.has_coords ? '地図で表示可能' : '座標未登録のため一覧のみ') + '</span>' +
            '<button type="button" class="miniBtn" data-focus-row="' + escapeHtml(String(item.id)) + '">地図へ移動</button>' +
          '</div>' +
        '</article>';
    }).join('');

    listEl.querySelectorAll('[data-row-id]').forEach(function (rowEl) {
      const itemId = Number(rowEl.getAttribute('data-row-id') || '0');
      rowById.set(itemId, rowEl);
    });
  }

  function renderMap(base, items, vehicles, bases) {
    const visibleItems = filterItemsByDriver(items);
    const visibleVehicles = filterVehiclesByDriver(vehicles || []);

    ensureMap();
    if (!map || !clusterLayer || !rangeLayer || !storeLayer || !vehicleLayer || !connectionLayer || !routeLayer || !suggestionLayer) {
      if (mapBadgeEl) {
        mapBadgeEl.textContent = '地図初期化失敗';
      }
      return;
    }

    clusterLayer.clearLayers();
    rangeLayer.clearLayers();
    storeLayer.clearLayers();
    vehicleLayer.clearLayers();
    connectionLayer.clearLayers();
    routeLayer.clearLayers();
    suggestionLayer.clearLayers();
    markerById = new Map();

    const bounds = [];
    const activeBases = Array.isArray(bases) && bases.length
      ? bases
      : (base && base.lat !== null && base.lng !== null ? [base] : []);
    activeBases.forEach(function (baseItem) {
      if (baseItem.lat === null || baseItem.lng === null) {
        return;
      }
      const latLng = [baseItem.lat, baseItem.lng];
      const marker = L.marker(latLng, {
        icon: buildStoreIcon(baseItem)
      });
      marker.bindPopup('<div class="transportMapPopup"><b>' + escapeHtml(baseItem.name || '店舗') + '</b><div>店舗基準点</div></div>');
      storeLayer.addLayer(marker);
      bounds.push(latLng);
      if (activeBases.length === 1) {
        L.circle(latLng, { radius: 5000, color: '#94a3b8', weight: 1, fillOpacity: 0.03 }).addTo(rangeLayer);
        L.circle(latLng, { radius: 10000, color: '#64748b', weight: 1, dashArray: '4 4', fillOpacity: 0.02 }).addTo(rangeLayer);
      }
    });

    visibleItems.forEach(function (item) {
      if (!item.has_coords) {
        return;
      }
      const latLng = [item.pickup_lat, item.pickup_lng];
      const marker = L.marker(latLng, {
        icon: buildStatusIcon(item)
      });
      marker.bindPopup(buildPopupHtml(item));
      marker.on('click', function () {
        focusItem(item.id, false);
      });
      clusterLayer.addLayer(marker);
      markerById.set(item.id, marker);
      bounds.push(latLng);
    });

    visibleVehicles.forEach(function (vehicle) {
      if (vehicle.lat === null || vehicle.lng === null) {
        return;
      }
      const latLng = [vehicle.lat, vehicle.lng];
      const marker = L.marker(latLng, {
        icon: buildVehicleIcon(vehicle)
      });
      marker.bindPopup(buildVehiclePopupHtml(vehicle));
      vehicleLayer.addLayer(marker);
      bounds.push(latLng);
    });

    renderDriverConnections(visibleItems, visibleVehicles);
    renderRouteLines(visibleItems, activeBases);
    renderSuggestionGroups(visibleItems);

    if (bounds.length) {
      map.fitBounds(bounds, { padding: [36, 36], maxZoom: 13 });
    } else {
      map.setView([34.665, 133.919], 11);
    }

    if (mapBadgeEl) {
      const mappedCount = visibleItems.filter(function (item) { return item.has_coords; }).length;
      const vehicleCount = visibleVehicles.filter(function (vehicle) { return vehicle.lat !== null && vehicle.lng !== null; }).length;
      const routeCount = countRouteGroups(visibleItems);
      mapBadgeEl.textContent = mappedCount + '件 + 車両' + vehicleCount + '台' + (routeCount > 0 ? ' + ルート' + routeCount + '本' : '');
    }
    if (footNoteEl) {
      const hiddenCount = items.filter(function (item) { return !item.has_coords; }).length;
      const driverHiddenCount = items.filter(function (item) {
        return item.driver_user_id !== null && hiddenDriverIds.has(Number(item.driver_user_id));
      }).length;
      const messages = [];
      if (hiddenCount > 0) {
        messages.push('緯度経度がない ' + hiddenCount + ' 件は一覧のみ表示しています。');
      } else {
        messages.push('すべての対象を地図表示できます。');
      }
      if (driverHiddenCount > 0) {
        messages.push('ドライバー切替で ' + driverHiddenCount + ' 件を地図から隠しています。');
      }
      footNoteEl.textContent = messages.join(' ');
    }

    renderVehicleUpdated(visibleVehicles);
  }

  function focusItem(itemId, openPopup) {
    activeId = itemId;
    rowById.forEach(function (rowEl, rowId) {
      rowEl.classList.toggle('is-active', rowId === itemId);
    });

    const rowEl = rowById.get(itemId);
    if (rowEl) {
      rowEl.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    const marker = markerById.get(itemId);
    if (marker && map) {
      const latLng = marker.getLatLng();
      map.flyTo(latLng, Math.max(map.getZoom(), 13), { duration: 0.5 });
      if (openPopup) {
        marker.openPopup();
      }
    }
  }

  function focusCast(castId, openPopup) {
    const itemId = itemIdByCastId.get(Number(castId || 0));
    if (!itemId) {
      return false;
    }
    focusItem(itemId, openPopup);
    return true;
  }

  function buildStatusIcon(item) {
    const status = String(item.status || '');
    const isUnassigned = item.driver_user_id === null;
    const color = (statusOptions[status] && statusOptions[status].color) || '#475569';
    const storeClass = ' transportMapMarkerIcon--store-' + String(item.store_id || '0');
    const className = (isUnassigned ? ' transportMapMarkerIcon--unassigned' : '') + storeClass;
    const shopTag = buildStoreScopedTag(item.store_id, item.shop_tag, item.cast_id);
    const nameText = String(item.display_name || item.cast_name || '-');
    const labelHtml = ''
      + '<span class="transportMapMarkerTag">' + escapeHtml(shopTag || '-') + '</span>'
      + '<span class="transportMapMarkerName">' + escapeHtml(nameText) + '</span>';
    return L.divIcon({
      className: 'transportMapMarkerWrap',
      html: '<span class="transportMapMarkerIcon' + className + '" style="--pin-color:' + escapeHtml(color) + '">'
        + labelHtml
        + '</span>',
      iconSize: [140, 34],
      iconAnchor: [18, 17],
      popupAnchor: [0, -18]
    });
  }

  function buildStoreIcon(baseItem) {
    const storeLabel = storeShortLabels[String((baseItem && baseItem.store_id) || '')] || '店';
    return L.divIcon({
      className: 'transportMapStoreWrap',
      html: '<span class="transportMapStoreIcon">' + escapeHtml(storeLabel) + '</span>',
      iconSize: [26, 26],
      iconAnchor: [13, 13]
    });
  }

  function buildVehicleIcon(vehicle) {
    const className = (vehicle.is_stale ? ' transportMapVehicleIcon--stale' : '') + ' transportMapVehicleIcon--store-' + String(vehicle.store_id || '0');
    const label = (vehicle.vehicle_label || '車両') + ' ' + (vehicle.driver_name || '');
    const timeLabel = formatDateTimeShort(vehicle.recorded_at);
    const storeLabel = storeShortLabels[String(vehicle.store_id || '')] || '';
    return L.divIcon({
      className: 'transportMapVehicleWrap',
      html: '<span class="transportMapVehicleIcon' + className + '">'
        + '<span class="transportMapVehicleBadge">' + escapeHtml((storeLabel ? storeLabel + ' ' : '') + (vehicle.vehicle_label || '車')) + '</span>'
        + '<span class="transportMapVehicleName">' + escapeHtml(label.trim()) + '</span>'
        + '<span class="transportMapVehicleTime">' + escapeHtml(timeLabel || '--:--') + '</span>'
        + '</span>',
      iconSize: [212, 36],
      iconAnchor: [22, 18],
      popupAnchor: [0, -18]
    });
  }

  function buildPopupHtml(item) {
    const status = statusOptions[item.status] || {};
    const shopTag = buildStoreScopedTag(item.store_id, item.shop_tag, item.cast_id);
    const tagText = shopTag ? '【' + shopTag + '】' : '';
    const displayLabel = (tagText ? tagText + ' ' : '') + (item.display_name || item.cast_name || '-');
    return '' +
      '<div class="transportMapPopup">' +
        '<div class="transportMapPopupTitle">' + escapeHtml(displayLabel) + '</div>' +
        '<div class="transportMapPopupGrid">' +
          '<span><b>時間</b>' + escapeHtml(formatTimeRange(item.pickup_time_from, item.pickup_time_to)) + '</span>' +
          '<span><b>方面</b>' + escapeHtml(item.direction_bucket || '未分類') + '</span>' +
          '<span><b>距離</b>' + escapeHtml(item.distance_km !== null ? item.distance_km.toFixed(1) + 'km' : '-') + '</span>' +
          '<span><b>状態</b><i style="color:' + escapeHtml(status.color || '#475569') + '">' + escapeHtml(item.status_label || '-') + '</i></span>' +
          '<span><b>担当</b>' + escapeHtml(item.driver_name || '未割当') + '</span>' +
          '<span><b>車両</b>' + escapeHtml(item.vehicle_label || '-') + '</span>' +
        '</div>' +
        '<div class="transportMapPopupAddress">' + escapeHtml(item.pickup_address || '住所未登録') + '</div>' +
        (item.pickup_note ? '<div class="transportMapPopupNote">メモ: ' + escapeHtml(item.pickup_note) + '</div>' : '') +
        '<button type="button" class="btn miniBtn transportMapPopupButton" data-focus-id="' + escapeHtml(String(item.id)) + '">一覧で表示</button>' +
      '</div>';
  }

  function buildVehiclePopupHtml(vehicle) {
    const storeLabel = storeShortLabels[String(vehicle.store_id || '')] || String(vehicle.store_id || '');
    return '' +
      '<div class="transportMapPopup">' +
        '<div class="transportMapPopupTitle">' + escapeHtml((storeLabel ? storeLabel + ' ' : '') + (vehicle.vehicle_label || '車両') + ' / ' + (vehicle.driver_name || '-')) + '</div>' +
        '<div class="transportMapPopupGrid">' +
          '<span><b>更新</b>' + escapeHtml(vehicle.recorded_at || '-') + '</span>' +
          '<span><b>状態</b>' + escapeHtml(vehicle.is_stale ? '位置古め' : '送信中') + '</span>' +
          '<span><b>精度</b>' + escapeHtml(vehicle.accuracy_m !== null ? Math.round(vehicle.accuracy_m) + 'm' : '-') + '</span>' +
          '<span><b>速度</b>' + escapeHtml(vehicle.speed_kmh !== null ? Math.round(vehicle.speed_kmh) + 'km/h' : '-') + '</span>' +
        '</div>' +
      '</div>';
  }

  function filterItemsByDriver(items) {
    return (items || []).filter(function (item) {
      if (item.driver_user_id === null) {
        return true;
      }
      return !hiddenDriverIds.has(Number(item.driver_user_id));
    });
  }

  function filterVehiclesByDriver(vehicles) {
    return (vehicles || []).filter(function (vehicle) {
      const driverId = Number(vehicle.driver_user_id || 0);
      if (driverId <= 0) {
        return true;
      }
      return !hiddenDriverIds.has(driverId);
    });
  }

  function renderDriverConnections(items, vehicles) {
    if (!connectionLayer || typeof L === 'undefined') {
      return;
    }
    const vehicleByDriver = new Map();
    (vehicles || []).forEach(function (vehicle) {
      const driverId = Number(vehicle.driver_user_id || 0);
      if (driverId > 0 && vehicle.lat !== null && vehicle.lng !== null) {
        vehicleByDriver.set(driverId, vehicle);
      }
    });

    (items || []).forEach(function (item) {
      const driverId = Number(item.driver_user_id || 0);
      const vehicle = vehicleByDriver.get(driverId);
      if (!vehicle || !item.has_coords) {
        return;
      }
      L.polyline([
        [vehicle.lat, vehicle.lng],
        [item.pickup_lat, item.pickup_lng]
      ], {
        color: '#94a3b8',
        weight: 1.5,
        opacity: 0.42,
        dashArray: '4 6',
        interactive: false
      }).addTo(connectionLayer);
    });
  }

  function renderSuggestionGroups(items) {
    if (!suggestionLayer || typeof L === 'undefined') {
      return;
    }
    const showGroupTags = pagePath.indexOf('/transport/map_screen.php') === -1;
    const groups = new Map();
    (items || []).forEach(function (item) {
      const suggestion = suggestionById.get(Number(item.id || 0));
      if (!suggestion || !suggestion.group_id || !item.has_coords) {
        return;
      }
      const groupId = String(suggestion.group_id);
      if (!groups.has(groupId)) {
        groups.set(groupId, []);
      }
      groups.get(groupId).push(item);
    });

    groups.forEach(function (groupItems, groupId) {
      if (!groupItems.length) {
        return;
      }
      let latTotal = 0;
      let lngTotal = 0;
      groupItems.forEach(function (item) {
        latTotal += Number(item.pickup_lat || 0);
        lngTotal += Number(item.pickup_lng || 0);
      });
      const centerLat = latTotal / groupItems.length;
      const centerLng = lngTotal / groupItems.length;
      let radiusM = 180;
      groupItems.forEach(function (item) {
        const km = haversineKm(centerLat, centerLng, Number(item.pickup_lat || 0), Number(item.pickup_lng || 0));
        radiusM = Math.max(radiusM, Math.round(km * 1000) + 120);
      });
      const direction = String(groupItems[0].direction_bucket || '未分類');
      L.circle([centerLat, centerLng], {
        radius: radiusM,
        color: '#60a5fa',
        weight: 1,
        opacity: 0.65,
        fillColor: '#bfdbfe',
        fillOpacity: 0.08,
        dashArray: '6 6',
        interactive: false
      }).addTo(suggestionLayer);
      if (!showGroupTags) {
        return;
      }
      L.marker([centerLat, centerLng], {
        icon: L.divIcon({
          className: 'transportMapSuggestGroupWrap',
          html: '<span class="transportMapSuggestGroupTag">'
            + '<b>' + escapeHtml(groupId) + '</b>'
            + '<span>' + escapeHtml(direction) + ' ' + escapeHtml(String(groupItems.length)) + '件</span>'
            + '</span>',
          iconSize: [110, 30],
          iconAnchor: [55, 15]
        })
      }).addTo(suggestionLayer);
    });
  }

  function renderRouteLines(items, bases) {
    if (!routeLayer || typeof L === 'undefined') {
      return;
    }
    const baseByStore = new Map();
    (bases || []).forEach(function (baseItem) {
      const storeId = Number(baseItem.store_id || 0);
      if (storeId > 0 && baseItem.lat !== null && baseItem.lng !== null) {
        baseByStore.set(storeId, baseItem);
      }
    });

    const groups = buildRouteGroups(items);
    groups.forEach(function (group) {
      if (group.items.length <= 0) {
        return;
      }
      const base = baseByStore.get(group.store_id);
      const latLngs = [];
      if (base && base.lat !== null && base.lng !== null) {
        latLngs.push([base.lat, base.lng]);
      }
      group.items.forEach(function (item) {
        latLngs.push([Number(item.pickup_lat), Number(item.pickup_lng)]);
      });
      if (latLngs.length < 2) {
        return;
      }
      const color = colorForDriver(group.driver_id);
      const isSuggested = !!group.is_suggested;
      L.polyline(latLngs, {
        color: color,
        weight: isSuggested ? 4 : 5,
        opacity: isSuggested ? 0.22 : 0.36,
        lineCap: 'round',
        lineJoin: 'round'
      }).addTo(routeLayer);
      L.polyline(latLngs, {
        color: color,
        weight: isSuggested ? 2 : 3,
        opacity: isSuggested ? 0.82 : 0.94,
        dashArray: isSuggested ? '8 8' : null,
        lineCap: 'round',
        lineJoin: 'round'
      }).addTo(routeLayer);

      group.items.forEach(function (item, index) {
        const order = index + 1;
        L.marker([Number(item.pickup_lat), Number(item.pickup_lng)], {
          icon: L.divIcon({
            className: 'transportMapRouteOrderWrap',
            html: '<span class="transportMapRouteOrderTag" style="--route-color:' + escapeHtml(color) + '">' + escapeHtml(String(order)) + '</span>',
            iconSize: [22, 22],
            iconAnchor: [11, 11]
          })
        }).addTo(routeLayer);
      });
    });
  }

  function buildRouteGroups(items) {
    const groups = new Map();
    (items || []).forEach(function (item) {
      if (!item.has_coords) {
        return;
      }
      const suggestion = suggestionById.get(Number(item.id || 0)) || null;
      const driverId = Number((suggestion && suggestion.suggested_driver_id) || item.driver_user_id || 0);
      if (driverId <= 0 || hiddenDriverIds.has(driverId)) {
        return;
      }
      const order = suggestion && suggestion.suggested_order != null
        ? Number(suggestion.suggested_order)
        : Number(item.sort_order || 0);
      if (order <= 0) {
        return;
      }
      const key = String(item.store_id || 0) + ':' + String(driverId);
      if (!groups.has(key)) {
        groups.set(key, {
          store_id: Number(item.store_id || 0),
          driver_id: driverId,
          is_suggested: false,
          items: []
        });
      }
      if (suggestion) {
        groups.get(key).is_suggested = true;
      }
      groups.get(key).items.push(item);
    });

    return Array.from(groups.values()).map(function (group) {
      group.items.sort(function (a, b) {
        const suggestionA = suggestionById.get(Number(a.id || 0)) || null;
        const suggestionB = suggestionById.get(Number(b.id || 0)) || null;
        const orderA = suggestionA && suggestionA.suggested_order != null ? Number(suggestionA.suggested_order) : Number(a.sort_order || 0);
        const orderB = suggestionB && suggestionB.suggested_order != null ? Number(suggestionB.suggested_order) : Number(b.sort_order || 0);
        return orderA - orderB;
      });
      return group;
    }).filter(function (group) {
      return group.items.length > 0;
    });
  }

  function countRouteGroups(items) {
    return buildRouteGroups(items).length;
  }

  function colorForDriver(driverId) {
    const palette = ['#2563eb', '#f97316', '#10b981', '#8b5cf6', '#ec4899', '#eab308', '#ef4444', '#14b8a6'];
    const index = Math.abs(Number(driverId || 0)) % palette.length;
    return palette[index];
  }

  function renderDriverToggles(items, vehicles) {
    if (!driverToggleEl) {
      return;
    }

    const entriesByDriver = new Map();
    (items || []).forEach(function (item) {
      const driverId = Number(item.driver_user_id || 0);
      if (driverId <= 0) {
        return;
      }
      const existing = entriesByDriver.get(driverId) || {
        id: driverId,
        name: item.driver_name || ('ドライバー' + driverId),
        assignedCount: 0,
        vehicleCount: 0
      };
      existing.assignedCount += 1;
      entriesByDriver.set(driverId, existing);
    });
    (vehicles || []).forEach(function (vehicle) {
      const driverId = Number(vehicle.driver_user_id || 0);
      if (driverId <= 0) {
        return;
      }
      const existing = entriesByDriver.get(driverId) || {
        id: driverId,
        name: vehicle.driver_name || ('ドライバー' + driverId),
        assignedCount: 0,
        vehicleCount: 0
      };
      existing.vehicleCount += 1;
      entriesByDriver.set(driverId, existing);
    });

    const nextIds = new Set();
    const entries = Array.from(entriesByDriver.values()).sort(function (a, b) {
      return String(a.name).localeCompare(String(b.name), 'ja');
    });
    entries.forEach(function (entry) {
      nextIds.add(entry.id);
    });
    hiddenDriverIds.forEach(function (driverId) {
      if (!nextIds.has(driverId)) {
        hiddenDriverIds.delete(driverId);
      }
    });

    if (!entries.length) {
      driverToggleEl.innerHTML = '<span class="transportMapEmptyInline">車両送信が始まると切替できます</span>';
      return;
    }

    driverToggleEl.innerHTML = entries.map(function (entry) {
      const hidden = hiddenDriverIds.has(entry.id);
      const stateClass = hidden ? ' is-muted' : ' is-active';
      const detail = [];
      if (entry.assignedCount > 0) {
        detail.push('担当' + entry.assignedCount);
      }
      if (entry.vehicleCount > 0) {
        detail.push('車両' + entry.vehicleCount);
      }
      return ''
        + '<button type="button" class="transportMapDriverToggle' + stateClass + '" data-driver-toggle="' + escapeHtml(String(entry.id)) + '">'
        + '<span class="transportMapDriverToggleName">' + escapeHtml(entry.name) + '</span>'
        + '<span class="transportMapDriverToggleMeta">' + escapeHtml(detail.join(' / ')) + '</span>'
        + '</button>';
    }).join('');
  }

  function renderVehicleUpdated(vehicles) {
    if (!vehicleUpdatedEl) {
      return;
    }
    const sorted = (vehicles || [])
      .filter(function (vehicle) { return vehicle.recorded_at; })
      .sort(function (a, b) {
        return String(b.recorded_at).localeCompare(String(a.recorded_at));
      });
    if (!sorted.length) {
      vehicleUpdatedEl.textContent = '車両更新 まだありません';
      return;
    }
    vehicleUpdatedEl.textContent = '車両更新 ' + formatDateTimeShort(sorted[0].recorded_at);
  }

  function buildDriverSelectHtml(storeId, selectedDriverId) {
    const options = driversByStore[String(storeId || 0)] || [];
    const selected = selectedDriverId === null ? '0' : String(selectedDriverId);
    const html = ['<select class="sel transportMapInlineSelect" data-assign-driver>'];
    html.push('<option value="0"' + (selected === '0' ? ' selected' : '') + '>未割当</option>');
    options.forEach(function (driver) {
      const driverId = String(driver.id || 0);
      html.push('<option value="' + escapeHtml(driverId) + '"' + (driverId === selected ? ' selected' : '') + '>' + escapeHtml(driver.name || '') + '</option>');
    });
    html.push('</select>');
    return html.join('');
  }

  function buildStatusSelectHtml(selectedStatus) {
    const current = String(selectedStatus || 'pending');
    const html = ['<select class="sel transportMapInlineSelect" data-assign-status>'];
    Object.keys(statusOptions).forEach(function (statusKey) {
      const option = statusOptions[statusKey] || {};
      html.push('<option value="' + escapeHtml(statusKey) + '"' + (statusKey === current ? ' selected' : '') + '>' + escapeHtml(option.label || statusKey) + '</option>');
    });
    html.push('</select>');
    return html.join('');
  }

  function buildSuggestionHtml(suggestion) {
    const parts = [];
    parts.push('<div class="transportMapSuggestion" title="' + escapeHtml(suggestion.reason || '') + '">');
    parts.push('<span><b>提案</b>' + escapeHtml(suggestion.suggested_driver_name || '候補なし') + '</span>');
    parts.push('<span><b>組</b>' + escapeHtml(suggestion.group_id || '-') + '</span>');
    parts.push('<span><b>順</b>' + escapeHtml(suggestion.suggested_order != null ? String(suggestion.suggested_order) : '-') + '</span>');
    parts.push('<span><b>ETA</b>' + escapeHtml(suggestion.eta_minutes != null && suggestion.eta_minutes > 0 ? String(suggestion.eta_minutes) + '分' : '-') + '</span>');
    parts.push('<span style="grid-column:1 / -1;"><b>理由</b>' + escapeHtml(suggestion.reason || '-') + '</span>');
    parts.push('</div>');
    return parts.join('');
  }

  function setSuggestStatus(text, isError) {
    if (!suggestStatusEl) {
      return;
    }
    suggestStatusEl.textContent = text;
    suggestStatusEl.classList.toggle('is-error', !!isError);
  }

  function renderSuggestionRouteSummary() {
    if (!suggestRoutePanelEl || !suggestRouteListEl) {
      return;
    }
    const grouped = new Map();
    suggestionById.forEach(function (suggestion, requestId) {
      const driverId = Number(suggestion.suggested_driver_id || 0);
      if (driverId <= 0) {
        return;
      }
      const item = itemById.get(Number(requestId));
      if (!item) {
        return;
      }
      const key = String(driverId);
      if (!grouped.has(key)) {
        grouped.set(key, {
          driver_name: suggestion.suggested_driver_name || ('ドライバー' + driverId),
          stops: []
        });
      }
      grouped.get(key).stops.push({
        order: Number(suggestion.suggested_order || 0),
        name: buildStoreScopedTag(item.store_id, item.shop_tag, item.cast_id) + ' ' + (item.display_name || item.cast_name || '-')
      });
    });

    const groups = Array.from(grouped.values()).map(function (group) {
      group.stops.sort(function (a, b) {
        return a.order - b.order;
      });
      return group;
    });

    if (!groups.length) {
      suggestRoutePanelEl.hidden = true;
      suggestRouteListEl.innerHTML = '';
      return;
    }

    suggestRouteListEl.innerHTML = groups.map(function (group) {
      const stopsHtml = group.stops.map(function (stop) {
        return '<span class="transportMapSuggestRouteStop"><i>' + escapeHtml(String(stop.order || '-')) + '</i><span>' + escapeHtml(stop.name) + '</span></span>';
      }).join('');
      return ''
        + '<div class="transportMapSuggestRouteCard">'
        +   '<div class="transportMapSuggestRouteDriver"><b>' + escapeHtml(String(group.stops.length)) + '</b><span>' + escapeHtml(group.driver_name) + '</span></div>'
        +   '<div class="transportMapSuggestRouteStops">' + stopsHtml + '</div>'
        + '</div>';
    }).join('');
    suggestRoutePanelEl.hidden = false;
  }

  function applySuggestionsToUi(items) {
    suggestionById = new Map();
    (items || []).forEach(function (suggestion) {
      suggestionById.set(Number(suggestion.request_id || 0), suggestion);
    });
    itemById.forEach(function (item, itemId) {
      const suggestion = suggestionById.get(itemId);
      if (!suggestion) {
        return;
      }
      if (Number(item.store_id || 0) <= 0 && Number(suggestion.store_id || 0) > 0) {
        item.store_id = Number(suggestion.store_id || 0);
      }
      item.driver_user_id = suggestion.suggested_driver_id || item.driver_user_id;
      item.driver_name = suggestion.suggested_driver_name || item.driver_name;
      if (item.status === 'pending' && suggestion.suggested_driver_id) {
        item.status = 'assigned';
      }
    });
    rowById.forEach(function (rowEl, itemId) {
      const suggestion = suggestionById.get(itemId);
      if (!suggestion) {
        return;
      }
      const driverField = rowEl.querySelector('[data-assign-driver]');
      const statusField = rowEl.querySelector('[data-assign-status]');
      if (driverField && suggestion.suggested_driver_id) {
        driverField.value = String(suggestion.suggested_driver_id);
      }
      if (statusField && suggestion.suggested_driver_id && String(statusField.value || '') === 'pending') {
        statusField.value = 'assigned';
      }
      rowEl.dataset.suggestedOrder = suggestion.suggested_order != null ? String(suggestion.suggested_order) : '';
    });
    renderList(Array.from(itemById.values()));
    rowById.forEach(function (rowEl, itemId) {
      const suggestion = suggestionById.get(itemId);
      if (suggestion) {
        rowEl.dataset.suggestedOrder = suggestion.suggested_order != null ? String(suggestion.suggested_order) : '';
      }
    });
    const nextItems = Array.from(itemById.values());
    renderDriverToggles(nextItems, window.__transportVehicles || []);
    renderMap(window.__transportBase || {}, nextItems, window.__transportVehicles || [], window.__transportBases || []);
    renderSuggestionRouteSummary();
    setSuggestStatus((items || []).length > 0 ? ((items || []).length + '件の提案を反映しました') : '提案対象はありません', false);
  }

  async function optimizeSuggestionRoutes(suggestions) {
    const grouped = new Map();
    (suggestions || []).forEach(function (suggestion) {
      const item = itemById.get(Number(suggestion.request_id || 0));
      if (!item || !item.has_coords) {
        return;
      }
      const driverId = Number(suggestion.suggested_driver_id || 0);
      const storeId = Number(item.store_id || 0);
      if (driverId <= 0 || storeId <= 0) {
        return;
      }
      const key = String(storeId) + ':' + String(driverId);
      if (!grouped.has(key)) {
        grouped.set(key, {
          store_id: storeId,
          driver_id: driverId,
          items: []
        });
      }
      grouped.get(key).items.push({
        id: Number(suggestion.request_id || 0),
        pickup_lat: Number(item.pickup_lat || 0),
        pickup_lng: Number(item.pickup_lng || 0)
      });
    });

    for (const group of grouped.values()) {
      if (!group.items.length) {
        continue;
      }
      const payload = new URLSearchParams();
      payload.set('csrf_token', String(pageConfig.csrfToken || ''));
      payload.set('store_id', String(group.store_id));
      payload.set('driver_id', String(group.driver_id));
      payload.set('items_json', JSON.stringify(group.items));
      const response = await fetch(optimizeRouteUrl, {
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
        throw new Error(json.error || 'ルート順の生成に失敗しました');
      }
      (json.items || []).forEach(function (routeItem) {
        const requestId = Number(routeItem.request_id || 0);
        const suggestion = suggestionById.get(requestId);
        if (!suggestion) {
          return;
        }
        suggestion.suggested_order = Number(routeItem.order || 0);
        suggestion.eta_minutes = Number(routeItem.eta_minutes || 0);
      });
    }
  }

  document.addEventListener('click', function (event) {
    const trigger = event.target && event.target.closest ? event.target.closest('[data-focus-id]') : null;
    if (!trigger) {
      return;
    }
    const itemId = Number(trigger.getAttribute('data-focus-id') || '0');
    if (itemId > 0) {
      focusItem(itemId, false);
    }
  });

  if (listEl) {
    listEl.addEventListener('click', function (event) {
      const saveTrigger = event.target && event.target.closest ? event.target.closest('[data-save-assignment]') : null;
      if (saveTrigger) {
        const itemId = Number(saveTrigger.getAttribute('data-save-assignment') || '0');
        if (itemId > 0) {
          saveAssignment(itemId, saveTrigger);
        }
        return;
      }

      const focusTrigger = event.target && event.target.closest ? event.target.closest('[data-focus-row]') : null;
      if (focusTrigger) {
        const itemId = Number(focusTrigger.getAttribute('data-focus-row') || '0');
        if (itemId > 0) {
          focusItem(itemId, true);
        }
        return;
      }

      const interactive = event.target && event.target.closest
        ? event.target.closest('select, input, button, a, label')
        : null;
      if (interactive) {
        return;
      }

      const row = event.target && event.target.closest ? event.target.closest('[data-row-id]') : null;
      if (!row) {
        return;
      }
      const itemId = Number(row.getAttribute('data-row-id') || '0');
      if (itemId > 0) {
        focusItem(itemId, true);
      }
    });

    listEl.addEventListener('change', function (event) {
      const driverField = event.target && event.target.matches ? (event.target.matches('[data-assign-driver]') ? event.target : null) : null;
      if (!driverField) {
        return;
      }
      const rowEl = driverField.closest('[data-row-id]');
      if (!rowEl) {
        return;
      }
      const statusField = rowEl.querySelector('[data-assign-status]');
      if (!statusField) {
        return;
      }
      if (String(driverField.value || '0') === '0' && ['assigned'].indexOf(String(statusField.value || '')) >= 0) {
        statusField.value = 'pending';
      }
      if (String(driverField.value || '0') !== '0' && String(statusField.value || '') === 'pending') {
        statusField.value = 'assigned';
      }
    });
  }

  document.addEventListener('click', function (event) {
    const toggle = event.target && event.target.closest ? event.target.closest('[data-driver-toggle]') : null;
    if (!toggle) {
      return;
    }
    const driverId = Number(toggle.getAttribute('data-driver-toggle') || '0');
    if (driverId <= 0) {
      return;
    }
    if (hiddenDriverIds.has(driverId)) {
      hiddenDriverIds.delete(driverId);
    } else {
      hiddenDriverIds.add(driverId);
    }
    renderDriverToggles(Array.from(itemById.values()), window.__transportVehicles || []);
    renderMap(window.__transportBase || {}, Array.from(itemById.values()), window.__transportVehicles || [], window.__transportBases || []);
  });

  async function fetchData(pushHistory) {
    const seq = ++lastFetchSeq;
    const params = serializeForm();
    params.set('_ts', String(Date.now()));
    const url = apiUrl + '?' + params.toString();

    if (reloadButton) {
      reloadButton.disabled = true;
    }
    if (listEl) {
      listEl.innerHTML = '<div class="transportMapEmpty">送迎データを読み込んでいます…</div>';
    }
    if (mapBadgeEl) {
      mapBadgeEl.textContent = '読込中';
    }

    try {
      const response = await fetch(url, {
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      });
      const json = await response.json().catch(function () { return {}; });
      if (seq !== lastFetchSeq) {
        return;
      }
      if (!response.ok || !json.ok) {
        throw new Error(json.error || '送迎データの取得に失敗しました');
      }

      renderSummary(json.summary || {});
      suggestionById = new Map();
      renderList(json.items || []);
      renderDriverToggles(json.items || [], json.vehicles || []);
      renderSuggestionRouteSummary();
      window.__transportBase = json.base || {};
      window.__transportBases = json.bases || [];
      window.__transportVehicles = json.vehicles || [];
      renderMap(json.base || {}, json.items || [], json.vehicles || [], json.bases || []);
      if (focusCastId > 0 && activeId === null) {
        focusCast(focusCastId, true);
      }

      if (pushHistory) {
        const nextUrl = pagePath + '?' + params.toString();
        window.history.replaceState({}, '', nextUrl);
      }
    } catch (error) {
      if (listEl) {
        listEl.innerHTML = '<div class="transportMapEmpty transportMapEmptyError">' + escapeHtml(error.message || '送迎データの取得に失敗しました') + '</div>';
      }
      renderChipSummary(summaryRefs.direction, {}, '取得失敗');
      renderChipSummary(summaryRefs.driver, {}, '取得失敗');
      if (mapBadgeEl) {
        mapBadgeEl.textContent = '取得失敗';
      }
      if (footNoteEl) {
        footNoteEl.textContent = error.message || '送迎データの取得に失敗しました';
      }
    } finally {
      if (reloadButton) {
        reloadButton.disabled = false;
      }
      scheduleAutoRefresh();
    }
  }

  function scheduleAutoRefresh() {
    if (autoRefreshTimer !== null) {
      window.clearTimeout(autoRefreshTimer);
    }
    if (!autoRefreshEnabled) {
      autoRefreshTimer = null;
      return;
    }
    autoRefreshTimer = window.setTimeout(function () {
      fetchData(false);
    }, autoRefreshMs);
  }

  function updateAutoRefreshToggleButton() {
    if (!autoRefreshToggleButton) {
      return;
    }
    autoRefreshToggleButton.textContent = autoRefreshEnabled ? '自動更新ON' : '自動更新OFF';
    autoRefreshToggleButton.classList.toggle('is-muted', !autoRefreshEnabled);
  }

  function toggleAutoRefresh() {
    autoRefreshEnabled = !autoRefreshEnabled;
    updateAutoRefreshToggleButton();
    if (autoRefreshEnabled) {
      setSuggestStatus('自動更新を再開しました', false);
      scheduleAutoRefresh();
    } else {
      if (autoRefreshTimer !== null) {
        window.clearTimeout(autoRefreshTimer);
        autoRefreshTimer = null;
      }
      setSuggestStatus('自動更新を停止しました', false);
    }
  }

  async function saveAssignment(itemId, triggerEl) {
    const rowEl = rowById.get(itemId);
    const item = itemById.get(itemId);
    if (!rowEl || !item) {
      return;
    }

    const driverField = rowEl.querySelector('[data-assign-driver]');
    const statusField = rowEl.querySelector('[data-assign-status]');
    const driverUserId = driverField ? String(driverField.value || '0') : '0';
    const requestedStatus = statusField ? String(statusField.value || 'pending') : 'pending';
    const storeId = resolveItemStoreId(item);
    if (storeId <= 0) {
      throw new Error('対象店舗が不正です');
    }

    const payload = new URLSearchParams();
    payload.set('action', 'save_assignment');
    payload.set('csrf_token', String(pageConfig.csrfToken || ''));
    payload.set('store_id', String(storeId));
    payload.set('business_date', String(item.business_date || ''));
    payload.set('cast_id', String(item.cast_id || '0'));
    payload.set('driver_user_id', driverUserId);
    payload.set('status', requestedStatus);
    payload.set('sort_order', String(rowEl.dataset.suggestedOrder || ''));

    if (triggerEl) {
      triggerEl.disabled = true;
      triggerEl.textContent = '保存中…';
    }

    try {
      const response = await fetch(apiUrl, {
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
        throw new Error(json.error || '送迎割当の保存に失敗しました');
      }
      const nextFocusId = json.item && json.item.id ? Number(json.item.id) : itemId;
      await fetchData(false);
      focusItem(nextFocusId, true);
    } catch (error) {
      const message = String((error && error.message) || '送迎割当の保存に失敗しました');
      if (message.indexOf('対象店舗が不正です') === -1) {
        window.alert(message);
      }
    } finally {
      if (triggerEl) {
        triggerEl.disabled = false;
        triggerEl.textContent = '保存';
      }
    }
  }

  async function runAutoAssign() {
    const params = serializeForm();
    params.set('csrf_token', String(pageConfig.csrfToken || ''));
    try {
      if (autoAssignButton) {
        autoAssignButton.disabled = true;
      }
      setSuggestStatus('提案を生成しています…', false);
      const response = await fetch(autoAssignUrl, {
        method: 'POST',
        cache: 'no-store',
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
        },
        body: params.toString()
      });
      const json = await response.json().catch(function () { return {}; });
      if (!response.ok || !json.ok) {
        throw new Error(json.error || '自動提案の生成に失敗しました');
      }
      suggestionById = new Map();
      (json.items || []).forEach(function (suggestion) {
        suggestionById.set(Number(suggestion.request_id || 0), suggestion);
      });
      await optimizeSuggestionRoutes(json.items || []);
      applySuggestionsToUi(Array.from(suggestionById.values()));
    } catch (error) {
      setSuggestStatus(error.message || '自動提案の生成に失敗しました', true);
      window.alert(error.message || '自動提案の生成に失敗しました');
    } finally {
      if (autoAssignButton) {
        autoAssignButton.disabled = false;
      }
    }
  }

  async function resetSuggestions() {
    try {
      if (resetSuggestionsButton) {
        resetSuggestionsButton.disabled = true;
      }
      suggestionById = new Map();
      setSuggestStatus('提案をリセットしています…', false);
      await fetchData(false);
      setSuggestStatus('提案をリセットしました', false);
    } catch (error) {
      setSuggestStatus(error.message || 'リセットに失敗しました', true);
      window.alert(error.message || 'リセットに失敗しました');
    } finally {
      if (resetSuggestionsButton) {
        resetSuggestionsButton.disabled = false;
      }
    }
  }

  async function confirmSuggestions() {
    const suggestions = Array.from(suggestionById.values()).filter(function (suggestion) {
      return Number(suggestion.suggested_driver_id || 0) > 0;
    });
    if (!suggestions.length) {
      setSuggestStatus('確定できる提案がありません', true);
      return;
    }
    if (confirmSuggestionsButton) {
      confirmSuggestionsButton.disabled = true;
    }
    setSuggestStatus('提案を確定しています…', false);
    try {
      let savedCount = 0;
      let skippedAddressCount = 0;
      let skippedStoreCount = 0;
      let failedCount = 0;
      const failedMessages = [];
      for (const suggestion of suggestions) {
        const itemId = Number(suggestion.request_id || 0);
        const item = itemById.get(itemId);
        if (!item || Number(item.cast_id || 0) <= 0) {
          continue;
        }
        if (String(item.pickup_address || '').trim() === '') {
          skippedAddressCount += 1;
          continue;
        }
        const rowEl = rowById.get(itemId);
        const driverField = rowEl ? rowEl.querySelector('[data-assign-driver]') : null;
        const statusField = rowEl ? rowEl.querySelector('[data-assign-status]') : null;
        const storeId = resolveSuggestionStoreId(item, suggestion);
        if (storeId <= 0) {
          skippedStoreCount += 1;
          failedMessages.push('cast=' + Number(item.cast_id || 0) + ' は店舗特定不可');
          continue;
        }
        try {
          const payload = new URLSearchParams();
          payload.set('action', 'save_assignment');
          payload.set('csrf_token', String(pageConfig.csrfToken || ''));
          payload.set('store_id', String(storeId));
          payload.set('business_date', String(item.business_date || ''));
          payload.set('cast_id', String(item.cast_id || '0'));
          payload.set('driver_user_id', driverField ? String(driverField.value || '0') : String(suggestion.suggested_driver_id || 0));
          payload.set('status', statusField ? String(statusField.value || 'assigned') : 'assigned');
          payload.set('sort_order', suggestion.suggested_order != null ? String(suggestion.suggested_order) : '');
          const response = await fetch(apiUrl, {
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
            throw new Error(json.error || '提案確定に失敗しました');
          }
          savedCount += 1;
        } catch (innerError) {
          failedCount += 1;
          failedMessages.push('cast=' + Number(item.cast_id || 0) + ' ' + (innerError.message || '保存失敗'));
          continue;
        }
      }
      await fetchData(false);
      if (savedCount <= 0) {
        if (skippedStoreCount > 0) {
          setSuggestStatus('店舗判定できない提案 ' + skippedStoreCount + '件は保留にしました', false);
          return;
        }
        throw new Error(
          skippedAddressCount > 0 ? '住所未登録の提案は確定できません'
            : failedCount > 0 ? (failedMessages[0] || '提案確定に失敗しました')
            : '確定できる提案がありません'
        );
      }
      const summaryParts = [savedCount + '件を確定しました'];
      if (skippedAddressCount > 0) {
        summaryParts.push('住所未登録 ' + skippedAddressCount + '件スキップ');
      }
      if (skippedStoreCount > 0) {
        summaryParts.push('店舗不正 ' + skippedStoreCount + '件スキップ');
      }
      if (failedCount > 0) {
        summaryParts.push('保存失敗 ' + failedCount + '件');
      }
      setSuggestStatus(summaryParts.join(' / '), failedCount > 0);
      if (failedCount > 0 && savedCount <= 0) {
        window.alert(summaryParts.join('\n') + '\n' + failedMessages.slice(0, 3).join('\n'));
      }
    } catch (error) {
      const message = String((error && error.message) || '提案確定に失敗しました');
      setSuggestStatus(message, true);
      if (message.indexOf('対象店舗が不正です') === -1) {
        window.alert(message);
      }
    } finally {
      if (confirmSuggestionsButton) {
        confirmSuggestionsButton.disabled = false;
      }
    }
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function formatTimeRange(from, to) {
    const start = (from || '').slice(0, 5);
    const end = (to || '').slice(0, 5);
    if (start && end) {
      return start + ' - ' + end;
    }
    return start || end || '時間未設定';
  }

  function formatDateTimeShort(value) {
    const text = String(value || '').trim();
    if (text === '') {
      return '';
    }
    const parts = text.split(' ');
    if (parts.length >= 2) {
      return parts[1].slice(0, 5);
    }
    return text.slice(-5);
  }

  function haversineKm(lat1, lng1, lat2, lng2) {
    const earthRadiusKm = 6371.0;
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLng = (lng2 - lng1) * Math.PI / 180;
    const a = Math.sin(dLat / 2) * Math.sin(dLat / 2)
      + Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180)
      * Math.sin(dLng / 2) * Math.sin(dLng / 2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return earthRadiusKm * c;
  }

  function buildStoreScopedTag(storeId, shopTag, castId) {
    const prefix = storeShortLabels[String(storeId || '')] || String(storeId || '');
    const localTag = String(shopTag || (Number(castId || 0) > 0 ? castId : '')).trim();
    if (prefix && localTag) {
      return prefix + localTag;
    }
    return localTag || prefix;
  }

  function resolveItemStoreId(item) {
    const itemStoreId = Number(item && item.store_id ? item.store_id : 0);
    if (itemStoreId > 0) {
      return itemStoreId;
    }
    const selectedStoreId = Number(storeSelect && storeSelect.value ? storeSelect.value : 0);
    if (selectedStoreId > 0) {
      return selectedStoreId;
    }
    const initialStoreId = Number((pageConfig.initialFilters && pageConfig.initialFilters.store_id) || 0);
    if (initialStoreId > 0) {
      return initialStoreId;
    }
    const baseStoreId = Number((window.__transportBase && window.__transportBase.store_id) || 0);
    if (baseStoreId > 0) {
      return baseStoreId;
    }
    if (Array.isArray(window.__transportBases) && window.__transportBases.length === 1) {
      const singleBaseStoreId = Number(window.__transportBases[0] && window.__transportBases[0].store_id ? window.__transportBases[0].store_id : 0);
      if (singleBaseStoreId > 0) {
        return singleBaseStoreId;
      }
    }
    if (storeSelect && storeSelect.options && storeSelect.options.length === 1) {
      const onlyStoreId = Number(storeSelect.options[0].value || 0);
      if (onlyStoreId > 0) {
        return onlyStoreId;
      }
    }
    return 0;
  }

  function resolveSuggestionStoreId(item, suggestion) {
    const suggestionStoreId = Number(suggestion && suggestion.store_id ? suggestion.store_id : 0);
    if (suggestionStoreId > 0) {
      return suggestionStoreId;
    }
    const groupMatch = String((suggestion && suggestion.group_id) || '').match(/^S(\d+)-/);
    if (groupMatch) {
      const groupStoreId = Number(groupMatch[1] || 0);
      if (groupStoreId > 0) {
        return groupStoreId;
      }
    }
    const currentStoreId = Number(pageConfig.currentStoreId || 0);
    if (currentStoreId > 0) {
      return currentStoreId;
    }
    return resolveItemStoreId(item);
  }

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    fetchData(true);
  });

  if (reloadButton) {
    reloadButton.addEventListener('click', function () {
      fetchData(true);
    });
  }

  if (autoAssignButton) {
    autoAssignButton.addEventListener('click', runAutoAssign);
  }

  if (resetSuggestionsButton) {
    resetSuggestionsButton.addEventListener('click', resetSuggestions);
  }

  if (confirmSuggestionsButton) {
    confirmSuggestionsButton.addEventListener('click', confirmSuggestions);
  }

  if (storeSelect) {
    storeSelect.addEventListener('change', function () {
      updateDriverOptions();
      fetchData(true);
    });
  }

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible' && autoRefreshEnabled) {
      fetchData(false);
    }
  });

  if (autoRefreshToggleButton) {
    autoRefreshToggleButton.addEventListener('click', toggleAutoRefresh);
    updateAutoRefreshToggleButton();
  }

  updateDriverOptions();
  fetchData(false);
})();
