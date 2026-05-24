const API = '../api/';
let currentUser = null, customerData = null;
let allProducts = [], allMerchants = [];
let customerOrders = [];
// Haversine distance (km)
function distanceKm(lat1, lon1, lat2, lon2) {
  const toRad = v => v * Math.PI / 180;
  const R = 6371; // km
  const dLat = toRad(lat2 - lat1);
  const dLon = toRad(lon2 - lon1);
  const a = Math.sin(dLat/2) * Math.sin(dLat/2) + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLon/2) * Math.sin(dLon/2);
  const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
  return R * c;
}
// cart item shape: {id, name, price, qty, maxQty, merchant_id, image_path, checked}
let cart = [];
let pickedLat = 10.3157, pickedLng = 123.8854, pickedAddress = '';
let locationMap = null, locationMarker = null;
let profileMenuOpen = false;
let selectedTipModalAmount = 0;
let currentModalProduct = null, modalQty = 1;

async function api(path, opts = {}) {
  try {
    const isForm = opts.body instanceof FormData;
    const r = await fetch(API + path, { headers: isForm ? {} : { 'Content-Type': 'application/json' }, ...opts });
    return await r.json();
  } catch(e) { return { ok: false, error: e.message }; }
}

// ── Init ───────────────────────────────────────
(async () => {
  const me = await api('auth/?action=me');
  if (!me.ok || me.data.role !== 'customer') { window.location.href = '../login.html'; return; }
  currentUser = me.data;
  document.getElementById('greetName').textContent = currentUser.name?.split(' ')[0] || 'Customer';
  document.getElementById('avatarBtn').textContent = (currentUser.name || 'C')[0].toUpperCase();
  document.getElementById('pEmail').value = currentUser.email || '';

  const cp = await api('customers/');
  if (cp.ok && cp.data) {
    customerData = cp.data;
    document.getElementById('pFName').value = cp.data.fname || '';
    document.getElementById('pLName').value = cp.data.lname || '';
    const ph = (cp.data.phone || '').replace(/^(\+63|0|63)/, '');
    document.getElementById('pPhone').value = ph;
    if (cp.data.address) {
      document.getElementById('pAddress').value = cp.data.address;
    }
    updateCustomerProfilePreview();
  }

  restoreLocation();
  loadCart();
  await Promise.all([loadMerchants(), loadProducts()]);
  await loadOrders();
})();

// ── Data ───────────────────────────────────────
async function loadMerchants() {
  const d = await api('merchants/');
  allMerchants = d.data || [];
  renderMerchants();
}

async function loadProducts(cat = 'all', limit = 200) {
  // Always fetch a full list client-side so we can filter multi-category products reliably
  const d = await api('products/?all=1&limit=' + limit);
  allProducts = d.data || [];
  // Sync maxQty into existing cart items
  cart.forEach(item => {
    const p = allProducts.find(x => x.id == item.id);
    if (p) item.maxQty = parseInt(p.qty || 0);
  });
  renderHomeProducts();
  renderProductGrid(allProducts, 'productGrid');
}

async function loadOrders() {
  const d = await api('orders/');
  const orders = d.data || [];
  customerOrders = orders;
  document.getElementById('statOrders').textContent = orders.length;
  document.getElementById('statActive').textContent = orders.filter(o => ['Pending', 'Ready for Delivery', 'Delivering'].includes(o.status)).length;
  document.getElementById('statDone').textContent = orders.filter(o => o.status === 'Delivered').length;
  renderOrders(orders);
}

// ── Render ─────────────────────────────────────
function renderHomeProducts() { renderProductGrid(allProducts.slice(0, 8), 'homeProdGrid'); }

function renderProductGrid(list, gridId) {
  const el = document.getElementById(gridId); if (!el) return;
  if (!list.length) {
    el.innerHTML = '<div class="empty-state" style="grid-column:1/-1"><div class="empty-state-icon">🛍️</div><h4>No products found</h4></div>';
    return;
  }
  el.innerHTML = list.map(p => {
    const m = allMerchants.find(x => x.id == p.merchant_id);
    const stock = parseInt(p.qty || 0);
    const soldOut = p.status !== 'Available' || stock === 0;
    const lowStock = !soldOut && stock <= 5;
    const imgHtml = p.image_path ? '<img src="../' + p.image_path + '" alt="' + esc(p.name) + '">' : '🛍️';
    // render category badges (support multiple categories stored as JSON array or comma-separated)
    const catSource = p.category_ids || p.category_id || '';
    let catList = [];
    if (Array.isArray(catSource)) {
      catList = catSource;
    } else if (typeof catSource === 'string' && catSource.trim().startsWith('[')) {
      try { catList = JSON.parse(catSource); } catch (e) { catList = catSource.split(/\s*,\s*/).filter(Boolean); }
    } else {
      catList = (typeof catSource === 'string' && catSource.trim()) ? catSource.split(/\s*,\s*/).filter(Boolean) : [];
    }
    const catHtml = catList.map(c => '<span class="badge badge-blue" style="margin-right:6px;font-size:11px;padding:4px 8px;">' + esc(c) + '</span>').join('');

    return '<div class="product-card' + (soldOut ? ' sold-out' : '') + '" onclick="openProductModal(' + p.id + ')">' +
      '<div class="product-img-wrap">' + imgHtml +
        (soldOut ? '<div class="sold-out-overlay"><span class="sold-out-label">SOLD OUT</span></div>' : '') +
      '</div>' +
      '<div class="product-info">' +
        '<div class="product-name">' + esc(p.name) + '</div>' +
        '<div class="product-store">' + (m ? esc(m.name) : 'Store') + '</div>' +
        (catHtml ? '<div style="margin:6px 0;">' + catHtml + '</div>' : '') +
        (lowStock ? '<div class="product-stock-hint">⚠ Only ' + stock + ' left!</div>' : '') +
        '<div class="product-row">' +
          '<div class="product-price">₱' + parseFloat(p.price || 0).toFixed(2) + '</div>' +
          (soldOut
            ? '<span class="out-stock-tag">Sold Out</span>'
            : '<button class="btn-add" onclick="event.stopPropagation();quickAddToCart(' + p.id + ')">+</button>') +
        '</div>' +
      '</div>' +
    '</div>';
  }).join('');
}

// ── Product modal ──────────────────────────────
function openProductModal(prodId) {
  const p = allProducts.find(x => x.id == prodId); if (!p) return;
  const m = allMerchants.find(x => x.id == p.merchant_id);
  currentModalProduct = p; modalQty = 1;
  const stock = parseInt(p.qty || 0);
  const soldOut = p.status !== 'Available' || stock === 0;

  document.getElementById('prodModalTitle').textContent = p.name;
  document.getElementById('prodModalImg').innerHTML = p.image_path
    ? '<img src="../' + p.image_path + '" style="width:100%;height:100%;object-fit:cover;">'
    : '🛍️';
  document.getElementById('prodModalStore').textContent = m ? '🏪 ' + m.name : '';
  document.getElementById('prodModalDesc').textContent = p.description || '';
  document.getElementById('prodModalPrice').textContent = '₱' + parseFloat(p.price || 0).toFixed(2);

  const statusEl = document.getElementById('prodModalStatus');
  statusEl.textContent = soldOut ? 'Sold Out' : 'Available';
  statusEl.className = 'badge ' + (soldOut ? 'badge-red' : 'badge-green');

  const stockInfoEl = document.getElementById('prodStockInfo');
  if (soldOut) {
    stockInfoEl.textContent = '';
    stockInfoEl.style.color = '';
  } else if (stock <= 10) {
    stockInfoEl.textContent = '⚠ Only ' + stock + ' left in stock!';
    stockInfoEl.style.color = 'var(--amber)';
  } else {
    stockInfoEl.textContent = stock + ' available';
    stockInfoEl.style.color = 'var(--ink-soft)';
  }

  document.getElementById('modalQty').textContent = 1;
  document.getElementById('modalTotal').textContent = '₱' + parseFloat(p.price || 0).toFixed(2);
  document.getElementById('modalStockWarning').style.display = 'none';
  document.getElementById('prodModalActions').style.display = soldOut ? 'none' : 'flex';
  document.getElementById('prodSoldOutMsg').style.display = soldOut ? 'block' : 'none';
  document.getElementById('modalQtyMinus').disabled = soldOut;
  document.getElementById('modalQtyPlus').disabled = soldOut || stock <= 1;
  document.getElementById('productModal').classList.add('open');
}

function changeModalQty(delta) {
  if (!currentModalProduct) return;
  const maxStock = parseInt(currentModalProduct.qty || 0);
  const inCart = cart.find(i => i.id == currentModalProduct.id);
  const alreadyInCart = inCart ? inCart.qty : 0;
  const maxCanAdd = Math.max(0, maxStock - alreadyInCart);
  const proposed = modalQty + delta;
  if (proposed < 1) return;
  if (proposed > maxStock) {
    const w = document.getElementById('modalStockWarning');
    w.textContent = 'Only ' + maxStock + ' in stock' + (alreadyInCart > 0 ? ' — ' + alreadyInCart + ' already in cart' : '');
    w.style.display = 'block';
    return;
  }
  modalQty = proposed;
  document.getElementById('modalQty').textContent = modalQty;
  document.getElementById('modalTotal').textContent = '₱' + (parseFloat(currentModalProduct.price) * modalQty).toFixed(2);
  document.getElementById('modalStockWarning').style.display = 'none';
  document.getElementById('modalQtyPlus').disabled = modalQty >= maxStock;
}

function modalAddToCart() {
  if (!currentModalProduct) return;
  addToCartInternal(currentModalProduct, modalQty);
  closeModal('productModal');
}

function modalBuyNow() {
  if (!currentModalProduct) return;
  addToCartInternal(currentModalProduct, modalQty);
  closeModal('productModal');
  // Open cart for checkout
  if (!document.getElementById('cartPanel').classList.contains('open')) toggleCart();
}

function quickAddToCart(prodId) {
  const p = allProducts.find(x => x.id == prodId);
  if (!p || p.status !== 'Available' || parseInt(p.qty || 0) === 0) {
    showToast('Product not available', 'error'); return;
  }
  addToCartInternal(p, 1);
}

function addToCartInternal(p, qty) {
  if (p.status !== 'Available' || parseInt(p.qty || 0) === 0) {
    showToast('Product not available', 'error'); return;
  }
  const maxStock = parseInt(p.qty || 0);
  const idx = cart.findIndex(i => i.id == p.id);
  if (idx > -1) {
    const newQty = cart[idx].qty + qty;
    if (newQty > maxStock) { showToast('Only ' + maxStock + ' in stock!', 'warn'); return; }
    cart[idx].qty = newQty;
  } else {
    cart.push({ id: p.id, name: p.name, price: parseFloat(p.price), qty: qty, maxQty: maxStock, merchant_id: p.merchant_id, image_path: p.image_path || null, checked: true });
  }
  localStorage.setItem('mm_cart', JSON.stringify(cart));
  updateCartUI();
  showToast(esc(p.name) + ' added ✓', 'success');
}

// ── Cart ───────────────────────────────────────
function loadCart() {
  try { cart = JSON.parse(localStorage.getItem('mm_cart') || '[]'); } catch(e) { cart = []; }
  cart.forEach(item => {
    const p = allProducts.find(x => x.id == item.id);
    if (p) item.maxQty = parseInt(p.qty || 0);
    if (item.checked === undefined) item.checked = true;
  });
  updateCartUI();
}

function changeCartQty(prodId, delta) {
  const idx = cart.findIndex(i => i.id == prodId); if (idx < 0) return;
  const newQty = cart[idx].qty + delta;
  if (newQty <= 0) { cart.splice(idx, 1); }
  else if (newQty > (cart[idx].maxQty || 9999)) { showToast('Only ' + cart[idx].maxQty + ' in stock!', 'warn'); return; }
  else { cart[idx].qty = newQty; }
  localStorage.setItem('mm_cart', JSON.stringify(cart));
  updateCartUI();
}

function toggleItemCheck(prodId, checked) {
  const idx = cart.findIndex(i => i.id == prodId); if (idx < 0) return;
  cart[idx].checked = checked;
  localStorage.setItem('mm_cart', JSON.stringify(cart));
  updateCartSummary();
  syncSelectAll();
}

function toggleSelectAll(checked) {
  cart.forEach(i => i.checked = checked);
  localStorage.setItem('mm_cart', JSON.stringify(cart));
  updateCartUI();
}

function syncSelectAll() {
  const allChecked = cart.length > 0 && cart.every(i => i.checked);
  const el = document.getElementById('selectAllCart');
  if (el) el.checked = allChecked;
}

function updateCartUI() {
  const total = cart.length;
  const badge = document.getElementById('cartBadge');
  badge.textContent = total; badge.style.display = total > 0 ? 'flex' : 'none';
  document.getElementById('statCart').textContent = total;

  const emptyEl = document.getElementById('cartEmptyState');
  const itemsWrap = document.getElementById('cartItemsWrap');
  const footerEl = document.getElementById('cartFooter');

  if (!cart.length) {
    emptyEl.style.display = 'flex'; itemsWrap.style.display = 'none'; footerEl.style.display = 'none'; return;
  }
  emptyEl.style.display = 'none'; itemsWrap.style.display = 'block'; footerEl.style.display = 'block';

  document.getElementById('cartItems').innerHTML = cart.map(item => {
    const overMax = item.qty >= (item.maxQty || 9999);
    const lowStock = item.maxQty && item.maxQty <= 5;
    return '<div class="cart-item">' +
      '<input type="checkbox" class="cart-item-check" ' + (item.checked ? 'checked' : '') + ' onchange="toggleItemCheck(' + item.id + ',this.checked)">' +
      '<div class="cart-thumb">' + (item.image_path ? '<img src="../' + item.image_path + '">' : '🛍️') + '</div>' +
      '<div class="cart-item-info">' +
        '<div class="cart-item-name">' + esc(item.name) + '</div>' +
        '<div class="cart-item-price">₱' + item.price.toFixed(2) + ' each</div>' +
        (lowStock ? '<div class="cart-item-stock">⚠ ' + item.maxQty + ' in stock</div>' : '') +
      '</div>' +
      '<div class="cart-qty-row">' +
        '<button class="cart-qty-btn" onclick="changeCartQty(' + item.id + ',-1)">−</button>' +
        '<span class="cart-qty-num">' + item.qty + '</span>' +
        '<button class="cart-qty-btn" onclick="changeCartQty(' + item.id + ',1)"' + (overMax ? ' disabled' : '') + '>+</button>' +
      '</div>' +
    '</div>';
  }).join('');

  updateCartSummary();
  syncSelectAll();
}

function updateCartSummary() {
  const selected = cart.filter(i => i.checked);
  const subtotal = selected.reduce((s, i) => s + i.price * i.qty, 0);
  const deliveryStores = new Set(selected.map(i => i.merchant_id)).size;
  const deliveryFee = deliveryStores * 50;
  document.getElementById('cartSubtotal').textContent = '₱' + subtotal.toFixed(2);
  document.getElementById('cartTotal').textContent = '₱' + (subtotal + deliveryFee).toFixed(2);
  document.getElementById('selectedCountLabel').textContent = selected.length + ' item' + (selected.length !== 1 ? 's' : '') + ' selected';
}

function toggleCart() {
  const panel = document.getElementById('cartPanel'), overlay = document.getElementById('cartOverlay');
  const open = panel.classList.toggle('open');
  overlay.style.display = open ? 'block' : 'none';
}

// ── Checkout ───────────────────────────────────
function openCheckout() {
  const selected = cart.filter(i => i.checked);
  if (!selected.length) { showToast('Select at least one item to checkout', 'error'); return; }
  const subtotal = selected.reduce((s, i) => s + i.price * i.qty, 0);
  document.getElementById('checkoutSubtotal').textContent = '₱' + subtotal.toFixed(2);

  document.getElementById('checkoutItemsList').innerHTML = selected.map(i =>
    '<div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--stone-mid);font-size:14px;">' +
    '<span>' + esc(i.name) + ' × ' + i.qty + '</span>' +
    '<span style="font-weight:600;color:var(--green);">₱' + (i.price * i.qty).toFixed(2) + '</span>' +
    '</div>'
  ).join('');

  document.getElementById('checkoutAddress').value = pickedAddress || document.getElementById('pAddress').value || '';
  document.getElementById('checkoutPayMethod').value = 'cod';
  document.getElementById('cashlessFields').style.display = 'none';
  updateCheckoutTotal();

  // Close cart silently
  document.getElementById('cartPanel').classList.remove('open');
  document.getElementById('cartOverlay').style.display = 'none';
  document.getElementById('checkoutModal').classList.add('open');
}

function toggleCashlessFields() {
  const method = document.getElementById('checkoutPayMethod').value;
  document.getElementById('cashlessFields').style.display = method === 'cod' ? 'none' : 'block';
}



function updateCheckoutTotal() {
  const selected = cart.filter(i => i.checked);
  const subtotal = selected.reduce((s, i) => s + i.price * i.qty, 0);
  const deliveryStores = new Set(selected.map(i => i.merchant_id)).size;
  const deliveryFee = deliveryStores * 50;
  document.getElementById('checkoutDeliveryFee').textContent = '₱' + deliveryFee.toFixed(2);
  document.getElementById('checkoutTotal').textContent = '₱' + (subtotal + deliveryFee).toFixed(2);
}

async function placeOrder() {
  const selected = cart.filter(i => i.checked);
  if (!selected.length) { showToast('No items selected', 'error'); return; }
  const address = document.getElementById('checkoutAddress').value.trim();
  if (!address) { showToast('Please enter delivery address', 'error'); return; }

  const payMethod = document.getElementById('checkoutPayMethod').value;
  if (payMethod !== 'cod') {
    const cn = document.getElementById('cardName').value.trim();
    const cnum = document.getElementById('cardNumber').value.replace(/\s/g, '');
    const exp = document.getElementById('cardExpiry').value.trim();
    const cvv = document.getElementById('cardCvv').value.trim();
    if (!cn || cnum.length < 12 || !exp || !cvv) {
      showToast('Please fill in all payment details', 'error'); return;
    }
    if (!/^\d{12,19}$/.test(cnum)) {
      showToast('Card number must contain 12 to 19 digits', 'error'); return;
    }
    if (!/^[0-9]{2}\/[0-9]{2}$/.test(exp) || !isCardExpiryValid(exp)) {
      showToast('Enter a valid card expiry month/year that is not expired', 'error'); return;
    }
    if (!/^[0-9]{3,4}$/.test(cvv)) {
      showToast('CVV must be 3 or 4 digits', 'error'); return;
    }
  }

  const btn = document.getElementById('placeOrderBtn');
  btn.disabled = true; btn.textContent = 'Placing…';

  const body = {
    items: selected.map(i => ({ product_id: i.id, qty: i.qty })),
    address,
    pay_method: payMethod,
    lat: pickedLat || null,
    lng: pickedLng || null,
    tip_amount: 0,
  };

  const d = await api('orders/', { method: 'POST', body: JSON.stringify(body) });
  btn.disabled = false; btn.textContent = 'Place Order';
  if (!d.ok) { showToast(d.error || 'Order failed', 'error'); return; }

  // Remove ordered items from cart
  cart = cart.filter(i => !i.checked);
  localStorage.setItem('mm_cart', JSON.stringify(cart));
  updateCartUI();
  closeModal('checkoutModal');
  document.getElementById('successModal').classList.add('open');
  await loadOrders();
}

async function cancelOrder(id) {
  if (!confirm('Cancel this order?')) return;
  const d = await api('orders/?id=' + id, { method: 'PATCH', body: JSON.stringify({ status: 'Cancelled' }) });
  if (!d.ok) { showToast(d.error, 'error'); return; }
  showToast('Order cancelled', 'success');
  await loadOrders();
}

async function cancelGroupOrders(idsCsv) {
  const ids = idsCsv.split(',').map(i => i.trim()).filter(Boolean);
  if (!ids.length) return;
  if (!confirm('Cancel all pending orders in this checkout?')) return;

  for (const id of ids) {
    const d = await api('orders/?id=' + id, { method: 'PATCH', body: JSON.stringify({ status: 'Cancelled' }) });
    if (!d.ok) {
      showToast(d.error || ('Failed to cancel order #' + id), 'error');
      return;
    }
  }

  showToast('Order(s) cancelled', 'success');
  await loadOrders();
}

async function cancelOrderItem(orderId, itemId) {
  if (!confirm('Cancel this item from your order?')) return;
  const d = await api('orders/?id=' + orderId, {
    method: 'PATCH',
    body: JSON.stringify({ cancel_item_id: itemId })
  });
  if (!d.ok) {
    showToast(d.error || 'Failed to cancel item', 'error');
    return;
  }
  showToast('Item cancelled', 'success');
  await loadOrders();
}

function closeSuccessModal() { document.getElementById('successModal').classList.remove('open'); showTab('orders'); }

// ── Orders render ──────────────────────────────
function renderOrders(orders) {
  if (!orders.length) {
    document.getElementById('ordersContainer').innerHTML =
      '<div class="empty-state" style="padding:60px;"><div class="empty-state-icon">📦</div><h4>No orders yet</h4><p>Your orders will appear here.</p></div>';
    return;
  }

  const sc = {
    'Pending': 'status-Pending',
    'Ready for Delivery': 'status-Ready',
    'Delivering': 'status-Delivering',
    'Delivered': 'status-Delivered',
    'Cancelled': 'status-Cancelled'
  };

  const reasonLabels = {
    fake_address: 'Fake address',
    no_answer: 'No answer',
    refused_delivery: 'Refused delivery',
    fraud: 'Fraud',
    other: 'Other'
  };

  const groups = {};
  orders.forEach(o => {
    const key = [o.ordered_at || '', o.delivery_address || '', o.pay_method || '', o.merchant_id || ''].join('||');
    if (!groups[key]) {
      groups[key] = {
        orders: [],
        items: [],
        subtotal: 0,
        delivery_fee: 0,
        tip_amount: 0,
        grand_total: 0,
        ordered_at: o.ordered_at,
        delivery_address: o.delivery_address || ''
      };
    }
    groups[key].orders.push(o);
    groups[key].items = groups[key].items.concat(o.items || []);
    groups[key].subtotal += parseFloat(o.total || 0);
    groups[key].delivery_fee += parseFloat(o.delivery_fee || 0);
    groups[key].tip_amount += parseFloat(o.tip_amount || 0);
    groups[key].grand_total += (
      parseFloat(o.total || 0) + parseFloat(o.delivery_fee || 0) + parseFloat(o.tip_amount || 0)
    );
  });

  const groupedOrders = Object.values(groups);

  document.getElementById('ordersContainer').innerHTML = groupedOrders.map(group => {
    const firstOrder = group.orders[0];
    const date = group.ordered_at ? new Date(group.ordered_at).toLocaleString('en-PH', {
      year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
    }) : '—';
    const orderLabel = group.orders.length > 1
      ? 'Order #' + String(firstOrder.id).padStart(6, '0') + ' + ' + (group.orders.length - 1) + ' more'
      : 'Order #' + String(firstOrder.id).padStart(6, '0');
    const merchantName = firstOrder.merchant_name || 'Store';

    const statusLabels = group.orders.map(o =>
      '<span class="order-status-badge ' + (sc[o.status] || '') + '">' + esc(o.merchant_name || 'Store') + ': ' + o.status + '</span>'
    ).join(' ');

    const hasDelivered = group.orders.some(o => o.status === 'Delivered' || o.delivered_at);
    const hasArrived = group.orders.some(o => o.arrived_at);
    const arrivedNote = hasDelivered
      ? '<div class="order-arrived-note" style="font-size:13px;margin-top:8px;padding:10px 14px;border:1px solid var(--green);border-radius:10px;background:rgba(220,252,231,.9);color:var(--green);">✅ Delivered successfully!</div>'
      : (hasArrived ? '<div class="order-arrived-note" style="font-size:13px;margin-top:8px;padding:10px 14px;border:1px solid var(--green);border-radius:10px;background:rgba(209,250,229,.4);color:var(--green);">🚴 Rider has arrived for this order.</div>' : '');

    const reportOrder = group.orders.find(o => o.report_id);
    const reportActionButton = reportOrder ?
      '<button class="btn btn-sm btn-secondary" onclick="openReportReplyModal(' + reportOrder.id + ',' + reportOrder.report_id + ')">' +
        (reportOrder.report_customer_reply ? 'Update reply' : 'Reply to report') +
      '</button>' : '';

    const pendingOrderIds = group.orders.filter(o => o.status === 'Pending').map(o => o.id);
    const groupActions = pendingOrderIds.length > 0 ?
      '<button class="btn btn-sm btn-danger" onclick="cancelGroupOrders(\'' + pendingOrderIds.join(',') + '\')">' +
        (pendingOrderIds.length > 1 ? 'Cancel All' : 'Cancel') +
      '</button>' : '';

    return '<div class="order-card">' +
      '<div class="order-header">' +
        '<div class="order-header-left">' +
          '<div class="order-id">' + orderLabel + '</div>' +
          '<div class="order-merchant">' + esc(merchantName) + '</div>' +
          '<div class="order-date">' + date + '</div>' +
        '</div>' +
        '<div class="order-status-group">' + statusLabels + '</div>' +
      '</div>' +
      (group.delivery_address ? '<div class="order-addr-pill">📍 ' + esc(group.delivery_address) + '</div>' : '') +
      arrivedNote +
      '<div style="margin:14px 0;">' +
        (group.items.length ? (() => {
          const orderStatus = {};
          const orderSeen = {};
          group.orders.forEach(o => { orderStatus[o.id] = o; });
          return group.items.map(item => {
            const order = orderStatus[item.order_id] || {};
            const itemSubtotal = parseFloat(item.subtotal || (parseFloat(item.unit_price || 0) * parseInt(item.qty || 1)));
            const canCancelItem = order.status === 'Pending' && item.status !== 'Cancelled';
            const isCancelled = item.status === 'Cancelled';
            const showOrderButton = !orderSeen[item.order_id];
            if (showOrderButton) orderSeen[item.order_id] = true;
            const viewRiderButton = showOrderButton && order.rider_id
              ? '<button class="btn btn-sm btn-secondary" onclick="openRiderModal(' + item.order_id + ')">View Rider</button>'
              : '';
            const reportLabel = order.report_id ?
              '<div class="order-item-note">' +
                '<strong>🚩 Rider report:</strong> This order was cancelled by the rider' +
                (order.report_reason ? ' for ' + esc(reasonLabels[order.report_reason] || order.report_reason) : '') +
                '. Admin will review the report.' +
              '</div>' : '';
            const reportReply = order.report_id && order.report_customer_reply ?
              '<div class="order-item-reply">' +
                '<strong>Your reply:</strong> ' + esc(order.report_customer_reply) +
              '</div>' : '';
            return '<div class="order-item-row" style="' + (isCancelled ? 'opacity:0.65;' : '') + '">' +
              '<div class="order-item-main">' +
                '<div class="order-item-title">' + esc(item.product_name || 'Item') + ' × ' + (item.qty || 1) + '</div>' +
                '<div class="order-item-meta">' +
                  (isCancelled ? '<span class="order-item-status" style="font-size:12px;padding:4px 8px;background:#fee2e2;color:#991b1b;border-radius:999px;">CANCELLED</span>' : '') +
                  '<span class="order-item-price">₱' + itemSubtotal.toFixed(2) + '</span>' +
                  '<div class="order-item-actions">' + viewRiderButton + '</div>' +
                '</div>' +
              '</div>' +
              reportLabel +
              reportReply +
            '</div>';
          }).join('');
        })() : '<div style="color:var(--ink-soft);font-style:italic;padding:8px 0;font-size:13px;">Loading items…</div>') +
      '</div>' +
      '<div class="order-card-footer">' +
        '<div class="order-summary-row"><span>Subtotal</span><span>₱' + group.subtotal.toFixed(2) + '</span></div>' +
        '<div class="order-summary-row"><span>Delivery fee</span><span>₱' + group.delivery_fee.toFixed(2) + '</span></div>' +
        (group.tip_amount > 0 ? '<div class="order-summary-row"><span>Tip to rider</span><span>₱' + group.tip_amount.toFixed(2) + '</span></div>' : '') +
        '<div class="order-summary-total"><span>Total</span><span>₱' + group.grand_total.toFixed(2) + '</span></div>' +
        '<div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;align-items:center;margin-top:12px;">' +
          reportActionButton +
          groupActions +
        '</div>' +
      '</div>' +
    '</div>';
        '<div style="display:flex;justify-content:space-between;font-size:13px;color:var(--ink-soft);margin-bottom:4px;">' +
          '<span>Subtotal</span><span>₱' + group.subtotal.toFixed(2) + '</span>' +
        '</div>' +
        '<div style="display:flex;justify-content:space-between;font-size:13px;color:var(--ink-soft);margin-bottom:4px;">' +
          '<span>Delivery fee</span><span>₱' + group.delivery_fee.toFixed(2) + '</span>' +
        '</div>' +
        (group.tip_amount > 0 ? '<div style="display:flex;justify-content:space-between;font-size:13px;color:var(--green);margin-bottom:4px;">' +
          '<span>Tip to rider</span><span style="font-weight:600;">₱' + group.tip_amount.toFixed(2) + '</span>' +
        '</div>' : '') +
        '<div style="display:flex;align-items:center;justify-content:space-between;margin-top:8px;padding-top:8px;border-top:1px solid var(--stone-dark);">' +
          '<div style="font-family:\'Fraunces\',serif;font-size:22px;font-weight:400;color:var(--green);">Total: ₱' + group.grand_total.toFixed(2) + '</div>' +
          '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">' +
            reportActionButton +
            groupActions +
          '</div>' +
        '</div>' +
      '</div>' +
    '</div>';
  }).join('');
}

// ── Tip modal ──────────────────────────────────
function openTipModal(orderId) {
  document.getElementById('tipOrderId').value = orderId;
  document.getElementById('customTipModal').value = '';
  selectedTipModalAmount = 0;
  document.querySelectorAll('#tipModal .tip-chip').forEach(b => b.classList.remove('active'));
  document.getElementById('tipModal').classList.add('open');
}

function openRiderModal(orderId) {
  const order = customerOrders.find(o => o.id === orderId);
  if (!order) {
    document.getElementById('riderModalBody').innerHTML = '<p style="color:var(--red);">Rider information is unavailable.</p>';
    document.getElementById('riderModal').classList.add('open');
    return;
  }

  const riderName = order.rider_name || ('Rider #' + order.rider_id);
  const phone = order.rider_phone ? esc(order.rider_phone) : 'Not available';
  const vehicle = order.rider_vehicle_type ? esc(order.rider_vehicle_type) : 'Not available';
  const note = order.status === 'Delivered' && order.tip_amount === 0
    ? '<p style="margin:0;font-size:13px;color:var(--ink-soft);">You can tip this rider once the order is delivered.</p>'
    : '';

  document.getElementById('tipOrderId').value = orderId;
  document.getElementById('riderModalBody').innerHTML =
    '<div style="display:grid;gap:12px;line-height:1.5;">' +
      '<div><strong>Name</strong><br>' + esc(riderName) + '</div>' +
      '<div><strong>Phone</strong><br>' + phone + '</div>' +
      '<div><strong>Vehicle</strong><br>' + vehicle + '</div>' +
      note +
    '</div>';
  document.getElementById('riderModal').classList.add('open');
  // Show Tip button only when order is delivered and not already tipped
  const tipBtn = document.getElementById('riderModalTipBtn');
  if (tipBtn) {
    const isDelivered = order.status === 'Delivered';
    const alreadyTipped = parseFloat(order.tip_amount || 0) > 0;
    tipBtn.style.display = (isDelivered && !alreadyTipped) ? 'inline-flex' : 'none';
  }
}

function openReportReplyModal(orderId, reportId) {
  const order = customerOrders.find(o => o.id === orderId);
  const reportReplyField = document.getElementById('reportReplyText');
  const reportReplyLabel = document.getElementById('reportReplyOrderLabel');
  const reportReplyId = document.getElementById('reportReplyId');
  const reportReplyOrderId = document.getElementById('reportReplyOrderId');

  reportReplyId.value = reportId;
  reportReplyOrderId.value = orderId;
  reportReplyLabel.textContent = order
    ? 'Reply to the rider report for order #' + String(order.id).padStart(6, '0')
    : 'Reply to the rider report';
  reportReplyField.value = order && order.report_customer_reply ? order.report_customer_reply : '';
  document.getElementById('reportReplyModal').classList.add('open');
}

async function submitReportReply() {
  const reportId = document.getElementById('reportReplyId').value;
  const reply = document.getElementById('reportReplyText').value.trim();
  if (!reply) {
    showToast('Please enter a reply', 'error');
    return;
  }

  const d = await api('reports/reply.php', {
    method: 'POST',
    body: JSON.stringify({ report_id: reportId, reply })
  });

  if (!d.ok) {
    showToast(d.error || 'Failed to send reply', 'error');
    return;
  }

  showToast('Your reply was sent to admin', 'success');
  closeModal('reportReplyModal');
  await loadOrders();
}

function selectTipModal(btn, amount) {
  selectedTipModalAmount = amount;
  document.querySelectorAll('#tipModal .tip-chip').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
}

async function submitTip() {
  const orderId = document.getElementById('tipOrderId').value;
  const tipAmt = selectedTipModalAmount || parseFloat(document.getElementById('customTipModal').value) || 0;
  if (tipAmt <= 0) { showToast('Please enter a tip amount', 'error'); return; }
  
  // Only send tip_amount, don't try to change status
  const d = await api('orders/?id=' + orderId, { 
    method: 'PATCH', 
    body: JSON.stringify({ tip_amount: tipAmt }) 
  });
  
  if (!d.ok) { 
    showToast(d.error || 'Failed to send tip', 'error'); 
    return; 
  }
  
  showToast('₱' + tipAmt.toFixed(2) + ' tip sent! 🎁', 'success');
  closeModal('tipModal');
  await loadOrders();
}

// ── Store view ─────────────────────────────────
async function openStoreView(id) {
  const m = allMerchants.find(x => x.id == id); if (!m) return;
  showTab('merchants');
  document.getElementById('merchantsGrid').style.display = 'none';
  document.getElementById('storeProductsSection').style.display = 'block';
  document.getElementById('storeProductsTitle').textContent = '🏪 ' + m.name;
  const d = await api('products/?merchant_id=' + id);
  const storeProds = d.data || [];
  storeProds.forEach(p => { if (!allProducts.find(x => x.id == p.id)) allProducts.push(p); });
  renderProductGrid(storeProds, 'storeProductsGrid');
}

function closeStoreView() {
  document.getElementById('merchantsGrid').style.display = 'grid';
  document.getElementById('storeProductsSection').style.display = 'none';
}

// ── Search ─────────────────────────────────────
let globalSearchQuery = ''; // Track search state globally

function handleSearch() {
  const q = document.getElementById('searchInput').value.toLowerCase().trim();
  globalSearchQuery = q;
  
  if (!q) {
    // Reset ALL sections to normal state
    renderHomeProducts();
    renderProductGrid(allProducts, 'productGrid');
    renderMerchants();
    return;
  }
  
  // Filter products
  const filteredProds = allProducts.filter(p =>
    (p.name || '').toLowerCase().includes(q) || 
    (p.description || '').toLowerCase().includes(q)
  );
  
  // Filter merchants  
  const filteredMerch = allMerchants.filter(m =>
    (m.name || '').toLowerCase().includes(q) || 
    (m.address || '').toLowerCase().includes(q)
  );

  // UPDATE ALL GRIDS regardless of current tab
  renderProductGrid(filteredProds, 'productGrid');
  renderProductGrid(filteredProds.slice(0, 8), 'homeProdGrid');
  
  // Update merchants grid
  if (filteredMerch.length) {
    document.getElementById('merchantsGrid').innerHTML = filteredMerch.map(m => {
      const imgHtml = m.image_path ? '<img src="../' + m.image_path + '" alt="' + esc(m.name) + '">' : '🏪';
      return '<div class="merchant-card" onclick="openStoreView(' + m.id + ')">' +
        '<div class="merchant-avatar">' + imgHtml + '</div>' +
        '<div class="merchant-name">' + esc(m.name) + '</div>' +
        '<div class="merchant-addr">📍 ' + esc(m.address || '') + '</div>' +
        '</div>';
    }).join('');
  } else {
    document.getElementById('merchantsGrid').innerHTML = '<div style="color:var(--ink-soft);font-size:14px;grid-column:1/-1;">No stores match your search.</div>';
  }
  
  // Update home stores row too
  document.getElementById('homeStoresRow').innerHTML = filteredMerch.slice(0, 4).map(m => {
    const imgHtml = m.image_path ? '<img src="../' + m.image_path + '" alt="' + esc(m.name) + '">' : '🏪';
    return '<div class="merchant-card" onclick="openStoreView(' + m.id + ')">' +
      '<div class="merchant-avatar">' + imgHtml + '</div>' +
      '<div class="merchant-name">' + esc(m.name) + '</div>' +
      '<div class="merchant-addr">📍 ' + esc(m.address || '') + '</div>' +
      '</div>';
  }).join('') || '<div style="grid-column:1/-1;padding:24px;color:var(--ink-soft);">No stores match search</div>';
}

// ── Category filter ────────────────────────────
function filterAndGo(cat) { showTab('products'); document.getElementById('catFilter').value = cat; filterByCategory(cat); }

async function filterByCategory(cat) {
  document.getElementById('productTabTitle').textContent = cat === 'all' ? 'All Products' : cat.charAt(0).toUpperCase() + cat.slice(1);
  if (cat === 'all') { renderProductGrid(allProducts, 'productGrid'); return; }
  // Filter client-side: support products with multiple categories stored as JSON array or comma-separated values
  const filtered = allProducts.filter(p => {
    const catSource = p.category_ids || p.category_id || '';
    let cats = [];
    if (Array.isArray(catSource)) {
      cats = catSource;
    } else if (typeof catSource === 'string' && catSource.trim().startsWith('[')) {
      try { cats = JSON.parse(catSource); } catch (e) { cats = catSource.split(/\s*,\s*/).filter(Boolean); }
    } else if (typeof catSource === 'string') {
      cats = catSource.split(/\s*,\s*/).filter(Boolean);
    }
    const lowerCats = cats.map(x => String(x).toLowerCase());
    return lowerCats.includes(cat.toLowerCase());
  });
  renderProductGrid(filtered, 'productGrid');
}

// ── Profile ────────────────────────────────────
function validatePhone() {
  const v = document.getElementById('pPhone').value.trim();
  const hint = document.getElementById('phoneHintProfile');
  if (!v) { hint.textContent = '10 digits starting with 9'; hint.style.color = 'var(--ink-soft)'; return; }
  if (v.length === 10 && v.startsWith('9')) { hint.textContent = '✓ Valid Philippine number'; hint.style.color = 'var(--green)'; }
  else { hint.textContent = 'Must be 10 digits starting with 9'; hint.style.color = 'var(--red)'; }
}

async function saveProfile() {
  const phone = document.getElementById('pPhone').value.trim();
  if (phone && (phone.length !== 10 || !phone.startsWith('9'))) { showToast('Phone must be 10 digits starting with 9', 'error'); return; }
  const body = {
    fname: document.getElementById('pFName').value.trim(),
    lname: document.getElementById('pLName').value.trim(),
    phone: phone ? '0' + phone : '',
    address: document.getElementById('pAddress').value.trim(),
  };
  const d = await api('customers/', { method: 'POST', body: JSON.stringify(body) });
  if (!d.ok) { showToast(d.error || 'Failed', 'error'); return; }
  showToast('Profile updated ✓', 'success');
  updateCustomerProfilePreview();
}

function updateCustomerProfilePreview() {
  const firstName = document.getElementById('pFName')?.value.trim() || '';
  const lastName = document.getElementById('pLName')?.value.trim() || '';
  const name = (firstName + ' ' + lastName).trim() || 'Customer';
  document.getElementById('profilePreviewName').textContent = name;
  document.getElementById('profilePreviewEmail').textContent = document.getElementById('pEmail')?.value || '—';
  document.getElementById('profilePreviewPhone').textContent = document.getElementById('pPhone')?.value ? '+63' + document.getElementById('pPhone').value : '—';
  document.getElementById('profilePreviewAddress').textContent = document.getElementById('pAddress')?.value || '—';
  document.getElementById('profileAvatar').textContent = name[0]?.toUpperCase() || 'C';
}

function checkPwStrength(pw) {
  const wrap = document.getElementById('pwStrengthWrap'), bar = document.getElementById('pwBar'), lbl = document.getElementById('pwLabel');
  if (!pw) { wrap.classList.remove('show'); return; } wrap.classList.add('show');
  let s = 0; if (pw.length >= 8) s++; if (pw.length >= 12) s++; if (/[A-Z]/.test(pw)) s++; if (/[0-9]/.test(pw)) s++; if (/[^A-Za-z0-9]/.test(pw)) s++;
  const lvls = [{ p: '20%', c: '#ef4444', l: 'Too weak' }, { p: '40%', c: '#f97316', l: 'Weak' }, { p: '60%', c: '#eab308', l: 'Fair' }, { p: '80%', c: '#22c55e', l: 'Good' }, { p: '100%', c: '#16a34a', l: 'Strong' }];
  const lvl = lvls[Math.min(s, 4)]; bar.style.width = lvl.p; bar.style.background = lvl.c; lbl.textContent = lvl.l; lbl.style.color = lvl.c;
}

async function changePassword() {
  const old = document.getElementById('oldPw').value;
  const pw = document.getElementById('newPw').value;
  const conf = document.getElementById('confirmPw').value;
  if (!old || !pw) { showToast('Fill in all fields', 'error'); return; }
  if (pw.length < 8) { showToast('Password must be at least 8 characters', 'error'); return; }
  if (pw !== conf) { showToast('Passwords do not match', 'error'); return; }
  const d = await api('auth/?action=change_password', { method: 'POST', body: JSON.stringify({ old_password: old, new_password: pw, confirm: conf }) });
  if (!d.ok) { showToast(d.error || 'Failed', 'error'); return; }
  showToast('Password changed ✓', 'success');
  ['oldPw', 'newPw', 'confirmPw'].forEach(id => document.getElementById(id).value = '');
}

// ── Card formatting ────────────────────────────
function formatCard(el) { let v = el.value.replace(/\D/g, '').slice(0, 16); el.value = v.replace(/(.{4})/g, '$1 ').trim(); }
function formatExpiry(el) { let v = el.value.replace(/\D/g, '').slice(0, 4); if (v.length > 2) v = v.slice(0, 2) + '/' + v.slice(2); el.value = v; }
function isCardExpiryValid(value) {
  const [mm, yy] = value.split('/').map(Number);
  if (!mm || !yy || mm < 1 || mm > 12) return false;
  const now = new Date();
  const currentYear = now.getFullYear() % 100;
  const currentMonth = now.getMonth() + 1;
  if (yy < currentYear) return false;
  if (yy === currentYear && mm < currentMonth) return false;
  return true;
}

// ── Location ───────────────────────────────────
function restoreLocation() {
  const saved = localStorage.getItem('mm_delivery_address'); if (!saved) return;
  pickedAddress = saved;
  pickedLat = parseFloat(localStorage.getItem('mm_delivery_lat') || 10.3157);
  pickedLng = parseFloat(localStorage.getItem('mm_delivery_lng') || 123.8854);
  const label = saved.length > 26 ? saved.slice(0, 26) + '…' : saved;
  document.getElementById('deliveryAddressBar').textContent = saved;
  document.getElementById('topbarLocation').textContent = label;
  const pAddr = document.getElementById('pAddress');
  if (pAddr && !pAddr.value) pAddr.value = saved;
}

function openLocationModal() {
  document.getElementById('locationModal').classList.add('open');
  setTimeout(() => {
    if (!locationMap) {
      locationMap = L.map('locationPickerMap').setView([pickedLat, pickedLng], 15);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap', maxZoom: 19 }).addTo(locationMap);
      const pin = L.divIcon({ className: '', html: '<div style="font-size:36px;filter:drop-shadow(0 3px 8px rgba(0,0,0,.4));">📍</div>', iconAnchor: [18, 36], iconSize: [36, 36] });
      locationMarker = L.marker([pickedLat, pickedLng], { icon: pin, draggable: true }).addTo(locationMap);
      locationMap.on('click', e => { locationMarker.setLatLng(e.latlng); pickedLat = e.latlng.lat; pickedLng = e.latlng.lng; reverseGeocode(pickedLat, pickedLng); });
      locationMarker.on('dragend', e => { const ll = e.target.getLatLng(); pickedLat = ll.lat; pickedLng = ll.lng; reverseGeocode(pickedLat, pickedLng); });
    } else { locationMap.invalidateSize(); locationMarker.setLatLng([pickedLat, pickedLng]); locationMap.setView([pickedLat, pickedLng], 15); }
    if (pickedAddress) document.getElementById('locationSearchInput').value = pickedAddress;
  }, 250);
}

async function reverseGeocode(lat, lng) {
  try {
    const r = await fetch('https://nominatim.openstreetmap.org/reverse?lat=' + lat + '&lon=' + lng + '&format=json');
    const d = await r.json(); pickedAddress = d.display_name || lat.toFixed(5) + ',' + lng.toFixed(5);
  } catch { pickedAddress = lat.toFixed(5) + ',' + lng.toFixed(5); }
  document.getElementById('locationSearchInput').value = pickedAddress;
}

async function searchAddress() {
  let q = document.getElementById('locationSearchInput').value.trim(); if (!q) return;
  q = q.replace(/\bcor\.?\b/gi, 'corner').replace(/\s+/g, ' ').trim();
  async function attempt(qstr) {
    const url = 'https://nominatim.openstreetmap.org/search?q=' + encodeURIComponent(qstr) + '&format=json&limit=1&countrycodes=ph&accept-language=en';
    try { const r = await fetch(url, { headers: { 'User-Agent': 'MetroMart/1.0 (local)' } }); const d = await r.json(); if (d && d.length) return d[0]; } catch(_) {}
    return null;
  }

  try {
    let res = await attempt(q);
    if (!res) res = await attempt(q + ', Cebu City, Philippines');
    if (!res && /,/.test(q)) res = await attempt(q.replace(/,/g, ' '));
    if (!res) { showToast('Address not found', 'error'); return; }
    pickedLat = parseFloat(res.lat); pickedLng = parseFloat(res.lon); pickedAddress = res.display_name;
    if (locationMap) { locationMap.setView([pickedLat, pickedLng], 15); locationMarker.setLatLng([pickedLat, pickedLng]); }
    document.getElementById('locationSearchInput').value = pickedAddress;
  } catch { showToast('Search failed', 'error'); }
}

function useCurrentLocation() {
  if (!navigator.geolocation) { showToast('Not supported', 'error'); return; }
  navigator.geolocation.getCurrentPosition(
    pos => { pickedLat = pos.coords.latitude; pickedLng = pos.coords.longitude; locationMap.setView([pickedLat, pickedLng], 16); locationMarker.setLatLng([pickedLat, pickedLng]); reverseGeocode(pickedLat, pickedLng); },
    () => showToast('Could not get location', 'error')
  );
}

function confirmLocation() {
  localStorage.setItem('mm_delivery_address', pickedAddress);
  localStorage.setItem('mm_delivery_lat', pickedLat);
  localStorage.setItem('mm_delivery_lng', pickedLng);
  const label = pickedAddress.length > 26 ? pickedAddress.slice(0, 26) + '…' : pickedAddress;
  document.getElementById('deliveryAddressBar').textContent = pickedAddress;
  document.getElementById('topbarLocation').textContent = label;
  // Reflect in profile address field
  const pAddr = document.getElementById('pAddress');
  if (pAddr) pAddr.value = pickedAddress;
  // Reflect in checkout if open
  const ca = document.getElementById('checkoutAddress');
  if (ca) ca.value = pickedAddress;
  closeModal('locationModal');
  showToast('Location saved ✓', 'success');
}

// Update showTab to restore search results
async function showTab(tab) {
  ['home', 'merchants', 'products', 'orders', 'profile'].forEach(t => {
    const el = document.getElementById('tab-' + t), nav = document.getElementById('nav-' + t);
    if (el) el.style.display = t === tab ? 'block' : 'none';
    if (nav) nav.classList.toggle('active', t === tab);
  });
  
  if (tab === 'orders') {
    await loadOrders();
    return;
  }

  // Restore search results if searching for non-order tabs
  if (globalSearchQuery) {
    handleSearch(); // Re-apply search
  }
}

// Update renderMerchants to check search state
function renderMerchants() {
  if (globalSearchQuery) return; // Don't override search results
  
  const makeCard = m =>
    '<div class="merchant-card" onclick="openStoreView(' + m.id + ')">' +
    '<div class="merchant-avatar">' + (m.image_path ? '<img src="../' + m.image_path + '" alt="' + esc(m.name) + '">' : '🏪') + '</div>' +
    '<div class="merchant-name">' + esc(m.name) + '</div>' +
    '<div class="merchant-addr">📍 ' + esc(m.address || '') + (m._dist ? ' • ' + Number(m._dist).toFixed(1) + ' km' : '') + '</div>' +
    '</div>';

  let list = allMerchants.slice();
  if (pickedLat && pickedLng) {
    list = list.filter(m => m.latitude && m.longitude).map(m => { m._dist = distanceKm(pickedLat, pickedLng, parseFloat(m.latitude), parseFloat(m.longitude)); return m; }).sort((a,b)=>a._dist-b._dist);
    if (!list.length) list = allMerchants.slice();
  }

  const html = list.length
    ? list.map(makeCard).join('')
    : '<div class="empty-state" style="grid-column:1/-1"><div class="empty-state-icon">🏪</div><p>No stores yet.</p></div>';

  document.getElementById('merchantsGrid').innerHTML = html;
  document.getElementById('homeStoresRow').innerHTML = list.slice(0, 4).map(makeCard).join('');
}

function toggleProfileMenu() { profileMenuOpen = !profileMenuOpen; document.getElementById('profileMenu').classList.toggle('open', profileMenuOpen); }
document.addEventListener('click', e => { if (!e.target.closest('.profile-wrap')) { profileMenuOpen = false; document.getElementById('profileMenu').classList.remove('open'); } });
async function doLogout() { await fetch(API + 'auth/?action=logout', { method: 'POST' }); localStorage.removeItem('mm_cart'); window.location.href = '../login.html'; }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(el => el.addEventListener('click', e => { if (e.target === el) el.classList.remove('open'); }));
function showToast(msg, type = '') { const t = document.getElementById('toast'); t.textContent = msg; t.className = 'show ' + type; setTimeout(() => t.className = '', 3200); }
function esc(s) { return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

// Dev helper: inject test orders (two merchants, same ordered_at) when ?dev=1
function injectTestOrders() {
  const now = new Date().toISOString();
  const testOrders = [
    {
      id: 1001,
      merchant_id: 10,
      merchant_name: 'Bakery One',
      ordered_at: now,
      delivery_address: '123 Main St',
      pay_method: 'cod',
      total: 100.00,
      delivery_fee: 20.00,
      tip_amount: 0,
      grand_total: 120.00,
      status: 'Pending',
      items: [
        { id: 5001, order_id: 1001, product_name: 'Bread', qty: 1, unit_price: 100, subtotal: 100, status: 'Active' }
      ]
    },
    {
      id: 1002,
      merchant_id: 11,
      merchant_name: 'Grocery Two',
      ordered_at: now,
      delivery_address: '123 Main St',
      pay_method: 'cod',
      total: 50.00,
      delivery_fee: 15.00,
      tip_amount: 0,
      grand_total: 65.00,
      status: 'Pending',
      items: [
        { id: 5002, order_id: 1002, product_name: 'Milk', qty: 2, unit_price: 25, subtotal: 50, status: 'Active' }
      ]
    }
  ];
  renderOrders(testOrders);
}

if (location.search.includes('dev=1')) {
  const btn = document.createElement('button');
  btn.textContent = 'Inject Test Orders';
  btn.className = 'btn';
  btn.style.position = 'fixed';
  btn.style.right = '16px';
  btn.style.bottom = '16px';
  btn.style.zIndex = 9999;
  btn.onclick = injectTestOrders;
  document.body.appendChild(btn);
}
