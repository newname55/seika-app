(function () {
  'use strict';

  const configRoot = window.WBSS_TRANSPORT_MAP_CONFIG || {};
  const pageConfig = configRoot.page || {};
  const driversByStore = configRoot.driversByStore || {};
  const apiUrl = pageConfig.apiUrl || '/wbss/public/api/transport_map.php';
  const statusOptions = pageConfig.statusOptions || {};
  const focusCastId = Number(pageConfig.focusCastId || 0);
  const form = document.getElementById('transportMapFilterForm');
  const reloadButton = document.getElementById('transportMapReload');
  const storeSelect = document.getElementById('transportMapStore');
  const driverSelect = document.getElementById('transportMapDriver');
  const listEl = document.getElementById('transportMapList');
  const mapBadgeEl = document.getElementById('transportMapMapBadge');
  const footNoteEl = document.getElementById('transportMapFootNote');

  if (!form || !listEl) {
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
  let storeMarker = null;
  let rangeLayer = null;
  let markerById = new Map();
  let rowById = new Map();
  let itemById = new Map();
  let itemIdByCastId = new Map();
  let activeId = null;
  let lastFetchSeq = 0;

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
  }

  function updateDriverOptions() {
    if (!driverSelect || !storeSelect) {
      return;
    }
    const storeId = String(storeSelect.value || '0');
    const selected = driverSelect.value;
    const options = driversByStore[storeId] || [];
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
      const tagText = item.shop_tag ? '【' + item.shop_tag + '】' : '';
      const displayLabel = (tagText ? tagText + ' ' : '') + (item.display_name || item.cast_name || '-');
      const status = statusOptions[item.status] || {};
      const unassignedClass = item.driver_user_id === null ? ' is-unassigned' : '';
      const noCoordsClass = item.has_coords ? '' : ' is-disabled';
      const timeText = formatTimeRange(item.pickup_time_from, item.pickup_time_to);
      const noteText = item.note_exists ? 'メモあり' : 'メモなし';
      const distanceText = item.distance_km !== null ? item.distance_km.toFixed(1) + 'km' : '距離未計算';
      const driverText = item.driver_name || '未割当';
      const addressText = item.pickup_address_short || '住所未登録';
      const sourceBadge = item.source_type === 'shift_plan' ? '<span class="transportMapSourceTag">勤務予定由来</span>' : '';
      return '' +
        '<article class="transportMapRow' + unassignedClass + noCoordsClass + '" data-row-id="' + escapeHtml(String(item.id)) + '">' +
          '<div class="transportMapRowHead">' +
            '<div>' +
              '<div class="transportMapRowNameWrap">' +
                '<div class="transportMapRowName">' + escapeHtml(displayLabel) + '</div>' +
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

  function renderMap(base, items) {
    ensureMap();
    if (!map || !clusterLayer || !rangeLayer) {
      if (mapBadgeEl) {
        mapBadgeEl.textContent = '地図初期化失敗';
      }
      return;
    }

    clusterLayer.clearLayers();
    rangeLayer.clearLayers();
    markerById = new Map();

    if (storeMarker) {
      map.removeLayer(storeMarker);
      storeMarker = null;
    }

    const bounds = [];
    if (base && base.lat !== null && base.lng !== null) {
      const latLng = [base.lat, base.lng];
      storeMarker = L.marker(latLng, {
        icon: buildStoreIcon()
      }).addTo(map);
      storeMarker.bindPopup('<div class="transportMapPopup"><b>' + escapeHtml(base.name || '店舗') + '</b><div>店舗基準点</div></div>');
      bounds.push(latLng);
      L.circle(latLng, { radius: 5000, color: '#94a3b8', weight: 1, fillOpacity: 0.03 }).addTo(rangeLayer);
      L.circle(latLng, { radius: 10000, color: '#64748b', weight: 1, dashArray: '4 4', fillOpacity: 0.02 }).addTo(rangeLayer);
    }

    items.forEach(function (item) {
      if (!item.has_coords) {
        return;
      }
      const latLng = [item.pickup_lat, item.pickup_lng];
      const marker = L.marker(latLng, {
        icon: buildStatusIcon(item.status, item.driver_user_id === null)
      });
      marker.bindPopup(buildPopupHtml(item));
      marker.on('click', function () {
        focusItem(item.id, false);
      });
      clusterLayer.addLayer(marker);
      markerById.set(item.id, marker);
      bounds.push(latLng);
    });

    if (bounds.length) {
      map.fitBounds(bounds, { padding: [36, 36], maxZoom: 13 });
    } else {
      map.setView([34.665, 133.919], 11);
    }

    if (mapBadgeEl) {
      const mappedCount = items.filter(function (item) { return item.has_coords; }).length;
      mapBadgeEl.textContent = mappedCount + '件を地図表示';
    }
    if (footNoteEl) {
      const hiddenCount = items.filter(function (item) { return !item.has_coords; }).length;
      footNoteEl.textContent = hiddenCount > 0
        ? '緯度経度がない ' + hiddenCount + ' 件は一覧のみ表示しています。'
        : 'すべての対象を地図表示できます。';
    }
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

  function buildStatusIcon(status, isUnassigned) {
    const color = (statusOptions[status] && statusOptions[status].color) || '#475569';
    const className = isUnassigned ? ' transportMapMarkerIcon--unassigned' : '';
    return L.divIcon({
      className: 'transportMapMarkerWrap',
      html: '<span class="transportMapMarkerIcon' + className + '" style="--pin-color:' + escapeHtml(color) + '"></span>',
      iconSize: [20, 20],
      iconAnchor: [10, 10],
      popupAnchor: [0, -10]
    });
  }

  function buildStoreIcon() {
    return L.divIcon({
      className: 'transportMapStoreWrap',
      html: '<span class="transportMapStoreIcon">店</span>',
      iconSize: [26, 26],
      iconAnchor: [13, 13]
    });
  }

  function buildPopupHtml(item) {
    const status = statusOptions[item.status] || {};
    const tagText = item.shop_tag ? '【' + item.shop_tag + '】' : '';
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

  async function fetchData(pushHistory) {
    const seq = ++lastFetchSeq;
    const params = serializeForm();
    params.set('_ts', String(Date.now()));
    const url = apiUrl + '?' + params.toString();

    if (reloadButton) {
      reloadButton.disabled = true;
    }
    listEl.innerHTML = '<div class="transportMapEmpty">送迎データを読み込んでいます…</div>';
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
      renderList(json.items || []);
      renderMap(json.base || {}, json.items || []);
      if (focusCastId > 0 && activeId === null) {
        focusCast(focusCastId, true);
      }

      if (pushHistory) {
        const nextUrl = '/wbss/public/transport/map.php?' + params.toString();
        window.history.replaceState({}, '', nextUrl);
      }
    } catch (error) {
      listEl.innerHTML = '<div class="transportMapEmpty transportMapEmptyError">' + escapeHtml(error.message || '送迎データの取得に失敗しました') + '</div>';
      renderChipSummary(summaryRefs.direction, {}, '取得失敗');
      renderChipSummary(summaryRefs.driver, {}, '取得失敗');
      if (mapBadgeEl) {
        mapBadgeEl.textContent = '取得失敗';
      }
    } finally {
      if (reloadButton) {
        reloadButton.disabled = false;
      }
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

    const payload = new URLSearchParams();
    payload.set('action', 'save_assignment');
    payload.set('csrf_token', String(pageConfig.csrfToken || ''));
    payload.set('store_id', String(item.store_id || storeSelect.value || '0'));
    payload.set('business_date', String(item.business_date || ''));
    payload.set('cast_id', String(item.cast_id || '0'));
    payload.set('driver_user_id', driverUserId);
    payload.set('status', requestedStatus);

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
      window.alert(error.message || '送迎割当の保存に失敗しました');
    } finally {
      if (triggerEl) {
        triggerEl.disabled = false;
        triggerEl.textContent = '保存';
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

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    fetchData(true);
  });

  if (reloadButton) {
    reloadButton.addEventListener('click', function () {
      fetchData(true);
    });
  }

  if (storeSelect) {
    storeSelect.addEventListener('change', function () {
      updateDriverOptions();
      fetchData(true);
    });
  }

  updateDriverOptions();
  fetchData(false);
})();
