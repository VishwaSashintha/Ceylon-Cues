/**
 * Ceylon Cues — Customer Storefront Controller
 * Powers catalog browsing, search/filter, product modals, cart drawer, checkout, and bank transfer uploads.
 */

(function () {
  'use strict';

  let products = [];
  let categories = [];
  let selectedCategory = '';
  let activeCoupon = null;
  let lastPlacedOrder = null;

  // Initialization
  document.addEventListener('DOMContentLoaded', async () => {
    initAuthUI();
    await loadCategories();
    await loadProducts();
    await CeylonAPI.getCart();

    // Attach listeners
    CeylonAPI.onCartUpdate(renderCartUI);
    setupEventListeners();
  });

  // UI Event Listeners
  function setupEventListeners() {
    // Search input debounced
    const searchInput = document.getElementById('store-search-input');
    if (searchInput) {
      let debounceTimer;
      searchInput.addEventListener('input', (e) => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
          loadProducts(e.target.value);
        }, 300);
      });
    }

    // Filters
    const tierFilter = document.getElementById('filter-tier');
    if (tierFilter) {
      tierFilter.addEventListener('change', () => filterAndRenderProducts());
    }

    const sortFilter = document.getElementById('filter-sort');
    if (sortFilter) {
      sortFilter.addEventListener('change', () => filterAndRenderProducts());
    }

    // Forms
    const checkoutForm = document.getElementById('checkout-form');
    if (checkoutForm) {
      checkoutForm.addEventListener('submit', handleCheckoutSubmit);
    }

    const wholesaleForm = document.getElementById('wholesale-enquiry-form');
    if (wholesaleForm) {
      wholesaleForm.addEventListener('submit', handleWholesaleSubmit);
    }

    const supportForm = document.getElementById('support-ticket-form');
    if (supportForm) {
      supportForm.addEventListener('submit', handleSupportSubmit);
    }

    const loginForm = document.getElementById('login-form');
    if (loginForm) {
      loginForm.addEventListener('submit', handleLoginSubmit);
    }

    const regForm = document.getElementById('register-form');
    if (regForm) {
      regForm.addEventListener('submit', handleRegisterSubmit);
    }
  }

  // Auth UI Rendering
  function initAuthUI() {
    const container = document.getElementById('nav-auth-container');
    if (!container) return;

    const user = CeylonAPI.getUser();
    if (user) {
      const isStaff = CeylonAPI.isStaff();
      let staffLinks = '';
      if (isStaff) {
        staffLinks = `
          <a href="erp.html" style="display: block; padding: 0.5rem 1rem; font-size: 0.85rem; font-weight: 600; color: var(--color-primary); border-bottom: 1px solid var(--color-border);">
            📊 ERP Dashboard
          </a>
          <a href="pos.html" style="display: block; padding: 0.5rem 1rem; font-size: 0.85rem; font-weight: 600; color: var(--color-primary); border-bottom: 1px solid var(--color-border);">
            🖥️ Shop POS Terminal
          </a>
        `;
      }

      container.innerHTML = `
        <div style="position: relative; display: inline-block;">
          <button class="btn btn-outline btn-sm" id="user-menu-btn" onclick="toggleUserDropdown()" style="display: flex; align-items: center; gap: 0.4rem;">
            <span>👤</span>
            <span>${user.first_name || 'My Account'}</span>
            <span style="font-size: 0.65rem;">▼</span>
          </button>
          <div id="user-dropdown" style="display: none; position: absolute; right: 0; top: 110%; width: 200px; background: #ffffff; border: 1px solid var(--color-border); border-radius: var(--radius-md); box-shadow: var(--shadow-lg); z-index: 1000; overflow: hidden;">
            <div style="padding: 0.75rem 1rem; font-size: 0.75rem; color: var(--color-text-muted); background: #fafbfa; border-bottom: 1px solid var(--color-border);">
              Signed in as<br><strong style="color: var(--color-dark);">${user.email}</strong>
            </div>
            ${staffLinks}
            <a href="javascript:void(0)" onclick="openAccountModal()" style="display: block; padding: 0.5rem 1rem; font-size: 0.85rem; color: var(--color-text);">
              📦 My Orders & Tracking
            </a>
            <a href="javascript:void(0)" onclick="CeylonAPI.logout()" style="display: block; padding: 0.5rem 1rem; font-size: 0.85rem; color: var(--color-danger); border-top: 1px solid var(--color-border);">
              🚪 Log Out
            </a>
          </div>
        </div>
      `;
    } else {
      container.innerHTML = `
        <button class="btn btn-outline btn-sm" onclick="CeylonAPI.openModal('login-modal')">Login</button>
        <button class="btn btn-primary btn-sm" onclick="CeylonAPI.openModal('register-modal')">Register</button>
      `;
    }
  }

  window.toggleUserDropdown = function () {
    const el = document.getElementById('user-dropdown');
    if (el) {
      el.style.display = el.style.display === 'none' ? 'block' : 'none';
    }
  };

  // Close user dropdown if clicking outside
  document.addEventListener('click', (e) => {
    const btn = document.getElementById('user-menu-btn');
    const dropdown = document.getElementById('user-dropdown');
    if (dropdown && btn && !btn.contains(e.target) && !dropdown.contains(e.target)) {
      dropdown.style.display = 'none';
    }
  });

  // Load Categories
  async function loadCategories() {
    try {
      const res = await CeylonAPI.get('/categories');
      categories = res.data || [];
      const nav = document.getElementById('category-nav-list');
      if (!nav) return;

      let html = `<a href="javascript:void(0)" class="category-nav-link active" onclick="selectCategory(null, this)">All Equipment</a>`;
      categories.forEach((cat) => {
        html += `
          <a href="javascript:void(0)" class="category-nav-link" onclick="selectCategory(${cat.id}, this)">
            ${cat.name}
          </a>
        `;
      });
      nav.innerHTML = html;
    } catch (e) {
      console.error('Failed to load categories', e);
    }
  }

  window.selectCategory = function (catId, element) {
    selectedCategory = catId ? String(catId) : '';
    document.querySelectorAll('.category-nav-link').forEach((el) => el.classList.remove('active'));
    if (element) element.classList.add('active');

    const catObj = categories.find((c) => String(c.id) === selectedCategory);
    const headline = document.getElementById('catalog-headline');
    if (headline) {
      headline.textContent = catObj ? catObj.name : 'Tournament & Club Equipment';
    }

    filterAndRenderProducts();
  };

  // Load Products from API
  async function loadProducts(search = '') {
    const container = document.getElementById('products-grid-container');
    if (container) {
      container.innerHTML = `
        <div style="grid-column: 1/-1; text-align: center; padding: 4rem 0;">
          <div style="font-size: 1.5rem; margin-bottom: 0.5rem;">🔄 Loading authentic cues...</div>
          <div style="color: var(--color-text-muted);">Fetching from Ceylon Cues database</div>
        </div>
      `;
    }

    try {
      let endpoint = '/products?per_page=100';
      if (search) endpoint += `&search=${encodeURIComponent(search)}`;
      const res = await CeylonAPI.get(endpoint);
      products = res.data?.items || [];
      filterAndRenderProducts();
    } catch (e) {
      if (container) {
        container.innerHTML = `
          <div style="grid-column: 1/-1; text-align: center; padding: 3rem 0; color: var(--color-danger);">
            Failed to load products from server. Please verify backend connection.
          </div>
        `;
      }
    }
  }

  // Filter & Render
  function filterAndRenderProducts() {
    const container = document.getElementById('products-grid-container');
    if (!container) return;

    let filtered = [...products];

    // Category filter
    if (selectedCategory) {
      filtered = filtered.filter((p) => String(p.category_id) === String(selectedCategory));
    }

    // Quality tier filter
    const tier = document.getElementById('filter-tier')?.value;
    if (tier) {
      filtered = filtered.filter((p) => (p.quality_tier || '').toLowerCase() === tier.toLowerCase());
    }

    // Sort filter
    const sort = document.getElementById('filter-sort')?.value || 'featured';
    if (sort === 'price_asc') {
      filtered.sort((a, b) => parseFloat(a.retail_price) - parseFloat(b.retail_price));
    } else if (sort === 'price_desc') {
      filtered.sort((a, b) => parseFloat(b.retail_price) - parseFloat(a.retail_price));
    } else if (sort === 'name_asc') {
      filtered.sort((a, b) => a.name.localeCompare(b.name));
    } else {
      filtered.sort((a, b) => (b.is_featured || 0) - (a.is_featured || 0));
    }

    if (filtered.length === 0) {
      container.innerHTML = `
        <div style="grid-column: 1/-1; text-align: center; padding: 4rem 0;">
          <div style="font-size: 2rem; margin-bottom: 0.5rem;">🎱</div>
          <h3 style="font-size: 1.25rem; font-weight: 700; margin-bottom: 0.5rem;">No Equipment Found</h3>
          <p style="color: var(--color-text-muted);">Try adjusting your category or tier filters.</p>
        </div>
      `;
      return;
    }

    let html = '';
    filtered.forEach((p) => {
      const onlineStock = parseInt(p.online_stock || 0, 10);
      const isOutOfStock = onlineStock <= 0;

      // Tier badge
      let tierBadgeClass = 'badge-emerald';
      let tierLabel = (p.quality_tier || 'Standard').toUpperCase();
      if (p.quality_tier === 'tournament' || p.quality_tier === 'premium') {
        tierBadgeClass = 'badge-gold';
      }

      // Stock badge
      let stockBadge = `<span class="badge badge-emerald product-badge-stock">✓ In Stock (${onlineStock})</span>`;
      if (isOutOfStock) {
        stockBadge = `<span class="badge badge-danger product-badge-stock">Out of Stock</span>`;
      } else if (onlineStock <= 3) {
        stockBadge = `<span class="badge badge-warning product-badge-stock">Only ${onlineStock} Left</span>`;
      }

      // Specs summary preview
      let specsText = '';
      if (p.specifications) {
        if (typeof p.specifications === 'object') {
          const keys = Object.keys(p.specifications).slice(0, 2);
          specsText = keys.map((k) => `${k}: ${p.specifications[k]}`).join(' | ');
        }
      }

      html += `
        <div class="product-card">
          <div class="product-thumb" onclick="openProductModal(${p.id})">
            <img src="uploads/logo/ceylon-cues-logo.png" alt="${p.name}">
            <span class="badge ${tierBadgeClass} product-badge-tier">${tierLabel}</span>
            ${stockBadge}
          </div>
          <div class="product-details">
            <div class="product-category">${p.category_name || 'Billiards'}</div>
            <div class="product-title" onclick="openProductModal(${p.id})" style="cursor: pointer;" title="${p.name}">
              ${p.name}
            </div>
            <div class="product-specs-summary">${specsText || 'Tournament Standard Craftsmanship'}</div>
            <div class="product-footer">
              <div class="product-price">${CeylonAPI.formatLKR(p.retail_price)}</div>
              <button class="btn btn-sm ${isOutOfStock ? 'btn-disabled' : 'btn-primary'}" 
                      ${isOutOfStock ? 'disabled' : ''} 
                      onclick="handleAddToCart(${p.id}, event)">
                ${isOutOfStock ? 'Sold Out' : '+ Add'}
              </button>
            </div>
          </div>
        </div>
      `;
    });

    container.innerHTML = html;
  }

  // Add to Cart handler
  window.handleAddToCart = async function (productId, event) {
    if (event) event.stopPropagation();
    try {
      await CeylonAPI.addToCart(productId, 1);
    } catch (e) {
      CeylonAPI.showToast(e.message || 'Could not add to cart', 'error');
    }
  };

  // Cart UI Rendering
  function renderCartUI(cart) {
    const badge = document.getElementById('header-cart-count');
    if (badge) {
      const count = (cart.items || []).reduce((acc, it) => acc + parseInt(it.quantity, 10), 0);
      badge.textContent = count;
    }

    const list = document.getElementById('cart-items-list');
    const subtotalEl = document.getElementById('cart-drawer-subtotal');
    const checkoutBtn = document.getElementById('cart-checkout-btn');

    if (!list) return;

    if (!cart.items || cart.items.length === 0) {
      list.innerHTML = `
        <div style="text-align: center; padding: 4rem 1rem; color: var(--color-text-muted);">
          <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">🛒</div>
          <div style="font-weight: 700; margin-bottom: 0.25rem;">Your Cart is Empty</div>
          <div style="font-size: 0.85rem;">Explore our tournament cues and add equipment.</div>
        </div>
      `;
      if (subtotalEl) subtotalEl.textContent = 'LKR 0.00';
      if (checkoutBtn) checkoutBtn.disabled = true;
      return;
    }

    if (checkoutBtn) checkoutBtn.disabled = false;
    if (subtotalEl) subtotalEl.textContent = CeylonAPI.formatLKR(cart.subtotal);

    let html = '';
    cart.items.forEach((item) => {
      html += `
        <div class="cart-item">
          <div class="cart-item-img">
            <img src="uploads/logo/ceylon-cues-logo.png" style="max-height: 50px;">
          </div>
          <div class="cart-item-info">
            <div class="cart-item-title">${item.product_name}</div>
            <div class="cart-item-price">${CeylonAPI.formatLKR(item.unit_price)}</div>
            <div style="display: flex; justify-content: space-between; align-items: center;">
              <div class="cart-qty-ctrl">
                <button type="button" onclick="changeCartItemQty(${item.id}, ${item.quantity - 1})">-</button>
                <span>${item.quantity}</span>
                <button type="button" onclick="changeCartItemQty(${item.id}, ${item.quantity + 1})">+</button>
              </div>
              <button style="color: var(--color-danger); font-size: 0.8rem; font-weight: 600;" onclick="removeCartItemAction(${item.id})">
                Remove
              </button>
            </div>
          </div>
        </div>
      `;
    });
    list.innerHTML = html;
  }

  window.changeCartItemQty = async function (itemId, newQty) {
    try {
      if (newQty <= 0) {
        await CeylonAPI.removeCartItem(itemId);
      } else {
        await CeylonAPI.updateCartItem(itemId, newQty);
      }
    } catch (e) {
      CeylonAPI.showToast(e.message || 'Error updating quantity', 'error');
    }
  };

  window.removeCartItemAction = async function (itemId) {
    try {
      await CeylonAPI.removeCartItem(itemId);
    } catch (e) {
      CeylonAPI.showToast(e.message || 'Error removing item', 'error');
    }
  };

  window.clearCartAction = async function () {
    try {
      await CeylonAPI.clearCart();
      CeylonAPI.showToast('Cart cleared', 'info');
    } catch (e) {
      CeylonAPI.showToast(e.message || 'Error clearing cart', 'error');
    }
  };

  // Cart Drawer open/close
  window.openCartDrawer = function () {
    const backdrop = document.getElementById('cart-drawer-backdrop');
    if (backdrop) backdrop.classList.add('active');
  };

  window.closeCartDrawer = function () {
    const backdrop = document.getElementById('cart-drawer-backdrop');
    if (backdrop) backdrop.classList.remove('active');
  };

  // Product Details Modal
  window.openProductModal = function (productId) {
    const p = products.find((prod) => prod.id === productId);
    if (!p) return;

    const titleEl = document.getElementById('modal-product-title');
    const bodyEl = document.getElementById('modal-product-body');
    if (titleEl) titleEl.textContent = p.name;

    const onlineStock = parseInt(p.online_stock || 0, 10);
    const isOutOfStock = onlineStock <= 0;

    // Build specs table
    let specsHtml = '<div style="color: var(--color-text-muted); font-size: 0.9rem;">No detailed specifications listed.</div>';
    if (p.specifications && typeof p.specifications === 'object') {
      const keys = Object.keys(p.specifications);
      if (keys.length > 0) {
        specsHtml = `
          <table class="data-table" style="font-size: 0.85rem;">
            <tbody>
              ${keys.map((k) => `<tr><th style="width: 35%;">${k}</th><td>${p.specifications[k]}</td></tr>`).join('')}
            </tbody>
          </table>
        `;
      }
    }

    bodyEl.innerHTML = `
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 2rem;">
        <div style="background-color: #f7f9f7; border-radius: var(--radius-lg); padding: 2rem; display: flex; align-items: center; justify-content: center;">
          <img src="uploads/logo/ceylon-cues-logo.png" style="max-height: 220px; object-fit: contain;">
        </div>
        <div>
          <div class="badge badge-gold" style="margin-bottom: 0.5rem;">${(p.quality_tier || 'Standard').toUpperCase()} GRADE</div>
          <h2 style="font-size: 1.4rem; font-weight: 800; color: var(--color-dark); margin-bottom: 0.5rem;">${p.name}</h2>
          <div style="font-size: 0.8rem; color: var(--color-text-muted); margin-bottom: 1rem;">SKU: ${p.sku} | Barcode: ${p.barcode || 'N/A'}</div>
          
          <div style="font-size: 1.75rem; font-weight: 800; color: var(--color-primary-dark); margin-bottom: 1rem;">
            ${CeylonAPI.formatLKR(p.retail_price)}
          </div>

          <p style="color: var(--color-text); font-size: 0.95rem; line-height: 1.6; margin-bottom: 1.5rem;">
            ${p.description || 'Championship standard billiards gear with precision balance and low deflection.'}
          </p>

          <div style="margin-bottom: 1.5rem;">
            <div style="font-weight: 700; font-size: 0.9rem; margin-bottom: 0.5rem;">Technical Specifications:</div>
            ${specsHtml}
          </div>

          <div style="display: flex; align-items: center; gap: 1rem;">
            <button class="btn btn-primary btn-lg" style="flex: 1;" ${isOutOfStock ? 'disabled' : ''} onclick="handleAddToCart(${p.id}); CeylonAPI.closeModal('product-modal');">
              ${isOutOfStock ? 'Out of Stock' : 'Add to Cart'}
            </button>
          </div>
        </div>
      </div>
    `;

    CeylonAPI.openModal('product-modal');
  };

  // Checkout Flow
  window.openCheckoutModal = async function () {
    closeCartDrawer();
    const user = CeylonAPI.getUser();
    if (user) {
      const nameInput = document.getElementById('checkout-name');
      const emailInput = document.getElementById('checkout-email');
      const phoneInput = document.getElementById('checkout-phone');
      if (nameInput && !nameInput.value) nameInput.value = `${user.first_name || ''} ${user.last_name || ''}`.trim();
      if (emailInput && !emailInput.value) emailInput.value = user.email || '';
      if (phoneInput && !phoneInput.value) phoneInput.value = user.phone || '';
    }

    CeylonAPI.openModal('checkout-modal');
    await recalculateCheckout();
  };

  window.recalculateCheckout = async function () {
    const cart = await CeylonAPI.getCart();
    const subtotal = parseFloat(cart.subtotal || 0);

    const district = document.getElementById('checkout-district')?.value || 'Colombo';
    const delMethod = document.getElementById('checkout-del-method')?.value || 'home_delivery';
    const couponCode = activeCoupon?.code || document.getElementById('checkout-coupon')?.value || null;

    try {
      const res = await CeylonAPI.post('/checkout/calculate', {
        subtotal,
        district,
        delivery_method: delMethod,
        coupon_code: couponCode,
      });

      const data = res.data;
      document.getElementById('calc-subtotal').textContent = CeylonAPI.formatLKR(data.subtotal);
      document.getElementById('calc-delivery').textContent = CeylonAPI.formatLKR(data.delivery_charge);

      const discountRow = document.getElementById('calc-discount-row');
      const discountEl = document.getElementById('calc-discount');
      if (data.discount_amount > 0) {
        discountRow.style.display = 'flex';
        discountEl.textContent = '- ' + CeylonAPI.formatLKR(data.discount_amount);
      } else {
        discountRow.style.display = 'none';
      }

      document.getElementById('calc-total').textContent = CeylonAPI.formatLKR(data.total_amount);
    } catch (e) {
      console.error('Recalculate error', e);
    }
  };

  window.applyCouponCode = async function () {
    const code = document.getElementById('checkout-coupon')?.value?.trim();
    const feedback = document.getElementById('coupon-feedback');
    if (!code) {
      if (feedback) feedback.innerHTML = '<span style="color: var(--color-danger);">Please enter a coupon code.</span>';
      return;
    }

    try {
      const cart = await CeylonAPI.getCart();
      const res = await CeylonAPI.post('/marketing/validate-coupon', {
        code,
        subtotal: parseFloat(cart.subtotal || 0),
      });

      activeCoupon = { code, ...res.data };
      if (feedback) {
        feedback.innerHTML = `<span style="color: var(--color-primary); font-weight: 700;">✓ Coupon ${code} Applied: LKR ${parseFloat(res.data.discount_amount).toFixed(2)} off!</span>`;
      }
      await recalculateCheckout();
    } catch (e) {
      activeCoupon = null;
      if (feedback) {
        feedback.innerHTML = `<span style="color: var(--color-danger);">${e.message || 'Invalid or expired coupon code'}</span>`;
      }
      await recalculateCheckout();
    }
  };

  async function handleCheckoutSubmit(e) {
    e.preventDefault();
    const submitBtn = document.getElementById('btn-submit-order');
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Processing Order...';
    }

    try {
      const name = document.getElementById('checkout-name')?.value;
      const phone = document.getElementById('checkout-phone')?.value;
      const email = document.getElementById('checkout-email')?.value;
      const district = document.getElementById('checkout-district')?.value;
      const delMethod = document.getElementById('checkout-del-method')?.value;
      const address = document.getElementById('checkout-address')?.value;
      const notes = document.getElementById('checkout-notes')?.value;
      const paymentMethod = document.querySelector('input[name="payment_method"]:checked')?.value || 'bank_transfer';

      const payload = {
        customer_name: name,
        customer_phone: phone,
        customer_email: email,
        district,
        delivery_method: delMethod,
        shipping_address: address,
        notes,
        payment_method: paymentMethod,
        coupon_code: activeCoupon?.code || null,
      };

      const res = await CeylonAPI.post('/checkout/place-order', payload);
      lastPlacedOrder = res.data;

      CeylonAPI.closeModal('checkout-modal');
      openOrderSuccessModal(res.data);
    } catch (err) {
      CeylonAPI.showToast(err.message || 'Failed to place order', 'error');
    } finally {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Confirm & Place Order';
      }
    }
  }

  // Order Success & Bank Transfer Evidence Upload
  function openOrderSuccessModal(orderData) {
    const order = orderData.order;
    const payment = orderData.payment;
    const body = document.getElementById('order-success-body');

    let bankTransferSection = '';
    if (order.payment_method === 'bank_transfer') {
      bankTransferSection = `
        <div style="background-color: var(--color-gold-subtle); border: 1px solid rgba(198, 161, 91, 0.4); border-radius: var(--radius-lg); padding: 1.25rem; margin-top: 1.5rem;">
          <h4 style="font-size: 1rem; font-weight: 700; color: var(--color-gold-dark); margin-bottom: 0.5rem;">
            🏦 Bank Transfer Deposit Instructions
          </h4>
          <p style="font-size: 0.85rem; line-height: 1.5; margin-bottom: 0.75rem;">
            Please transfer <strong>${CeylonAPI.formatLKR(order.total_amount)}</strong> to the account below and submit your transfer slip reference:
          </p>
          <div style="background-color: #ffffff; padding: 0.85rem; border-radius: var(--radius-md); font-size: 0.825rem; margin-bottom: 1rem; border: 1px solid var(--color-border);">
            <strong>Bank:</strong> Bank of Ceylon (BOC)<br>
            <strong>Account Number:</strong> 0089201938<br>
            <strong>Account Name:</strong> Ceylon Cues PVT LTD<br>
            <strong>Branch:</strong> Corporate Branch Colombo
          </div>

          <!-- Slip Submission Form -->
          <form id="bank-slip-form" onsubmit="submitBankTransferSlip(event, ${payment?.id})">
            <div class="form-row">
              <div class="form-group">
                <label class="form-label" style="font-size: 0.8rem;">Your Bank Name *</label>
                <input type="text" name="bank_name" class="form-control" placeholder="e.g. Commercial Bank" required>
              </div>
              <div class="form-group">
                <label class="form-label" style="font-size: 0.8rem;">Account Holder Name *</label>
                <input type="text" name="account_holder" class="form-control" placeholder="Name on receipt" required>
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label class="form-label" style="font-size: 0.8rem;">Transfer / Reference No. *</label>
                <input type="text" name="transfer_reference" class="form-control" placeholder="e.g. TXN9812739" required>
              </div>
              <div class="form-group">
                <label class="form-label" style="font-size: 0.8rem;">Transfer Date *</label>
                <input type="date" name="transfer_date" class="form-control" value="${new Date().toISOString().split('T')[0]}" required>
              </div>
            </div>
            <button type="submit" class="btn btn-gold" style="width: 100%;">
              Submit Transfer Slip for Verification
            </button>
          </form>
          <div id="slip-submission-result" style="margin-top: 0.75rem;"></div>
        </div>
      `;
    }

    body.innerHTML = `
      <div style="text-align: center; margin-bottom: 1.5rem;">
        <div style="font-size: 0.9rem; color: var(--color-text-muted);">Order Reference Number:</div>
        <div style="font-size: 1.75rem; font-weight: 800; color: var(--color-primary); letter-spacing: 0.05em;">
          ${order.reference_number}
        </div>
        <div style="font-size: 0.85rem; color: var(--color-text-muted); margin-top: 0.25rem;">
          Total Payable: <strong>${CeylonAPI.formatLKR(order.total_amount)}</strong>
        </div>
      </div>

      <div class="card" style="font-size: 0.875rem;">
        <div style="display: flex; justify-content: space-between; margin-bottom: 0.4rem;">
          <span style="color: var(--color-text-muted);">Customer:</span>
          <strong>${order.customer_name} (${order.customer_phone})</strong>
        </div>
        <div style="display: flex; justify-content: space-between; margin-bottom: 0.4rem;">
          <span style="color: var(--color-text-muted);">Payment Method:</span>
          <strong>${order.payment_method.toUpperCase()}</strong>
        </div>
        <div style="display: flex; justify-content: space-between; margin-bottom: 0.4rem;">
          <span style="color: var(--color-text-muted);">Delivery Method:</span>
          <strong>${order.delivery_method}</strong>
        </div>
        <div style="display: flex; justify-content: space-between;">
          <span style="color: var(--color-text-muted);">Order Status:</span>
          <span class="badge badge-emerald">${order.status.toUpperCase()}</span>
        </div>
      </div>

      ${bankTransferSection}
    `;

    CeylonAPI.openModal('order-success-modal');
  }

  window.submitBankTransferSlip = async function (e, paymentId) {
    e.preventDefault();
    const form = e.target;
    const resultDiv = document.getElementById('slip-submission-result');

    try {
      const data = {
        payment_id: paymentId,
        bank_name: form.bank_name.value,
        account_holder: form.account_holder.value,
        transfer_reference: form.transfer_reference.value,
        transfer_date: form.transfer_date.value,
      };

      await CeylonAPI.post('/payments/bank-transfer/submit', data);
      resultDiv.innerHTML = `
        <div style="background: #e6f5ee; color: var(--color-primary); padding: 0.75rem; border-radius: var(--radius-md); font-size: 0.85rem; font-weight: 700;">
          ✓ Transfer slip submitted successfully! Our finance team is reviewing your payment.
        </div>
      `;
      form.style.display = 'none';
    } catch (err) {
      resultDiv.innerHTML = `
        <div style="background: #fee2e2; color: var(--color-danger); padding: 0.75rem; border-radius: var(--radius-md); font-size: 0.85rem;">
          ${err.message || 'Submission failed'}
        </div>
      `;
    }
  };

  // Wholesale Enquiry Form Submit
  async function handleWholesaleSubmit(e) {
    e.preventDefault();
    const form = e.target;
    try {
      await CeylonAPI.post('/wholesale/enquiry', {
        business_name: form.business_name.value,
        contact_person: form.contact_name.value,
        email: form.email.value,
        phone: form.phone.value,
        message: form.message.value,
      });

      CeylonAPI.showToast('Thank you! Your wholesale enquiry has been submitted.', 'success');
      form.reset();
    } catch (err) {
      CeylonAPI.showToast(err.message || 'Could not submit enquiry', 'error');
    }
  }

  // Support Ticket Submit
  async function handleSupportSubmit(e) {
    e.preventDefault();
    const form = e.target;
    try {
      await CeylonAPI.post('/support/tickets', {
        subject: form.subject.value,
        category: form.category.value,
        priority: form.priority.value,
        message: form.message.value,
      });

      CeylonAPI.showToast('Support ticket created. Our team will contact you shortly.', 'success');
      CeylonAPI.closeModal('support-modal');
      form.reset();
    } catch (err) {
      CeylonAPI.showToast(err.message || 'Could not submit ticket', 'error');
    }
  }

  // Auth Handlers
  async function handleLoginSubmit(e) {
    e.preventDefault();
    const email = document.getElementById('login-email').value;
    const pass = document.getElementById('login-password').value;

    try {
      await CeylonAPI.login(email, pass);
      CeylonAPI.closeModal('login-modal');
      initAuthUI();
      // Reload cart for user
      await CeylonAPI.getCart();
    } catch (err) {
      CeylonAPI.showToast(err.message || 'Login failed', 'error');
    }
  }

  async function handleRegisterSubmit(e) {
    e.preventDefault();
    const p1 = document.getElementById('reg-password').value;
    const p2 = document.getElementById('reg-password-confirm').value;

    if (p1 !== p2) {
      CeylonAPI.showToast('Passwords do not match', 'error');
      return;
    }

    try {
      await CeylonAPI.register({
        first_name: document.getElementById('reg-first-name').value,
        last_name: document.getElementById('reg-last-name').value,
        email: document.getElementById('reg-email').value,
        phone: document.getElementById('reg-phone').value,
        password: p1,
        password_confirmation: p2,
      });

      CeylonAPI.closeModal('register-modal');
      CeylonAPI.openModal('login-modal');
    } catch (err) {
      CeylonAPI.showToast(err.message || 'Registration failed', 'error');
    }
  }

  // Order Tracking
  window.openOrderTrackingModal = function () {
    CeylonAPI.openModal('tracking-modal');
  };

  window.lookupOrderTracking = async function () {
    const ref = document.getElementById('track-order-ref')?.value?.trim();
    const resultDiv = document.getElementById('tracking-result');
    if (!ref) {
      resultDiv.innerHTML = '<div style="color: var(--color-danger);">Please enter an order reference.</div>';
      return;
    }

    resultDiv.innerHTML = '<div style="color: var(--color-text-muted);">Searching order records...</div>';

    try {
      const res = await CeylonAPI.get(`/orders?search=${encodeURIComponent(ref)}`);
      const items = res.data?.items || [];
      const match = items.find((o) => o.reference_number === ref || String(o.id) === ref);

      if (!match) {
        resultDiv.innerHTML = `
          <div class="card" style="color: var(--color-danger); text-align: center;">
            No order found matching "${ref}". Please check the reference number.
          </div>
        `;
        return;
      }

      resultDiv.innerHTML = `
        <div class="card">
          <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
            <strong>Ref: ${match.reference_number}</strong>
            <span class="badge badge-emerald">${match.status.toUpperCase()}</span>
          </div>
          <div style="font-size: 0.85rem; color: var(--color-text-muted); margin-bottom: 0.5rem;">
            Date: ${CeylonAPI.formatDate(match.created_at)}
          </div>
          <div style="display: flex; justify-content: space-between; font-weight: 700; font-size: 1rem;">
            <span>Total:</span>
            <span>${CeylonAPI.formatLKR(match.total_amount)}</span>
          </div>
          <div style="font-size: 0.85rem; margin-top: 0.5rem;">
            Payment: <strong>${match.payment_status?.toUpperCase() || 'PENDING'}</strong>
          </div>
        </div>
      `;
    } catch (err) {
      resultDiv.innerHTML = `<div style="color: var(--color-danger);">${err.message || 'Tracking lookup error'}</div>`;
    }
  };

  // Customer Account Area
  window.openAccountModal = async function () {
    CeylonAPI.openModal('account-modal');
    await loadCustomerOrders();
    await loadCustomerTickets();
  };

  window.switchAccountTab = function (tab) {
    const ordersTab = document.getElementById('account-tab-orders');
    const ticketsTab = document.getElementById('account-tab-tickets');
    const btnOrders = document.getElementById('tab-btn-orders');
    const btnTickets = document.getElementById('tab-btn-tickets');

    if (tab === 'orders') {
      ordersTab.style.display = 'block';
      ticketsTab.style.display = 'none';
      btnOrders.classList.add('active');
      btnTickets.classList.remove('active');
    } else {
      ordersTab.style.display = 'none';
      ticketsTab.style.display = 'block';
      btnOrders.classList.remove('active');
      btnTickets.classList.add('active');
    }
  };

  async function loadCustomerOrders() {
    const list = document.getElementById('customer-orders-list');
    try {
      const res = await CeylonAPI.get('/orders');
      const orders = res.data?.items || [];
      if (orders.length === 0) {
        list.innerHTML = '<div style="color: var(--color-text-muted); text-align: center; padding: 2rem;">No orders placed yet.</div>';
        return;
      }

      let html = '<div style="display: flex; flex-direction: column; gap: 1rem;">';
      orders.forEach((o) => {
        html += `
          <div class="card" style="padding: 1rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
              <span style="font-weight: 800; color: var(--color-dark);">${o.reference_number}</span>
              <span class="badge badge-emerald">${o.status.toUpperCase()}</span>
            </div>
            <div style="display: flex; justify-content: space-between; font-size: 0.85rem; color: var(--color-text-muted);">
              <span>${CeylonAPI.formatDate(o.created_at)}</span>
              <span style="font-weight: 700; color: var(--color-text);">${CeylonAPI.formatLKR(o.total_amount)}</span>
            </div>
          </div>
        `;
      });
      html += '</div>';
      list.innerHTML = html;
    } catch (e) {
      list.innerHTML = '<div style="color: var(--color-danger);">Failed to load order history.</div>';
    }
  }

  async function loadCustomerTickets() {
    const list = document.getElementById('customer-tickets-list');
    try {
      const res = await CeylonAPI.get('/support/tickets');
      const tickets = res.data || [];
      if (tickets.length === 0) {
        list.innerHTML = '<div style="color: var(--color-text-muted); text-align: center; padding: 2rem;">No support tickets opened.</div>';
        return;
      }

      let html = '<div style="display: flex; flex-direction: column; gap: 1rem;">';
      tickets.forEach((t) => {
        html += `
          <div class="card" style="padding: 1rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
              <span style="font-weight: 700;">${t.subject}</span>
              <span class="badge badge-gold">${t.status.toUpperCase()}</span>
            </div>
            <div style="font-size: 0.85rem; color: var(--color-text-muted);">
              Reference: ${t.reference_number || 'N/A'} | Priority: ${t.priority}
            </div>
          </div>
        `;
      });
      html += '</div>';
      list.innerHTML = html;
    } catch (e) {
      list.innerHTML = '<div style="color: var(--color-danger);">Failed to load support history.</div>';
    }
  }

  window.openSupportModal = function () {
    CeylonAPI.openModal('support-modal');
  };

  window.openLoginModal = function () {
    CeylonAPI.openModal('login-modal');
  };

  window.openRegisterModal = function () {
    CeylonAPI.openModal('register-modal');
  };

})();
