/* /seika-app/public/orders/assets/orders.js
   - ticket_id を必ず送る（DBの order_orders.ticket_id に入る）
   - Response が空で json() 失敗する時も分かるエラーを出す
   - ✅ 注文したことが「目に見える」: トースト + 成功表示 + 直近注文ログ
   - ✅ 302 login 等でJSONじゃない時も分かる
*/

(function () {
  'use strict';

  const el = (id) => document.getElementById(id);

  // ------------------------------
  // 0) 初期データ（index.php の window.ORDERS から）
  // ------------------------------
  const BOOT = (window.ORDERS || {});
  const state = {
    apiBase: BOOT.apiBase || '/seika-app/public/api/orders.php',
    tableId: Number(BOOT.tableId || 0),   // order_tables.id（※卓番号じゃない）
    ticketId: Number(BOOT.ticketId || 0), // tickets.id
    seatId: Number(BOOT.seatId || 0),
    ticketStatus: String(BOOT.ticketStatus || ''),
    categories: [],
    cart: new Map(), // menu_id -> { qty, note, menu }
  };

  // デバッグ：ブラウザConsoleで確認できるように
  window.__ORDERS_STATE__ = state;
  // ------------------------------
  // 注文時音を出す（安定版）
  // ------------------------------
  const orderSound = new Audio('/seika-app/public/assets/sounds/order.mp3');
  orderSound.preload = 'auto';
  orderSound.volume = 0.8;

  function playOrderSound(){
    try{
      orderSound.currentTime = 0;
      orderSound.play().then(()=>{
        console.log('sound OK');
      }).catch(e=>{
        console.error('sound error:', e);
      });
    }catch(e){
      console.warn('sound exception', e);
    }
  }
  // ------------------------------
  // 1) UIユーティリティ
  // ------------------------------
  function setMsg(s) {
    const m = el('msg');
    if (!m) return;
    m.textContent = String(s || '');
  }

  function yen(n) {
    const v = Number(n || 0);
    try { return v.toLocaleString('ja-JP'); } catch { return String(v); }
  }

  function openModal() {
    const m = el('modal');
    if (!m) return;
    m.classList.remove('hidden');
  }
  function closeModal() {
    const m = el('modal');
    if (!m) return;
    m.classList.add('hidden');
  }

  function updateCartCount() {
    const c = el('cartCount');
    if (!c) return;
    let total = 0;
    for (const [, v] of state.cart) total += Number(v.qty || 0);
    c.textContent = String(total);
  }

  function escapeHtml(s) {
    return String(s)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  // ------------------------------
  // 1.5) ✅ トースト（モーダル閉じても見える）
  // ------------------------------
  function toast(text, type = 'ok', ms = 2600) {
    let wrap = document.getElementById('toastWrap');
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.id = 'toastWrap';
      wrap.style.position = 'fixed';
      wrap.style.right = '14px';
      wrap.style.bottom = '14px';
      wrap.style.zIndex = '9999';
      wrap.style.display = 'grid';
      wrap.style.gap = '10px';
      document.body.appendChild(wrap);
    }

    const t = document.createElement('div');
    t.textContent = text;
    t.style.padding = '12px 14px';
    t.style.borderRadius = '14px';
    t.style.fontWeight = '900';
    t.style.border = '1px solid rgba(255,255,255,.18)';
    t.style.boxShadow = '0 10px 24px rgba(0,0,0,.35)';
    t.style.backdropFilter = 'blur(6px)';
    t.style.transform = 'translateY(10px)';
    t.style.opacity = '0';
    t.style.transition = 'all .18s ease';

    if (type === 'ok') {
      t.style.background = 'linear-gradient(135deg, rgba(57,217,138,.95), rgba(10,140,80,.95))';
      t.style.color = '#04130c';
    } else if (type === 'ng') {
      t.style.background = 'linear-gradient(135deg, rgba(255,92,92,.95), rgba(160,10,10,.95))';
      t.style.color = '#1a0505';
    } else {
      t.style.background = 'linear-gradient(135deg, rgba(255,213,79,.95), rgba(255,170,0,.95))';
      t.style.color = '#1a1203';
    }

    wrap.appendChild(t);
    requestAnimationFrame(() => {
      t.style.transform = 'translateY(0)';
      t.style.opacity = '1';
    });

    setTimeout(() => {
      t.style.opacity = '0';
      t.style.transform = 'translateY(10px)';
      setTimeout(() => t.remove(), 220);
    }, ms);
  }

  // ------------------------------
  // 1.6) ✅ 直近注文ログ（モーダル内に残す）
  // ------------------------------
  function ensureRecentBox() {
    let box = document.getElementById('recentOrders');
    if (box) return box;

    const foot = document.querySelector('.cart-foot');
    if (!foot) return null;

    box = document.createElement('div');
    box.id = 'recentOrders';
    box.style.marginTop = '10px';
    box.style.paddingTop = '10px';
    box.style.borderTop = '1px solid rgba(255,255,255,.12)';
    box.innerHTML = `
      <div style="font-weight:1000; margin-bottom:8px;">🧾 直近の注文</div>
      <div id="recentOrdersList" style="display:grid; gap:8px;"></div>
    `;
    foot.appendChild(box);
    return box;
  }

  function addRecentOrderView({ orderId, ticketId, items }) {
    ensureRecentBox();
    const list = document.getElementById('recentOrdersList');
    if (!list) return;

    const dt = new Date();
    const time = dt.toLocaleTimeString('ja-JP', { hour: '2-digit', minute: '2-digit' });

    const wrap = document.createElement('div');
    wrap.style.border = '1px solid rgba(255,255,255,.12)';
    wrap.style.borderRadius = '14px';
    wrap.style.padding = '10px 12px';
    wrap.style.background = 'rgba(255,255,255,.03)';

    const lines = (items || []).map(x => `・${escapeHtml(x.name)} ×${Number(x.qty || 0)}`).join('<br>');

    wrap.innerHTML = `
      <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap;">
        <div style="font-weight:1000;">注文 #${Number(orderId)} / 伝票 ${ticketId ? Number(ticketId) : '-'}</div>
        <div style="opacity:.75; font-size:12px;">${escapeHtml(time)}</div>
      </div>
      <div style="margin-top:6px; font-size:13px; line-height:1.4; opacity:.9;">
        ${lines || '<span style="opacity:.75">（明細なし）</span>'}
      </div>
    `;

    list.prepend(wrap);
    while (list.children.length > 10) list.lastChild.remove();
  }

  // ------------------------------
  // 2) fetch helper（空レスポンス対策込み）
  // ------------------------------
  async function fetchJsonOrThrow(res) {
    const text = await res.text(); // まず text で読む（空を検知するため）
    if (!text || !text.trim()) {
      throw new Error(`APIが空レスポンスでした（HTTP ${res.status}）`);
    }
    let js;
    try {
      js = JSON.parse(text);
    } catch (e) {
      throw new Error(`JSON parse失敗: ${String(e)} / body=${text.slice(0, 200)}`);
    }
    return js;
  }

  // ------------------------------
  // 3) カート描画
  // ------------------------------
  function renderCart() {
    const list = el('cartList');
    if (!list) return;

    if (state.cart.size === 0) {
      list.innerHTML = '<div class="muted">カートは空です</div>';
      updateCartCount();
      return;
    }

    let html = '';
    for (const [menuId, v] of state.cart.entries()) {
      const menu = v.menu || {};
      const name = menu.name || `menu#${menuId}`;
      const price = Number(menu.price_ex || 0);
      const qty = Number(v.qty || 0);
      const note = String(v.note || '');

      html += `
        <div class="cart-row">
          <div>
            <div style="font-weight:1000">${escapeHtml(name)}</div>
            <div class="sub">￥${yen(price)} × ${qty}${note ? ` ／ メモ: ${escapeHtml(note)}` : ''}</div>
          </div>
          <div class="qty">
            <button class="btn" data-act="dec" data-id="${menuId}">-</button>
            <div class="n">${qty}</div>
            <button class="btn" data-act="inc" data-id="${menuId}">+</button>
            <button class="btn" data-act="del" data-id="${menuId}">削除</button>
          </div>
        </div>
      `;
    }
    list.innerHTML = html;

    // ハンドラ（イベント委譲）
    list.onclick = (e) => {
      const b = e.target && e.target.closest && e.target.closest('button[data-act]');
      if (!b) return;
      const act = b.getAttribute('data-act');
      const id = Number(b.getAttribute('data-id') || 0);
      if (!id) return;

      const cur = state.cart.get(id);
      if (!cur) return;

      if (act === 'inc') cur.qty = Number(cur.qty || 0) + 1;
      if (act === 'dec') cur.qty = Math.max(1, Number(cur.qty || 0) - 1);
      if (act === 'del') state.cart.delete(id);

      updateCartCount();
      renderCart();
    };

    updateCartCount();
  }

  // ------------------------------
  // 4) メニュー描画
  // ------------------------------
  function renderMenus() {
    const root = el('categories');
    if (!root) return;

    if (!Array.isArray(state.categories) || state.categories.length === 0) {
      root.innerHTML = '<div class="card" style="padding:14px">メニューがありません</div>';
      return;
    }

    let html = '';
    for (const cat of state.categories) {
      const cname = cat.category_name || 'カテゴリ';
      const items = Array.isArray(cat.items) ? cat.items : [];

      html += `
        <div class="cat">
          <div class="cat-head">
            <div class="cat-title">${escapeHtml(cname)}</div>
          </div>
          <div class="menu-grid">
      `;

      for (const m of items) {
        const id = Number(m.id || 0);
        const name = m.name || '';
        const price = Number(m.price_ex || 0);
        const img = m.image_url || '';
        const desc = m.description || '';
        const soldout = !!m.is_sold_out;

        html += `
          <div class="menu-item" data-menu="${id}">
            ${img
              ? `<img src="${escapeHtml(img)}" alt="">`
              : `<div style="height:140px;display:flex;align-items:center;justify-content:center;border-bottom:1px solid var(--line);color:var(--muted);">NO IMAGE</div>`
            }
            <div class="menu-pad">
              <div class="menu-name">${escapeHtml(name)}</div>
              ${desc ? `<div class="menu-desc">${escapeHtml(desc)}</div>` : ``}
              <div class="row">
                <div class="badge ${soldout ? 'soldout' : ''}">
                  ￥${yen(price)}
                </div>
                <button class="btn ${soldout ? 'disabled' : 'primary'}" ${soldout ? 'disabled' : ''} data-add="${id}">
                  追加
                </button>
              </div>
            </div>
          </div>
        `;
      }

      html += `
          </div>
        </div>
      `;
    }

    root.innerHTML = html;

    // 追加ボタン
    root.onclick = (e) => {
      const btn = e.target && e.target.closest && e.target.closest('button[data-add]');
      if (!btn) return;
      const id = Number(btn.getAttribute('data-add') || 0);
      if (!id) return;

      const menu = findMenuById(id);
      if (!menu) return;

      const cur = state.cart.get(id);
      if (cur) cur.qty = Number(cur.qty || 0) + 1;
      else state.cart.set(id, { qty: 1, note: '', menu });

      updateCartCount();
      renderCart();
      setMsg('');
      toast('カートに追加しました', 'info', 1200);
    };
  }

  function findMenuById(id) {
    for (const c of state.categories) {
      const items = Array.isArray(c.items) ? c.items : [];
      for (const m of items) {
        if (Number(m.id || 0) === Number(id)) return m;
      }
    }
    return null;
  }

  // ------------------------------
  // 5) メニュー取得
  // ------------------------------
  async function loadMenus() {
    setMsg('');
    const res = await fetch(`${state.apiBase}?action=menus`, { method: 'GET', credentials: 'same-origin' });
    const js = await fetchJsonOrThrow(res);
    if (!js.ok) throw new Error(js.error || 'メニュー取得に失敗');
    state.categories = js.categories || [];
    renderMenus();
  }

  // ------------------------------
  // 6) 注文作成（ここが本命）
  // ------------------------------
  async function submitOrder() {
    setMsg('');

    // ✅ 必須条件
    if (state.tableId <= 0) return setMsg('卓番号が未指定です（tableId解決失敗）');
    if (state.ticketId <= 0) return setMsg('伝票が未指定です（ticket_idが必要）');
    if (state.cart.size === 0) return setMsg('カートが空です');

    // API用items
    const items = [];
    // 表示用（直近注文ログ）
    const itemsView = [];

    for (const [menuId, c] of state.cart.entries()) {
      const menu = c.menu || {};
      items.push({
        menu_id: Number(menuId),
        qty: Number(c.qty || 1),
        note: String(c.note || ''),
      });
      itemsView.push({
        name: String(menu.name || `menu#${menuId}`),
        qty: Number(c.qty || 1),
      });
    }

    const btn = el('submitOrder');
    const oldText = btn ? btn.textContent : '';
    if (btn) {
      btn.disabled = true;
      btn.textContent = '送信中…';
    }
    toast('注文を送信中…', 'info', 1200);

    try {
      const payload = {
        table_id: Number(state.tableId),
        ticket_id: Number(state.ticketId),
        note: String((el('orderNote') && el('orderNote').value) ? el('orderNote').value : '').trim(),
        items,
      };

      const res = await fetch(`${state.apiBase}?action=create`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
        credentials: 'same-origin', // ✅ セッション維持（重要）
      });

      // ✅ 302 login等で JSONじゃない時を明示
      const ct = String(res.headers.get('content-type') || '').toLowerCase();
      if (!ct.includes('application/json')) {
        throw new Error(`注文に失敗（JSONではない応答 / HTTP ${res.status}）※ログイン切れの可能性`);
      }

      const js = await fetchJsonOrThrow(res);
      if (!js.ok) throw new Error(js.error || '注文に失敗');

      // ✅ 直近注文ログに残す（「送れた」が残る）
      addRecentOrderView({
        orderId: js.order_id,
        ticketId: state.ticketId,
        items: itemsView
      });

      // ✅ 成功を画面に見える形で出す（モーダル閉じても見える）
      toast(`✅ 注文完了！ 注文ID:${js.order_id} / 伝票:${state.ticketId}`, 'ok', 2800);
      
      // ✅ 成功時音を出す
      playOrderSound();

      // モーダル内にも残す（閉じない運用でも見える）
      setMsg(`✅ 注文完了（注文ID: ${js.order_id} / 伝票ID: ${state.ticketId}）`);

      // ✅ カートクリア
      state.cart.clear();
      if (el('orderNote')) el('orderNote').value = '';
      renderCart();
      updateCartCount();

      // ✅ ここがポイント：閉じるなら「トーストがある」ので消えない
      closeModal();

    } catch (e) {
      const msg = String((e && e.message) ? e.message : e);
      setMsg(msg);
      toast(`❌ ${msg}`, 'ng', 3400);
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.textContent = oldText || '注文する';
      }
    }
  }

  // ------------------------------
  // 7) 初期イベント
  // ------------------------------
  function bindUI() {
    const cartBtn = el('cartBtn');
    if (cartBtn) cartBtn.onclick = () => { openModal(); renderCart(); ensureRecentBox(); };

    const modalBg = el('modalBg');
    if (modalBg) modalBg.onclick = closeModal;

    const closeBtn = el('closeModal');
    if (closeBtn) closeBtn.onclick = closeModal;

    const submitBtn = el('submitOrder');
    if (submitBtn) submitBtn.onclick = submitOrder;

    // “Enterで送信” を防ぐ（誤爆防止）
    const note = el('orderNote');
    if (note) {
      note.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') e.preventDefault();
      });
    }
  }

  // ------------------------------
  // 8) 起動
  // ------------------------------
  (async function boot() {
    try {
      bindUI();
      await loadMenus();

      // 最初のメッセージ
      if (state.tableId <= 0) setMsg('卓番号が未指定です（tableId解決失敗）');
      else if (state.ticketId <= 0) setMsg('伝票が未指定です（ticket_idが必要）');

    } catch (e) {
      const msg = String((e && e.message) ? e.message : e);
      setMsg(msg);
      toast(`❌ ${msg}`, 'ng', 3400);
    }
  })();

})();