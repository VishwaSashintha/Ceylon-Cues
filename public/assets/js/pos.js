/**
 * Ceylon Cues — Physical Retail Shop POS Terminal Controller
 * Manages cashier session lifecycle, product search, cart, payment, and receipt printing.
 */

(function () {
  'use strict';

  /* ======== State ======== */
  let posSession = null;       // Active POS session object from backend
  let posCart = [];            // Array of { product, quantity, unitPrice, lineTotal }
  let currentPayMethod = 'cash';
  let allProducts = [];        // All products loaded for quick-add grid
  let allCategories = [];      // All categories for category pills
  let activeCategory = '';     // Currently selected category slug/id
  let sessionCashSales = 0;    // Running total of cash sales this session

  /* ======== Init ======== */
  document.addEventListener('DOMContentLoaded', async () => {
    startClock();
    await initPosAuth();
  });

  /* ---- Clock ---- */
  function startClock() {
    function tick() {
      const now = new Date();
      const el = document.getElementById('pos-clock');
      if (el) {
        el.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
      }
    }
    tick();
    setInterval(tick, 1000);
  }

  /* ---- Authentication Handling ---- */
  async function initPosAuth() {
    const user = CeylonAPI.getUser();
    if (!user) {
      // Show login form
      document.getElementById('pos-login-form').style.display = 'block';
      document.getElementById('pos-open-session-panel').style.display = 'none';
      bindLoginForm();
    } else if (!CeylonAPI.isStaff()) {
      showError('Access denied. Only staff accounts can access the POS terminal.');
      return;
    } else {
      const saved = localStorage.getItem('ceylon_cues_pos_session');
      if (saved) {
        try {
          posSession = JSON.parse(saved);
          await activatePosTerminal();
          return;
        } catch (_) {
          localStorage.removeItem('ceylon_cues_pos_session');
        }
      }
      showOpenSessionPanel(user);
    }
  }

  function bindLoginForm() {
    const form = document.getElementById('pos-login-form');
    form.onsubmit = async (e) => {
      e.preventDefault();
      try {
        const email = document.getElementById('pos-login-email').value;
        const pass = document.getElementById('pos-login-password').value;
        const result = await CeylonAPI.login(email, pass);
        if (!CeylonAPI.isStaff()) {
          CeylonAPI.logout();
          showError('Access denied: customer accounts cannot access the POS.');
          return;
        }
        form.style.display = 'none';
        const saved = localStorage.getItem('ceylon_cues_pos_session');
        if (saved) {
          try {
            posSession = JSON.parse(saved);
            await activatePosTerminal();
            return;
          } catch (_) {
            localStorage.removeItem('ceylon_cues_pos_session');
          }
        }
        showOpenSessionPanel(result.user);
      } catch (err) {
        CeylonAPI.showToast(err.message || 'Login failed', 'error');
      }
    };
  }

  function showOpenSessionPanel(user) {
    document.getElementById('pos-login-form').style.display = 'none';
    document.getElementById('pos-open-session-panel').style.display = 'block';
    const greeting = document.getElementById('pos-cashier-greeting');
    if (greeting) {
      const hour = new Date().getHours();
      const timeGreet = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
      greeting.innerHTML = `${timeGreet}, <strong style="color:#fff;">${user.first_name || user.email}</strong>!<br>Open your register to begin accepting sales.`;
    }

    const form = document.getElementById('pos-open-session-form');
    form.onsubmit = handleOpenSession;
  }

  /* ---- Activate POS Terminal UI ---- */
  async function activatePosTerminal() {
    document.getElementById('session-overlay').style.display = 'none';
    document.getElementById('pos-terminal').style.display = 'flex';

    const user = CeylonAPI.getUser();
    document.getElementById('pos-session-info').textContent =
      `Cashier: ${user?.first_name || 'Staff'} | Session #${posSession?.id || '1'} | Physical Shop`;

    document.getElementById('close-opening-float').textContent = CeylonAPI.formatLKR(posSession?.opening_balance || 0);
    updateCloseExpected();

    await Promise.all([loadCategories(), loadProducts()]);

    const barcodeInput = document.getElementById('pos-barcode-input');
    if (barcodeInput) {
      barcodeInput.focus();
      barcodeInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          posSearchProducts();
        }
      });
    }
  }

  /* ---- Open Session ---- */
  async function handleOpenSession(e) {
    e.preventDefault();
    const form = e.target;
    const submitBtn = form.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Opening register...';

    try {
      const res = await CeylonAPI.post('/pos/sessions/open', {
        location_id: parseInt(form.location_id.value, 10),
        opening_balance: parseFloat(form.opening_balance.value),
        notes: form.notes.value,
      });

      posSession = res.data;
      localStorage.setItem('ceylon_cues_pos_session', JSON.stringify(posSession));
      sessionCashSales = 0;

      await activatePosTerminal();
      CeylonAPI.showToast('POS Register opened! Ready to sell.', 'success');
    } catch (err) {
      if (err.message && err.message.includes('already has an open POS session')) {
        CeylonAPI.showToast('Existing open session detected. Resuming register terminal...', 'info');
        posSession = { id: 1, opening_balance: parseFloat(form.opening_balance.value || 0) };
        localStorage.setItem('ceylon_cues_pos_session', JSON.stringify(posSession));
        await activatePosTerminal();
      } else {
        CeylonAPI.showToast(err.message || 'Failed to open register session', 'error');
      }
    } finally {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Open Register & Start Selling';
    }
  }

  /* ---- Load Categories ---- */
  async function loadCategories() {
    try {
      const res = await CeylonAPI.get('/categories');
      allCategories = res.data || [];

      const container = document.getElementById('pos-category-pills');
      let html = '<button class="pos-cat-pill active" data-cat="" onclick="posFilterCategory(this)">All Items</button>';
      allCategories.forEach((cat) => {
        html += `<button class="pos-cat-pill" data-cat="${cat.id}" onclick="posFilterCategory(this)">${cat.name}</button>`;
      });
      container.innerHTML = html;
    } catch (e) {
      console.error('Failed loading categories', e);
    }
  }

  /* ---- Load Products ---- */
  async function loadProducts(search = '') {
    const grid = document.getElementById('pos-products-grid');
    grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:2rem; color:#4d6b5e;">Loading...</div>';

    try {
      let endpoint = '/products?per_page=100';
      if (search) endpoint += `&search=${encodeURIComponent(search)}`;

      // Fetch products + shop-specific stock balances in parallel for accuracy
      const [res, stockRes] = await Promise.all([
        CeylonAPI.get(endpoint),
        CeylonAPI.get('/inventory/stock?location_id=2').catch(() => ({ data: [] })),
      ]);

      allProducts = res.data?.items || [];

      // Build a map of product_id → quantity_available from physical shop (location 2)
      const shopStockMap = {};
      const stockItems = stockRes.data || [];
      stockItems.forEach((s) => {
        shopStockMap[s.product_id] = parseInt(s.quantity_available ?? s.quantity_on_hand ?? 0, 10);
      });

      // Merge accurate shop stock into each product
      allProducts = allProducts.map((p) => ({
        ...p,
        shop_stock: shopStockMap[p.id] !== undefined ? shopStockMap[p.id] : parseInt(p.total_physical_stock ?? 0, 10),
      }));

      renderProductGrid();
    } catch (e) {
      grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:2rem; color:#9b4b4b;">Failed to load products.</div>';
    }
  }

  /* ---- Render Product Grid ---- */
  function renderProductGrid(search = '') {
    const grid = document.getElementById('pos-products-grid');
    let filtered = allProducts.filter((p) => {
      const catMatch = !activeCategory || String(p.category_id) === String(activeCategory);
      const searchMatch = !search || p.name.toLowerCase().includes(search.toLowerCase()) ||
        (p.sku || '').toLowerCase().includes(search.toLowerCase()) ||
        (p.barcode || '').includes(search);
      return catMatch && searchMatch;
    });

    if (filtered.length === 0) {
      grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:3rem; color:#4d6b5e;">No products found.</div>';
      return;
    }

    // Shop stock: use total_physical_stock as approximation (backend returns combined stock)
    // For POS, we care about shop (location_id=2) stock — indicated by the product data
    grid.innerHTML = filtered.map((p) => {
      const shopStock = parseInt(p.shop_stock ?? p.total_physical_stock ?? 0, 10);
      const outOfStock = shopStock <= 0;
      return `
        <div class="pos-product-card ${outOfStock ? 'opacity-50' : ''}"
             onclick="${outOfStock ? '' : `addToCart(${p.id})`}"
             style="${outOfStock ? 'opacity:.45; cursor:not-allowed;' : ''}">
          <div class="pos-card-sku">${p.sku}</div>
          <div class="pos-card-name">${p.name}</div>
          <div class="pos-card-stock">${outOfStock ? '⚠ Out of Shop Stock' : `Shop: ${shopStock} available`}</div>
          <div class="pos-card-price">${CeylonAPI.formatLKR(p.retail_price)}</div>
        </div>
      `;
    }).join('');
  }

  /* ---- Category Filter ---- */
  window.posFilterCategory = function (btn) {
    activeCategory = btn.dataset.cat || '';
    document.querySelectorAll('.pos-cat-pill').forEach((b) => b.classList.remove('active'));
    btn.classList.add('active');
    const search = document.getElementById('pos-barcode-input').value.trim();
    renderProductGrid(search);
  };

  /* ---- Barcode / Product Search ---- */
  window.posSearchProducts = function () {
    const val = document.getElementById('pos-barcode-input').value.trim();
    if (!val) {
      renderProductGrid();
      return;
    }

    // Try exact barcode match first
    const exactBarcode = allProducts.find((p) => p.barcode === val);
    if (exactBarcode) {
      addToCart(exactBarcode.id);
      document.getElementById('pos-barcode-input').value = '';
      return;
    }

    // Exact SKU match
    const exactSku = allProducts.find((p) => (p.sku || '').toLowerCase() === val.toLowerCase());
    if (exactSku) {
      addToCart(exactSku.id);
      document.getElementById('pos-barcode-input').value = '';
      return;
    }

    // Partial search
    renderProductGrid(val);
  };

  /* ---- Add to Cart ---- */
  window.addToCart = function (productId) {
    const product = allProducts.find((p) => p.id === productId);
    if (!product) return;

    const shopStock = parseInt(product.shop_stock ?? product.total_physical_stock ?? 0, 10);
    const existingItem = posCart.find((item) => item.product.id === productId);
    const currentQty = existingItem ? existingItem.quantity : 0;

    if (currentQty >= shopStock) {
      CeylonAPI.showToast(`Insufficient shop stock: only ${shopStock} available`, 'warning');
      return;
    }

    if (existingItem) {
      existingItem.quantity += 1;
      existingItem.lineTotal = existingItem.quantity * existingItem.unitPrice;
    } else {
      posCart.push({
        product,
        quantity: 1,
        unitPrice: parseFloat(product.retail_price),
        lineTotal: parseFloat(product.retail_price),
      });
    }

    renderTicket();
    CeylonAPI.showToast(`${product.name} added`, 'success');
    document.getElementById('pos-barcode-input').focus();
  };

  /* ---- Update Quantity in Cart ---- */
  window.updateCartQty = function (productId, newQty) {
    const item = posCart.find((it) => it.product.id === productId);
    if (!item) return;

    const shopStock = parseInt(item.product.shop_stock ?? item.product.total_physical_stock ?? 0, 10);
    newQty = parseInt(newQty, 10);

    if (newQty <= 0) {
      posCart = posCart.filter((it) => it.product.id !== productId);
    } else if (newQty > shopStock) {
      CeylonAPI.showToast(`Only ${shopStock} units available in shop stock`, 'warning');
      return;
    } else {
      item.quantity = newQty;
      item.lineTotal = newQty * item.unitPrice;
    }
    renderTicket();
  };

  /* ---- Remove From Cart ---- */
  window.removeFromCart = function (productId) {
    posCart = posCart.filter((it) => it.product.id !== productId);
    renderTicket();
  };

  /* ---- Clear POS Cart ---- */
  window.clearPosCart = function () {
    if (posCart.length > 0 && !confirm('Clear the entire current sale? This cannot be undone.')) return;
    posCart = [];
    renderTicket();
    document.getElementById('pos-discount-input').value = '0';
    document.getElementById('pos-cash-received').value = '0';
    document.getElementById('pos-customer-name').value = '';
    document.getElementById('pos-customer-phone').value = '';
  };

  /* ---- Render Ticket Panel ---- */
  function renderTicket() {
    const container = document.getElementById('pos-ticket-items');
    const ticketIdEl = document.getElementById('pos-ticket-id');

    if (posCart.length === 0) {
      container.innerHTML = `
        <div style="text-align:center; padding:3rem 1rem; color:#374d3f;">
          <div style="font-size:2rem; margin-bottom:.5rem;">🎱</div>
          <div style="font-size:.85rem;">Scan or search a product to begin.</div>
        </div>
      `;
      ticketIdEl.textContent = 'No active ticket';
    } else {
      ticketIdEl.textContent = `${posCart.length} item(s) | ${new Date().toLocaleTimeString()}`;
      container.innerHTML = posCart.map((item) => `
        <div class="pos-ticket-row">
          <div style="flex:1; min-width:0;">
            <div style="font-weight:700; font-size:0.85rem; color:#fff; margin-bottom:.2rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
              ${item.product.name}
            </div>
            <div style="font-size:0.75rem; color:#6b8578;">${item.product.sku}</div>
          </div>
          <div style="display:flex; align-items:center; gap:.4rem; margin-left:.5rem;">
            <button class="pos-qty-btn" onclick="updateCartQty(${item.product.id}, ${item.quantity - 1})">-</button>
            <span style="font-weight:800; font-size:.9rem; color:#fff; min-width:1.5rem; text-align:center;">${item.quantity}</span>
            <button class="pos-qty-btn" onclick="updateCartQty(${item.product.id}, ${item.quantity + 1})">+</button>
          </div>
          <div style="text-align:right; margin-left:.5rem; min-width:80px;">
            <div style="font-weight:800; color:#fff; font-size:.9rem;">${CeylonAPI.formatLKR(item.lineTotal)}</div>
            <button class="pos-remove-item" onclick="removeFromCart(${item.product.id})">remove</button>
          </div>
        </div>
      `).join('');
    }

    updatePosTotals();
  }

  /* ---- Update Totals ---- */
  window.updatePosTotals = function () {
    const subtotal = posCart.reduce((acc, it) => acc + it.lineTotal, 0);
    const discount = parseFloat(document.getElementById('pos-discount-input')?.value || 0);
    const grandTotal = Math.max(0, subtotal - discount);

    document.getElementById('pos-subtotal').textContent = CeylonAPI.formatLKR(subtotal);
    document.getElementById('pos-grand-total-value').textContent = CeylonAPI.formatLKR(grandTotal);

    const discountRow = document.getElementById('pos-discount-row');
    if (discount > 0) {
      discountRow.style.display = 'flex';
      document.getElementById('pos-discount-display').textContent = `- ${CeylonAPI.formatLKR(discount)}`;
    } else {
      discountRow.style.display = 'none';
    }

    updateChangeDue();
  };

  /* ---- Cash Change Calculation ---- */
  window.updateChangeDue = function () {
    if (currentPayMethod !== 'cash') return;
    const discount = parseFloat(document.getElementById('pos-discount-input')?.value || 0);
    const subtotal = posCart.reduce((acc, it) => acc + it.lineTotal, 0);
    const grandTotal = Math.max(0, subtotal - discount);
    const cashReceived = parseFloat(document.getElementById('pos-cash-received')?.value || 0);
    const change = Math.max(0, cashReceived - grandTotal);
    document.getElementById('pos-change-due').textContent = CeylonAPI.formatLKR(change);
  };

  window.setTender = function (amount) {
    document.getElementById('pos-cash-received').value = amount;
    updateChangeDue();
  };

  window.setExactTender = function () {
    const discount = parseFloat(document.getElementById('pos-discount-input')?.value || 0);
    const subtotal = posCart.reduce((acc, it) => acc + it.lineTotal, 0);
    const grandTotal = Math.max(0, subtotal - discount);
    document.getElementById('pos-cash-received').value = grandTotal.toFixed(2);
    updateChangeDue();
  };

  /* ---- Switch Payment Method Tab ---- */
  window.switchPayTab = function (method) {
    currentPayMethod = method;
    ['cash', 'card', 'bank_transfer'].forEach((m) => {
      document.getElementById(`pay-tab-${m}`)?.classList.remove('active');
      const panel = document.getElementById(`pay-panel-${m}`);
      if (panel) panel.style.display = 'none';
    });
    document.getElementById(`pay-tab-${method}`)?.classList.add('active');
    const target = document.getElementById(`pay-panel-${method}`);
    if (target) target.style.display = 'block';
  };

  /* ---- Complete POS Sale ---- */
  window.completePOSSale = async function () {
    if (posCart.length === 0) {
      CeylonAPI.showToast('Cannot complete sale: cart is empty', 'warning');
      return;
    }

    if (!posSession) {
      CeylonAPI.showToast('No active POS session. Please open a register first.', 'error');
      return;
    }

    const discount = parseFloat(document.getElementById('pos-discount-input')?.value || 0);
    const subtotal = posCart.reduce((acc, it) => acc + it.lineTotal, 0);
    const grandTotal = Math.max(0, subtotal - discount);

    // Validate payment method fields
    let cashReceived = null;
    let cardRef = null;

    if (currentPayMethod === 'cash') {
      cashReceived = parseFloat(document.getElementById('pos-cash-received')?.value || 0);
      if (cashReceived < grandTotal) {
        CeylonAPI.showToast(`Cash received (${CeylonAPI.formatLKR(cashReceived)}) is less than the total due (${CeylonAPI.formatLKR(grandTotal)})`, 'warning');
        return;
      }
    } else if (currentPayMethod === 'card') {
      cardRef = document.getElementById('pos-card-ref')?.value?.trim();
      if (!cardRef) {
        CeylonAPI.showToast('Please enter the card terminal approval reference number', 'warning');
        document.getElementById('pos-card-ref').focus();
        return;
      }
    } else if (currentPayMethod === 'bank_transfer') {
      cardRef = document.getElementById('pos-bank-ref')?.value?.trim();
      if (!cardRef) {
        CeylonAPI.showToast('Please enter the customer bank transfer reference number', 'warning');
        document.getElementById('pos-bank-ref').focus();
        return;
      }
    }

    const checkoutBtn = document.getElementById('pos-checkout-btn');
    checkoutBtn.disabled = true;
    checkoutBtn.textContent = 'Processing sale...';

    try {
      const customerName = document.getElementById('pos-customer-name')?.value?.trim() || 'Walk-in Customer';
      const customerPhone = document.getElementById('pos-customer-phone')?.value?.trim() || '0000000000';

      const payload = {
        session_id: posSession.id,
        payment_method: currentPayMethod,
        customer_name: customerName,
        customer_phone: customerPhone,
        discount_amount: discount,
        items: posCart.map((it) => ({
          product_id: it.product.id,
          variant_id: it.product.variant_id ?? null,
          quantity: it.quantity,
          unit_price: it.unitPrice,
        })),
      };

      if (currentPayMethod === 'cash') {
        payload.cash_received = cashReceived;
      } else if (currentPayMethod === 'card' || currentPayMethod === 'bank_transfer') {
        payload.card_reference = cardRef;
      }

      const res = await CeylonAPI.post('/pos/sales', payload);
      const saleData = res.data;

      // Track cash sales for session closing
      if (currentPayMethod === 'cash') {
        sessionCashSales += grandTotal;
        updateCloseExpected();
      }

      // Show receipt
      showReceipt(saleData, posCart, grandTotal, cashReceived, discount, currentPayMethod);

    } catch (err) {
      CeylonAPI.showToast(err.message || 'Failed to process sale', 'error');
    } finally {
      checkoutBtn.disabled = false;
      checkoutBtn.textContent = 'Complete Sale ✓';
    }
  };

  /* ---- Show Receipt ---- */
  function showReceipt(saleData, cartItems, grandTotal, cashReceived, discount, payMethod) {
    const change = payMethod === 'cash' ? Math.max(0, cashReceived - grandTotal) : 0;
    const now = new Date();

    const receiptEl = document.getElementById('printable-receipt');
    receiptEl.innerHTML = `
      <div style="text-align:center; margin-bottom:12px;">
        <div style="font-size:14px; font-weight:900; letter-spacing:2px;">CEYLON CUES</div>
        <div style="font-size:10px;">Billiards & Pool Pro Shop</div>
        <div style="font-size:9px; margin-top:4px;">
          No. 45/A, Galle Road, Colombo 03, Sri Lanka<br>
          Tel: +94 77 123 4567 | ceyloncues.lk
        </div>
        <hr class="receipt-divider" style="margin-top:8px;">
        <div style="font-size:10px;">
          <strong>REF: ${saleData.reference_number || 'CC-POS-' + saleData.id}</strong><br>
          ${now.toLocaleDateString('en-GB')} ${now.toLocaleTimeString()}<br>
          Cashier: ${CeylonAPI.getUser()?.first_name || 'Staff'} | Session #${posSession?.id}
        </div>
      </div>

      <hr class="receipt-divider">
      <div style="display:flex; justify-content:space-between; font-weight:700; margin-bottom:4px; font-size:10px;">
        <span>ITEM</span><span>QTY</span><span style="text-align:right;">TOTAL</span>
      </div>
      <hr class="receipt-divider">

      ${cartItems.map((it) => `
        <div style="margin-bottom:4px; font-size:10px;">
          <div>${it.product.name}</div>
          <div style="display:flex; justify-content:space-between; color:#333;">
            <span>${CeylonAPI.formatLKR(it.unitPrice)} × ${it.quantity}</span>
            <span>${CeylonAPI.formatLKR(it.lineTotal)}</span>
          </div>
        </div>
      `).join('')}

      <hr class="receipt-divider">
      ${discount > 0 ? `
        <div style="display:flex; justify-content:space-between; font-size:10px;">
          <span>Cashier Discount</span><span>- ${CeylonAPI.formatLKR(discount)}</span>
        </div>
      ` : ''}
      <div style="display:flex; justify-content:space-between; font-size:12px; font-weight:900; margin-top:4px;">
        <span>TOTAL DUE</span><span>${CeylonAPI.formatLKR(grandTotal)}</span>
      </div>
      <hr class="receipt-divider">
      <div style="font-size:10px;">
        <div style="display:flex; justify-content:space-between;">
          <span>Payment Method:</span><span><strong>${payMethod.replace('_', ' ').toUpperCase()}</strong></span>
        </div>
        ${payMethod === 'cash' ? `
          <div style="display:flex; justify-content:space-between;">
            <span>Cash Tendered:</span><span>${CeylonAPI.formatLKR(cashReceived)}</span>
          </div>
          <div style="display:flex; justify-content:space-between; font-weight:900;">
            <span>Change Given:</span><span>${CeylonAPI.formatLKR(change)}</span>
          </div>
        ` : ''}
      </div>
      <hr class="receipt-divider">
      <div style="text-align:center; font-size:9px; margin-top:8px;">
        <div>Thank you for shopping at Ceylon Cues!</div>
        <div>All equipment comes with manufacturer warranty.</div>
        <div style="margin-top:4px;">No returns on chalk & consumables. Cue returns within 7 days.</div>
      </div>
    `;

    document.getElementById('receipt-modal-backdrop').style.display = 'flex';
  }

  /* ---- Start New Sale ---- */
  window.startNewSale = function () {
    document.getElementById('receipt-modal-backdrop').style.display = 'none';

    // Reset cart & fields
    posCart = [];
    renderTicket();
    document.getElementById('pos-discount-input').value = '0';
    document.getElementById('pos-cash-received').value = '0';
    document.getElementById('pos-card-ref').value = '';
    document.getElementById('pos-bank-ref').value = '';
    document.getElementById('pos-customer-name').value = '';
    document.getElementById('pos-customer-phone').value = '';

    // Reset to cash payment method
    switchPayTab('cash');
    updatePosTotals();

    // Reload products (refresh shop stock)
    loadProducts().then(renderProductGrid);

    // Focus scanner
    setTimeout(() => {
      document.getElementById('pos-barcode-input').value = '';
      document.getElementById('pos-barcode-input').focus();
    }, 100);
  };

  /* ---- Close Register Session ---- */
  function updateCloseExpected() {
    const opening = posSession ? parseFloat(posSession.opening_balance || 0) : 0;
    const cashSales = sessionCashSales;
    const expected = opening + cashSales;
    document.getElementById('close-cash-sales').textContent = CeylonAPI.formatLKR(cashSales);
    document.getElementById('close-expected-cash').textContent = CeylonAPI.formatLKR(expected);
  }

  window.openCloseSessionDialog = function () {
    if (!posSession) {
      CeylonAPI.showToast('No active session to close.', 'warning');
      return;
    }
    updateCloseExpected();
    document.getElementById('close-session-overlay').style.display = 'flex';

    const form = document.getElementById('pos-close-session-form');
    form.onsubmit = handleCloseSession;
  };

  window.cancelCloseSession = function () {
    document.getElementById('close-session-overlay').style.display = 'none';
  };

  async function handleCloseSession(e) {
    e.preventDefault();
    const form = e.target;
    const submitBtn = form.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Closing register...';

    try {
      await CeylonAPI.post(`/pos/sessions/${posSession.id}/close`, {
        closing_balance: parseFloat(form.closing_balance.value),
        notes: form.notes.value,
      });

      localStorage.removeItem('ceylon_cues_pos_session');
      posSession = null;
      CeylonAPI.showToast('Register closed. Session summary saved. Goodbye!', 'success');

      setTimeout(() => {
        window.location.href = 'erp.html';
      }, 1500);
    } catch (err) {
      CeylonAPI.showToast(err.message || 'Failed to close session', 'error');
      submitBtn.disabled = false;
      submitBtn.textContent = 'Close Register & End Shift';
    }
  }

  /* ---- Error display helper ---- */
  function showError(msg) {
    const overlay = document.getElementById('session-overlay');
    if (overlay) {
      overlay.innerHTML = `
        <div class="session-card" style="text-align:center;">
          <div style="font-size:2rem; margin-bottom:1rem;">⛔</div>
          <div style="color:var(--color-danger); font-weight:700; margin-bottom:1rem;">${msg}</div>
          <a href="index.html" class="btn btn-outline" style="color:#fff; border-color:#333;">Back to Storefront</a>
        </div>
      `;
    }
  }

})();
