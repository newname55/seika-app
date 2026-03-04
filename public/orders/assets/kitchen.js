const root = document.getElementById('kitchen');
const statusText = document.getElementById('statusText');
const reloadBtn = document.getElementById('reloadBtn');

const apiBase = window.KITCHEN?.apiBase || "/seika-app/public/api/orders.php";

function escapeHtml(s){
  return String(s ?? '').replace(/[&<>"']/g, (m)=>({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[m]));
}

async function fetchOrders(){
  const res = await fetch(`${apiBase}?action=kitchen_list`);
  const js = await res.json();
  if (!js.ok) throw new Error(js.error || 'failed');
  return js.orders || [];
}

async function updateItem(itemId, status){
  const res = await fetch(`${apiBase}?action=item_status`, {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({item_id: itemId, status})
  });
  const js = await res.json();
  if (!js.ok) throw new Error(js.error || 'update failed');
}

function render(orders){
  root.innerHTML = '';
  if (orders.length === 0){
    root.innerHTML = `<div class="muted">新規/調理中の注文はありません</div>`;
    return;
  }

  for (const o of orders){
    const card = document.createElement('div');
    card.className = 'card';
    card.style.marginBottom = '0.9rem';
    card.innerHTML = `
      <div style="display:flex;justify-content:space-between;gap:1rem">
        <div style="font-weight:900;font-size:1.1rem">卓 ${escapeHtml(o.table_name)} / 注文 #${o.order_id}</div>
        <div class="muted">${escapeHtml(o.created_at)}</div>
      </div>
      ${o.order_note ? `<div class="muted" style="margin:.35rem 0 .6rem">メモ: ${escapeHtml(o.order_note)}</div>` : ``}
      <div data-items></div>
    `;

    const itemsWrap = card.querySelector('[data-items]');
    itemsWrap.style.display = 'grid';
    itemsWrap.style.gap = '.6rem';

    for (const it of (o.items || [])){
      const row = document.createElement('div');
      row.className = 'cart-row';
      row.innerHTML = `
        <div>
          <div style="font-weight:800">${escapeHtml(it.menu_name)} × ${it.qty}</div>
          <div class="sub">${escapeHtml(it.item_note || '')}</div>
          <div class="badge" style="margin-top:.45rem">${escapeHtml(it.item_status)}</div>
        </div>
        <div class="qty">
          <button class="btn primary" data-act="cooking">調理中</button>
          <button class="btn primary" data-act="served">提供済</button>
          <button class="btn danger"  data-act="canceled">取消</button>
        </div>
      `;
      row.querySelector('[data-act="cooking"]').addEventListener('click', async ()=>{
        await updateItem(it.item_id,'cooking'); await reload();
      });
      row.querySelector('[data-act="served"]').addEventListener('click', async ()=>{
        await updateItem(it.item_id,'served'); await reload();
      });
      row.querySelector('[data-act="canceled"]').addEventListener('click', async ()=>{
        await updateItem(it.item_id,'canceled'); await reload();
      });
      itemsWrap.appendChild(row);
    }

    root.appendChild(card);
  }
}

async function reload(){
  statusText.textContent = '更新中...';
  reloadBtn.disabled = true;
  try{
    const orders = await fetchOrders();
    render(orders);
    statusText.textContent = `更新: ${new Date().toLocaleTimeString()}`;
  }catch(e){
    root.innerHTML = `<div class="card warn"><b>取得失敗</b><div class="muted">${escapeHtml(e.message || String(e))}</div></div>`;
    statusText.textContent = '更新: 失敗';
  }finally{
    reloadBtn.disabled = false;
  }
}

reloadBtn.addEventListener('click', reload);
setInterval(reload, 5000);
reload();