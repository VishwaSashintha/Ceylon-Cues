/**
 * Ceylon Cues — Central ERP Application Controller
 * Manages Dashboard KPIs, Orders, Bank Verifications, Multi-Location Inventory, Transfers, Finance, and RBAC.
 */

(function () {
  'use strict';

  let currentView = 'dashboard';
  let cachedProducts = [];
  let cachedCategories = [];
  let cachedOrders = [];

  // Initialization
  document.addEventListener('DOMContentLoaded', async () => {
    const user = CeylonAPI.getUser();
    if (!user || !CeylonAPI.isStaff()) {
      CeylonAPI.showToast('Authentication required for ERP access.', 'warning');
      setTimeout(() => {
        window.location.href = 'index.html';
      }, 1000);
      return;
    }

    // Set user UI
    document.getElementById('erp-user-name').textContent = `${user.first_name} ${user.last_name || ''}`.trim();
    const roleBadge = document.getElementById('erp-user-role');
    if (roleBadge) {
      roleBadge.textContent = (user.role || 'Staff').toUpperCase();
    }

    // Show owner navigation if role is owner
    if (CeylonAPI.isOwner()) {
      document.querySelectorAll('.owner-only-nav').forEach((el) => {
        el.style.display = 'block';
      });
    }

    // Populate dropdowns and initial dashboard
    await Promise.all([loadCategories(), loadProductsList()]);
    await loadDashboard();
    setupEventListeners();
  });

  // Setup Event Listeners
  function setupEventListeners() {
    // Orders filters
    const orderSearch = document.getElementById('orders-search-input');
    if (orderSearch) {
      let timer;
      orderSearch.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(loadOrders, 300);
      });
    }

    const statusFilter = document.getElementById('orders-filter-status');
    if (statusFilter) statusFilter.addEventListener('change', loadOrders);

    const channelFilter = document.getElementById('orders-filter-channel');
    if (channelFilter) channelFilter.addEventListener('change', loadOrders);

    // Products filters
    const prodSearch = document.getElementById('erp-prod-search');
    if (prodSearch) {
      let timer;
      prodSearch.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(renderProductsTable, 300);
      });
    }

    const prodCat = document.getElementById('erp-prod-cat-filter');
    if (prodCat) prodCat.addEventListener('change', renderProductsTable);

    // Forms
    const statusForm = document.getElementById('order-status-form');
    if (statusForm) statusForm.addEventListener('submit', handleOrderStatusSubmit);

    const prodForm = document.getElementById('create-product-form');
    if (prodForm) prodForm.addEventListener('submit', handleCreateProductSubmit);

    const transferForm = document.getElementById('create-transfer-form');
    if (transferForm) transferForm.addEventListener('submit', handleCreateTransferSubmit);

    const adjustForm = document.getElementById('stock-adjustment-form');
    if (adjustForm) adjustForm.addEventListener('submit', handleStockAdjustmentSubmit);

    const expenseForm = document.getElementById('add-expense-form');
    if (expenseForm) expenseForm.addEventListener('submit', handleAddExpenseSubmit);

    const empForm = document.getElementById('add-employee-form');
    if (empForm) empForm.addEventListener('submit', handleAddEmployeeSubmit);

    const couponForm = document.getElementById('create-coupon-form');
    if (couponForm) couponForm.addEventListener('submit', handleCreateCouponSubmit);

    const catForm = document.getElementById('create-category-form');
    if (catForm) catForm.addEventListener('submit', handleCreateCategorySubmit);
  }

  // View Switching
  window.switchView = function (viewName, navEl) {
    currentView = viewName;

    // Hide all view panels
    document.querySelectorAll('.erp-view-panel').forEach((panel) => {
      panel.style.display = 'none';
    });

    // Show target view panel
    const target = document.getElementById(`view-${viewName}`);
    if (target) target.style.display = 'block';

    // Update active nav class
    if (navEl) {
      document.querySelectorAll('.erp-nav-item').forEach((item) => item.classList.remove('active'));
      navEl.classList.add('active');
    }

    // Set page title
    const titles = {
      dashboard: 'Executive Business Dashboard',
      orders: 'Order Processing & Fulfillment',
      payments: 'Bank Transfer Verification',
      products: 'Products & Catalog Management',
      inventory: 'Multi-Location Inventory Balances',
      transfers: 'Internal Stock Transfers',
      finance: 'Expenses & Profit & Loss Statement',
      marketing: 'Promotional Coupons & Discounts',
      wholesale: 'Wholesale & Billiard Club Inquiries',
      support: 'Customer Support Tickets',
      employees: 'Staff Employees & Permissions (Owner Only)',
      settings: 'System Settings & Backups (Owner Only)',
    };
    const titleEl = document.getElementById('erp-view-title');
    if (titleEl) titleEl.textContent = titles[viewName] || 'Business Management';

    // Trigger corresponding data fetch
    if (viewName === 'dashboard') loadDashboard();
    else if (viewName === 'orders') loadOrders();
    else if (viewName === 'payments') loadBankTransfers();
    else if (viewName === 'products') renderProductsTable();
    else if (viewName === 'inventory') loadStockLevels();
    else if (viewName === 'transfers') loadStockTransfers();
    else if (viewName === 'finance') loadFinanceData();
    else if (viewName === 'marketing') loadCoupons();
    else if (viewName === 'wholesale') loadWholesaleEnquiries();
    else if (viewName === 'support') loadSupportTickets();
    else if (viewName === 'employees') loadEmployees();
  };

  // 1. Dashboard View
  async function loadDashboard() {
    try {
      const res = await CeylonAPI.get('/erp/dashboard');
      const data = res.data || {};

      let totalSales = 0;
      if (Array.isArray(data.revenue_by_channel)) {
        data.revenue_by_channel.forEach((ch) => {
          totalSales += parseFloat(ch.total_revenue || 0);
        });
      } else if (data.today_sales !== undefined) {
        totalSales = parseFloat(data.today_sales || 0);
      }

      const retailValuation = data.inventory_valuation?.retail_value ?? data.month_sales ?? 0;
      const lowStockCount = data.low_stock_alerts ?? data.low_stock_count ?? 0;
      const pendingTransfers = data.pending_transfers ?? data.pending_bank_transfers ?? 0;

      document.getElementById('kpi-today-sales').textContent = CeylonAPI.formatLKR(totalSales);
      document.getElementById('kpi-month-sales').textContent = CeylonAPI.formatLKR(retailValuation);
      document.getElementById('kpi-pending-orders').textContent = pendingTransfers;
      document.getElementById('kpi-low-stock').textContent = lowStockCount;
      document.getElementById('kpi-pending-transfers').textContent = pendingTransfers;

      // Recent Orders Table: fetch live from orders endpoint if not nested in dashboard overview
      const recentTbody = document.getElementById('dashboard-recent-orders');
      let orders = data.recent_orders || [];
      if (!orders.length) {
        try {
          const ordRes = await CeylonAPI.get('/orders?per_page=5');
          orders = ordRes.data?.items || [];
        } catch (_) {}
      }

      if (orders.length === 0) {
        recentTbody.innerHTML = '<tr><td colspan="6" style="text-align: center;">No orders recorded yet.</td></tr>';
      } else {
        recentTbody.innerHTML = orders.map((o) => `
          <tr>
            <td><strong>${o.reference_number}</strong></td>
            <td>${o.customer_name}</td>
            <td><span class="badge ${o.channel === 'pos' ? 'badge-dark' : 'badge-emerald'}">${(o.channel || 'WEB').toUpperCase()}</span></td>
            <td>${CeylonAPI.formatLKR(o.total_amount)}</td>
            <td><span class="badge ${getStatusBadgeClass(o.status)}">${(o.status || 'PENDING').toUpperCase()}</span></td>
            <td>
              <button class="btn btn-outline btn-sm" onclick="openOrderStatusModal(${o.id}, '${o.reference_number}', '${o.status}')">
                Status
              </button>
            </td>
          </tr>
        `).join('');
      }

      // Low Stock List: fetch live from stock endpoint if needed
      const lowStockEl = document.getElementById('dashboard-low-stock-list');
      let lowItems = data.low_stock_items || [];
      if (!lowItems.length && lowStockCount > 0) {
        try {
          const stkRes = await CeylonAPI.get('/inventory/stock');
          const allStk = stkRes.data || [];
          lowItems = allStk
            .filter((s) => (s.quantity_available ?? s.quantity_on_hand ?? 0) <= 5)
            .map((s) => ({
              name: s.product_name,
              sku: s.sku,
              quantity: s.quantity_available ?? s.quantity_on_hand,
            }));
        } catch (_) {}
      }

      if (lowItems && lowItems.length > 0) {
        lowStockEl.innerHTML = lowItems.map((it) => `
          <div style="padding: 0.5rem 0; border-bottom: 1px solid var(--color-border); display: flex; justify-content: space-between;">
            <span>${it.name} (${it.sku})</span>
            <strong style="color: var(--color-danger);">${it.quantity} on hand</strong>
          </div>
        `).join('');
      } else {
        lowStockEl.innerHTML = '<div style="color: var(--color-primary); font-weight: 600;">✓ All items above safe threshold.</div>';
      }
    } catch (e) {
      console.error('Error loading dashboard', e);
    }
  }

  // 2. Orders View
  async function loadOrders() {
    const tbody = document.getElementById('erp-orders-tbody');
    tbody.innerHTML = '<tr><td colspan="8" style="text-align: center;">Loading orders...</td></tr>';

    const search = document.getElementById('orders-search-input')?.value || '';
    const status = document.getElementById('orders-filter-status')?.value || '';
    const channel = document.getElementById('orders-filter-channel')?.value || '';

    let endpoint = `/orders?per_page=50`;
    if (search) endpoint += `&search=${encodeURIComponent(search)}`;
    if (status) endpoint += `&status=${encodeURIComponent(status)}`;
    if (channel) endpoint += `&channel=${encodeURIComponent(channel)}`;

    try {
      const res = await CeylonAPI.get(endpoint);
      cachedOrders = res.data?.items || [];

      if (cachedOrders.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align: center; color: var(--color-text-muted);">No matching orders found.</td></tr>';
        return;
      }

      tbody.innerHTML = cachedOrders.map((o) => `
        <tr>
          <td><strong>${o.reference_number}</strong></td>
          <td>${CeylonAPI.formatDate(o.created_at)}</td>
          <td>
            <div>${o.customer_name}</div>
            <div style="font-size: 0.75rem; color: var(--color-text-muted);">${o.customer_phone}</div>
          </td>
          <td><span class="badge ${o.channel === 'pos' ? 'badge-dark' : 'badge-emerald'}">${o.channel.toUpperCase()}</span></td>
          <td style="font-weight: 700;">${CeylonAPI.formatLKR(o.total_amount)}</td>
          <td><span class="badge ${o.payment_status === 'paid' ? 'badge-emerald' : 'badge-warning'}">${(o.payment_status || 'PENDING').toUpperCase()}</span></td>
          <td><span class="badge ${getStatusBadgeClass(o.status)}">${o.status.toUpperCase()}</span></td>
          <td>
            <button class="btn btn-outline btn-sm" onclick="openOrderStatusModal(${o.id}, '${o.reference_number}', '${o.status}')">
              Manage
            </button>
          </td>
        </tr>
      `).join('');
    } catch (e) {
      tbody.innerHTML = '<tr><td colspan="8" style="text-align: center; color: var(--color-danger);">Failed to load orders.</td></tr>';
    }
  }

  window.openOrderStatusModal = function (orderId, ref, currentStatus) {
    document.getElementById('status-order-id').value = orderId;
    document.getElementById('status-order-ref').value = ref;
    document.getElementById('status-order-new').value = currentStatus;
    document.getElementById('status-order-notes').value = '';
    CeylonAPI.openModal('order-status-modal');
  };

  async function handleOrderStatusSubmit(e) {
    e.preventDefault();
    const orderId = document.getElementById('status-order-id').value;
    const status = document.getElementById('status-order-new').value;
    const notes = document.getElementById('status-order-notes').value;

    try {
      await CeylonAPI.put(`/orders/${orderId}/status`, { status, notes });
      CeylonAPI.showToast(`Order status updated to ${status.toUpperCase()}`, 'success');
      CeylonAPI.closeModal('order-status-modal');
      loadOrders();
      loadDashboard();
    } catch (err) {
      CeylonAPI.showToast(err.message || 'Failed to update order status', 'error');
    }
  }

  // 3. Bank Verification View
  async function loadBankTransfers() {
    const tbody = document.getElementById('erp-bank-transfers-tbody');
    tbody.innerHTML = '<tr><td colspan="7" style="text-align: center;">Loading submissions...</td></tr>';

    try {
      const res = await CeylonAPI.get('/payments/bank-transfers/pending');
      const list = res.data || [];

      if (list.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: var(--color-text-muted); padding: 2rem;">No pending bank transfers awaiting verification.</td></tr>';
        return;
      }

      tbody.innerHTML = list.map((item) => `
        <tr>
          <td><strong>${item.order_reference}</strong></td>
          <td>
            <div>${item.customer_name}</div>
            <div style="font-size: 0.75rem; color: var(--color-text-muted);">${item.account_holder}</div>
          </td>
          <td>
            <strong>${item.bank_name}</strong>
            <div style="font-size: 0.75rem; color: var(--color-text-muted);">Ref: ${item.transfer_reference}</div>
          </td>
          <td style="font-weight: 700; color: var(--color-primary-dark);">${CeylonAPI.formatLKR(item.total_amount)}</td>
          <td>${item.transfer_date}</td>
          <td>${CeylonAPI.formatDate(item.created_at)}</td>
          <td>
            <div style="display: flex; gap: 0.5rem;">
              <button class="btn btn-primary btn-sm" onclick="verifyBankTransferAction(${item.id}, true)">
                ✓ Approve
              </button>
              <button class="btn btn-danger btn-sm" onclick="verifyBankTransferAction(${item.id}, false)">
                ✕ Reject
              </button>
            </div>
          </td>
        </tr>
      `).join('');
    } catch (e) {
      tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: var(--color-danger);">Failed to load bank transfers.</td></tr>';
    }
  }

  window.verifyBankTransferAction = async function (submissionId, approved) {
    const actionLabel = approved ? 'Approve & confirm payment' : 'Reject';
    if (!confirm(`Are you sure you want to ${actionLabel} for this bank transfer?`)) {
      return;
    }

    try {
      await CeylonAPI.post(`/payments/bank-transfers/${submissionId}/verify`, { approved });
      CeylonAPI.showToast(`Transfer successfully ${approved ? 'approved' : 'rejected'}!`, 'success');
      loadBankTransfers();
      loadDashboard();
    } catch (err) {
      CeylonAPI.showToast(err.message || 'Action failed', 'error');
    }
  };

  // 4. Products & Catalog View
  async function loadProductsList() {
    try {
      const res = await CeylonAPI.get('/products?per_page=100');
      cachedProducts = res.data?.items || [];
      renderProductsTable();
      populateProductSelects();
    } catch (e) {
      console.error('Failed to load products list', e);
    }
  }

  async function loadCategories() {
    try {
      const res = await CeylonAPI.get('/categories');
      cachedCategories = res.data || [];

      // Populate filter dropdown & form category dropdown
      const filter = document.getElementById('erp-prod-cat-filter');
      const formCat = document.getElementById('prod-form-category');

      if (filter) {
        filter.innerHTML = '<option value="">All Categories</option>' + 
          cachedCategories.map((c) => `<option value="${c.id}">${c.name}</option>`).join('');
      }
      if (formCat) {
        formCat.innerHTML = cachedCategories.map((c) => `<option value="${c.id}">${c.name}</option>`).join('');
      }
    } catch (e) {
      console.error('Failed to load categories', e);
    }
  }

  function renderProductsTable() {
    const tbody = document.getElementById('erp-products-tbody');
    if (!tbody) return;

    const search = (document.getElementById('erp-prod-search')?.value || '').toLowerCase();
    const catId = document.getElementById('erp-prod-cat-filter')?.value;

    let filtered = [...cachedProducts];
    if (search) {
      filtered = filtered.filter((p) => p.name.toLowerCase().includes(search) || p.sku.toLowerCase().includes(search));
    }
    if (catId) {
      filtered = filtered.filter((p) => String(p.category_id) === String(catId));
    }

    if (filtered.length === 0) {
      tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; color: var(--color-text-muted);">No products found.</td></tr>';
      return;
    }

    tbody.innerHTML = filtered.map((p) => `
      <tr>
        <td>
          <strong>${p.sku}</strong>
          <div style="font-size: 0.75rem; color: var(--color-text-muted);">${p.barcode || '—'}</div>
        </td>
        <td>
          <div style="font-weight: 600;">${p.name}</div>
          <div style="font-size: 0.75rem; color: var(--color-gold-dark);">${(p.quality_tier || 'Standard').toUpperCase()}</div>
        </td>
        <td>${p.category_name || '—'}</td>
        <td>${CeylonAPI.formatLKR(p.cost_price)}</td>
        <td style="font-weight: 700; color: var(--color-primary);">${CeylonAPI.formatLKR(p.retail_price)}</td>
        <td><span class="badge badge-emerald">Shop: ${p.total_physical_stock || '0'}</span></td>
        <td><span class="badge badge-dark">Whse: ${p.total_physical_stock || '0'}</span></td>
        <td><span class="badge badge-gold">Alloc: ${p.online_stock || '0'}</span></td>
        <td>
          <button class="btn btn-outline btn-sm" onclick="openStockAdjustmentModal(${p.id})">Adjust</button>
        </td>
      </tr>
    `).join('');
  }

  function populateProductSelects() {
    const transferSelect = document.getElementById('transfer-form-product');
    const adjustSelect = document.getElementById('adjust-form-product');

    const options = cachedProducts.map((p) => `<option value="${p.id}">${p.name} (${p.sku})</option>`).join('');
    if (transferSelect) transferSelect.innerHTML = options;
    if (adjustSelect) adjustSelect.innerHTML = options;
  }

  window.openCreateProductModal = function () {
    CeylonAPI.openModal('create-product-modal');
  };

  async function handleCreateProductSubmit(e) {
    e.preventDefault();
    const form = e.target;

    try {
      const payload = {
        name: form.name.value,
        sku: form.sku.value,
        category_id: parseInt(form.category_id.value, 10),
        quality_tier: form.quality_tier.value,
        cost_price: parseFloat(form.cost_price.value),
        retail_price: parseFloat(form.retail_price.value),
        wholesale_price: form.wholesale_price.value ? parseFloat(form.wholesale_price.value) : null,
        initial_warehouse_stock: parseInt(form.initial_warehouse_stock.value || 0, 10),
        initial_shop_stock: parseInt(form.initial_shop_stock.value || 0, 10),
        online_allocation: parseInt(form.online_allocation.value || 0, 10),
        description: form.description.value,
      };

      await CeylonAPI.post('/products', payload);
      CeylonAPI.showToast('Product created successfully with stock allocated!', 'success');
      CeylonAPI.closeModal('create-product-modal');
      form.reset();
      await loadProductsList();
    } catch (err) {
      CeylonAPI.showToast(err.message || 'Error creating product', 'error');
    }
  }

  // 5. Multi-Location Stock
  async function loadStockLevels() {
    const tbody = document.getElementById('erp-stock-levels-tbody');
    tbody.innerHTML = '<tr><td colspan="7" style="text-align: center;">Loading stock levels...</td></tr>';

    try {
      const res = await CeylonAPI.get('/inventory/stock');
      const items = res.data || [];

      if (items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align: center;">No stock balances recorded.</td></tr>';
        return;
      }

      tbody.innerHTML = items.map((it) => `
        <tr>
          <td><strong>${it.sku}</strong></td>
          <td>${it.product_name}</td>
          <td><span class="badge ${it.location_id === 1 ? 'badge-dark' : 'badge-emerald'}">${it.location_name || (it.location_id === 1 ? 'Main Warehouse' : 'Physical Shop')}</span></td>
          <td style="font-weight: 700;">${it.quantity_on_hand}</td>
          <td style="color: var(--color-warning);">${it.quantity_reserved}</td>
          <td style="font-weight: 800; color: var(--color-primary);">${it.quantity_available}</td>
          <td>
            <button class="btn btn-outline btn-sm" onclick="openStockAdjustmentModal(${it.product_id}, ${it.location_id})">Adjust</button>
          </td>
        </tr>
      `).join('');
    } catch (e) {
      tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: var(--color-danger);">Failed to load stock levels.</td></tr>';
    }
  }

  window.openStockAdjustmentModal = function (productId = null, locationId = null) {
    if (productId) {
      document.getElementById('adjust-form-product').value = productId;
    }
    if (locationId) {
      document.querySelector('#stock-adjustment-form select[name="location_id"]').value = locationId;
    }
    CeylonAPI.openModal('stock-adjustment-modal');
  };

  async function handleStockAdjustmentSubmit(e) {
    e.preventDefault();
    const form = e.target;
    try {
      await CeylonAPI.post('/inventory/adjustments', {
        location_id: parseInt(form.location_id.value, 10),
        product_id: parseInt(form.product_id.value, 10),
        quantity_change: parseInt(form.quantity_change.value, 10),
        reason: form.reason.value,
        notes: form.notes.value,
      });

      CeylonAPI.showToast('Stock adjustment recorded successfully!', 'success');
      CeylonAPI.closeModal('stock-adjustment-modal');
      form.reset();
      loadStockLevels();
      loadProductsList();
    } catch (err) {
      CeylonAPI.showToast(err.message || 'Error applying stock adjustment', 'error');
    }
  }

  // 6. Stock Transfers
  async function loadStockTransfers() {
    const tbody = document.getElementById('erp-transfers-tbody');
    tbody.innerHTML = '<tr><td colspan="7" style="text-align: center;">Loading transfers...</td></tr>';

    try {
      const res = await CeylonAPI.get('/inventory/transfers');
      const list = res.data || [];

      if (list.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align: center;">No stock transfers recorded.</td></tr>';
        return;
      }

      tbody.innerHTML = list.map((t) => `
        <tr>
          <td><strong>${t.reference_number}</strong></td>
          <td>${t.from_location_name}</td>
          <td>${t.to_location_name}</td>
          <td>${CeylonAPI.formatDate(t.created_at)}</td>
          <td><span class="badge ${t.status === 'completed' ? 'badge-emerald' : 'badge-gold'}">${t.status.toUpperCase()}</span></td>
          <td>${t.item_count || 1} item(s)</td>
          <td>
            ${t.status === 'pending' ? `
              <button class="btn btn-primary btn-sm" onclick="completeTransferAction(${t.id})">
                ✓ Complete Transfer
              </button>
            ` : '<span style="color: var(--color-primary); font-size: 0.85rem; font-weight: 700;">Completed</span>'}
          </td>
        </tr>
      `).join('');
    } catch (e) {
      tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: var(--color-danger);">Failed to load transfers.</td></tr>';
    }
  }

  window.openCreateTransferModal = function () {
    CeylonAPI.openModal('create-transfer-modal');
  };

  async function handleCreateTransferSubmit(e) {
    e.preventDefault();
    const form = e.target;
    try {
      await CeylonAPI.post('/inventory/transfers', {
        from_location_id: parseInt(form.from_location_id.value, 10),
        to_location_id: parseInt(form.to_location_id.value, 10),
        notes: form.notes.value,
        items: [
          {
            product_id: parseInt(form.product_id.value, 10),
            quantity: parseInt(form.quantity.value, 10),
          },
        ],
      });

      CeylonAPI.showToast('Stock transfer initiated!', 'success');
      CeylonAPI.closeModal('create-transfer-modal');
      form.reset();
      loadStockTransfers();
      loadDashboard();
    } catch (err) {
      CeylonAPI.showToast(err.message || 'Error initiating transfer', 'error');
    }
  }

  window.completeTransferAction = async function (transferId) {
    try {
      await CeylonAPI.post(`/inventory/transfers/${transferId}/complete`, {});
      CeylonAPI.showToast('Stock transfer completed! Inventory ledgers updated.', 'success');
      loadStockTransfers();
      loadDashboard();
    } catch (err) {
      CeylonAPI.showToast(err.message || 'Error completing transfer', 'error');
    }
  };

  // 7. Finance & Expenses
  async function loadFinanceData() {
    const pnlContainer = document.getElementById('pnl-summary-content');
    const expTbody = document.getElementById('erp-expenses-tbody');

    try {
      // P&L
      const pnlRes = await CeylonAPI.get('/reports/profit-and-loss');
      const pnl = pnlRes.data || {};

      const grossRev = pnl.gross_revenue ?? pnl.total_sales ?? 0;
      const cogs = pnl.cogs ?? pnl.total_cogs ?? 0;
      const grossMargin = pnl.gross_profit ?? (grossRev - cogs);
      const expenses = pnl.operating_expenses ?? pnl.total_expenses ?? 0;
      const net = pnl.net_profit ?? (grossMargin - expenses);

      pnlContainer.innerHTML = `
        <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--color-border); padding-bottom: 0.5rem;">
          <span>Total Sales Revenue:</span>
          <strong class="text-emerald">${CeylonAPI.formatLKR(grossRev)}</strong>
        </div>
        <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--color-border); padding-bottom: 0.5rem;">
          <span>Cost of Goods Sold (COGS):</span>
          <strong>${CeylonAPI.formatLKR(cogs)}</strong>
        </div>
        <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--color-border); padding-bottom: 0.5rem; font-weight: 700;">
          <span>Gross Margin / Profit:</span>
          <span class="text-emerald">${CeylonAPI.formatLKR(grossMargin)}</span>
        </div>
        <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--color-border); padding-bottom: 0.5rem; color: var(--color-danger);">
          <span>Operating Expenses:</span>
          <strong>- ${CeylonAPI.formatLKR(expenses)}</strong>
        </div>
        <div style="display: flex; justify-content: space-between; font-size: 1.15rem; font-weight: 800; padding-top: 0.5rem;">
          <span>Net Profit (Pre-Tax):</span>
          <span style="color: ${net >= 0 ? 'var(--color-primary)' : 'var(--color-danger)'};">
            ${CeylonAPI.formatLKR(net)}
          </span>
        </div>
      `;

      // Expenses Table
      const expRes = await CeylonAPI.get('/erp/expenses');
      const expenses = expRes.data || [];
      if (expenses.length === 0) {
        expTbody.innerHTML = '<tr><td colspan="4" style="text-align: center;">No expenses recorded yet.</td></tr>';
      } else {
        expTbody.innerHTML = expenses.map((ex) => `
          <tr>
            <td>${ex.expense_date}</td>
            <td><span class="badge badge-dark">${ex.category}</span></td>
            <td>${ex.description}</td>
            <td style="font-weight: 700; color: var(--color-danger);">- ${CeylonAPI.formatLKR(ex.amount)}</td>
          </tr>
        `).join('');
      }
    } catch (e) {
      console.error('Finance load error', e);
    }
  }

  window.openAddExpenseModal = function () {
    CeylonAPI.openModal('add-expense-modal');
  };

  async function handleAddExpenseSubmit(e) {
    e.preventDefault();
    const form = e.target;
    try {
      await CeylonAPI.post('/erp/expenses', {
        category: form.category.value,
        amount: parseFloat(form.amount.value),
        payment_method: form.payment_method.value,
        description: form.description.value,
        expense_date: new Date().toISOString().split('T')[0],
      });

      CeylonAPI.showToast('Expense recorded!', 'success');
      CeylonAPI.closeModal('add-expense-modal');
      form.reset();
      loadFinanceData();
    } catch (err) {
      CeylonAPI.showToast(err.message || 'Error recording expense', 'error');
    }
  }

  // 8. Marketing & Coupons
  async function loadCoupons() {
    const tbody = document.getElementById('erp-coupons-tbody');
    try {
      const res = await CeylonAPI.get('/marketing/discounts');
      const list = res.data || [];

      if (list.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align: center;">No promotional discount codes found.</td></tr>';
        return;
      }

      tbody.innerHTML = list.map((c) => `
        <tr>
          <td><strong class="text-gold">${c.code || '—'}</strong><div style="font-size:0.75rem;color:var(--color-text-muted);">${c.name}</div></td>
          <td>${(c.type || '').toUpperCase()}</td>
          <td style="font-weight: 700;">${c.type === 'percentage' ? `${c.value}%` : c.type === 'free_delivery' ? 'Free Delivery' : CeylonAPI.formatLKR(c.value)}</td>
          <td>${CeylonAPI.formatLKR(c.min_order_amount || 0)}</td>
          <td>${c.used_count || 0} / ${c.max_uses || '∞'}</td>
          <td><span class="badge ${c.is_active ? 'badge-emerald' : 'badge-danger'}">${c.is_active ? 'ACTIVE' : 'INACTIVE'}</span></td>
        </tr>
      `).join('');
    } catch (e) {
      tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: var(--color-danger);">Failed to load coupons.</td></tr>';
    }
  }

  // 9. Wholesale Enquiries
  async function loadWholesaleEnquiries() {
    const tbody = document.getElementById('erp-wholesale-tbody');
    try {
      const res = await CeylonAPI.get('/wholesale/enquiries');
      const list = res.data || [];

      if (list.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; color: var(--color-text-muted); padding: 2rem;">No wholesale inquiries received.</td></tr>';
        return;
      }

      tbody.innerHTML = list.map((w) => `
        <tr>
          <td>${CeylonAPI.formatDate(w.created_at)}</td>
          <td><strong>${w.business_name}</strong></td>
          <td>${w.contact_person}</td>
          <td>
            <div>${w.phone}</div>
            <div style="font-size: 0.75rem; color: var(--color-text-muted);">${w.email}</div>
          </td>
          <td style="max-width: 300px;">${w.message}</td>
        </tr>
      `).join('');
    } catch (e) {
      tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; color: var(--color-danger);">Failed to load wholesale inquiries.</td></tr>';
    }
  }

  // 10. Customer Support Desk
  async function loadSupportTickets() {
    const tbody = document.getElementById('erp-support-tbody');
    try {
      const res = await CeylonAPI.get('/support/tickets');
      const list = res.data || [];

      if (list.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align: center;">No support tickets opened.</td></tr>';
        return;
      }

      tbody.innerHTML = list.map((t) => `
        <tr>
          <td><strong>${t.reference_number || 'TICK-' + t.id}</strong></td>
          <td>${t.subject}</td>
          <td><span class="badge badge-dark">${t.category}</span></td>
          <td><span class="badge ${t.priority === 'urgent' ? 'badge-danger' : 'badge-gold'}">${t.priority.toUpperCase()}</span></td>
          <td><span class="badge ${t.status === 'open' ? 'badge-warning' : 'badge-emerald'}">${t.status.toUpperCase()}</span></td>
          <td>
            <button class="btn btn-outline btn-sm" onclick="replySupportTicket(${t.id})">Reply</button>
          </td>
        </tr>
      `).join('');
    } catch (e) {
      tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: var(--color-danger);">Failed to load tickets.</td></tr>';
    }
  }

  window.replySupportTicket = async function (ticketId) {
    const reply = prompt('Enter message to reply to customer:');
    if (!reply) return;

    try {
      await CeylonAPI.post(`/support/tickets/${ticketId}/reply`, { message: reply });
      CeylonAPI.showToast('Reply sent to customer ticket!', 'success');
      loadSupportTickets();
    } catch (e) {
      CeylonAPI.showToast(e.message || 'Error replying to ticket', 'error');
    }
  };

  // 11. Employees & RBAC (Owner Only)
  async function loadEmployees() {
    const tbody = document.getElementById('erp-employees-tbody');
    try {
      const res = await CeylonAPI.get('/employees');
      const list = res.data?.items || [];

      if (list.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align: center;">No employees created.</td></tr>';
        return;
      }

      tbody.innerHTML = list.map((emp) => `
        <tr>
          <td><strong>${emp.first_name} ${emp.last_name || ''}</strong></td>
          <td>${emp.email}</td>
          <td>${emp.phone || '—'}</td>
          <td><span class="badge ${emp.role === 'owner' ? 'badge-gold' : 'badge-emerald'}">${emp.role.toUpperCase()}</span></td>
          <td><span class="badge ${emp.is_active ? 'badge-emerald' : 'badge-danger'}">${emp.is_active ? 'ACTIVE' : 'INACTIVE'}</span></td>
          <td>${CeylonAPI.formatDate(emp.created_at)}</td>
        </tr>
      `).join('');
    } catch (e) {
      tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: var(--color-danger);">Failed to load employees.</td></tr>';
    }
  }

  window.openAddEmployeeModal = function () {
    CeylonAPI.openModal('add-employee-modal');
  };

  async function handleAddEmployeeSubmit(e) {
    e.preventDefault();
    const form = e.target;
    try {
      await CeylonAPI.post('/employees', {
        first_name: form.first_name.value,
        last_name: form.last_name.value,
        email: form.email.value,
        phone: form.phone.value,
        role: form.role.value,
        password: form.password.value,
      });

      CeylonAPI.showToast('Employee account created!', 'success');
      CeylonAPI.closeModal('add-employee-modal');
      form.reset();
      loadEmployees();
    } catch (err) {
      CeylonAPI.showToast(err.message || 'Failed to create employee', 'error');
    }
  }

  // 12. Settings & Backups (Owner Only)
  window.triggerDatabaseBackup = async function () {
    const resDiv = document.getElementById('backup-result');
    resDiv.innerHTML = '<span style="color: var(--color-text-muted);">Executing mysqldump backup across all 60 tables...</span>';

    try {
      const res = await CeylonAPI.post('/settings/backup', {});
      resDiv.innerHTML = `
        <div style="background: #e6f5ee; color: var(--color-primary); padding: 0.85rem; border-radius: var(--radius-md); font-weight: 700; font-size: 0.85rem;">
          ✓ Database Backup Completed Successfully!<br>
          File: <code>${res.data.filename}</code> (${(res.data.size / 1024).toFixed(1)} KB)
        </div>
      `;
      CeylonAPI.showToast('Database backup created successfully!', 'success');
    } catch (e) {
      resDiv.innerHTML = `<div style="background: #fee2e2; color: var(--color-danger); padding: 0.85rem; border-radius: var(--radius-md); font-size: 0.85rem;">Backup failed: ${e.message}</div>`;
    }
  };

  window.triggerTestAlert = async function () {
    const resDiv = document.getElementById('notif-result');
    resDiv.innerHTML = '<span style="color: var(--color-text-muted);">Queueing WhatsApp test notification to owner...</span>';
    try {
      const res = await CeylonAPI.post('/notifications/test', {});
      resDiv.innerHTML = `<div style="color: var(--color-primary); font-weight: 700; font-size: 0.85rem;">✓ Alert queued: ID ${res.data.id}</div>`;
      CeylonAPI.showToast('WhatsApp notification alert queued!', 'success');
    } catch (e) {
      resDiv.innerHTML = `<div style="color: var(--color-danger); font-size: 0.85rem;">${e.message}</div>`;
    }
  };

  window.triggerQueueWorker = async function () {
    const resDiv = document.getElementById('notif-result');
    resDiv.innerHTML = '<span style="color: var(--color-text-muted);">Executing notification queue worker...</span>';
    try {
      const res = await CeylonAPI.post('/notifications/process-queue', {});
      resDiv.innerHTML = `<div style="color: var(--color-primary); font-weight: 700; font-size: 0.85rem;">✓ Processed ${res.data.processed} notification(s).</div>`;
      CeylonAPI.showToast(`Processed ${res.data.processed} notification(s)`, 'success');
    } catch (e) {
      resDiv.innerHTML = `<div style="color: var(--color-danger); font-size: 0.85rem;">${e.message}</div>`;
    }
  };

  // 8b. Create Coupon Modal
  window.openCreateCouponModal = function () {
    CeylonAPI.openModal('create-coupon-modal');
  };

  async function handleCreateCouponSubmit(e) {
    e.preventDefault();
    const form = e.target;
    try {
      await CeylonAPI.post('/marketing/discounts', {
        name: form.name.value,
        code: form.code.value.trim().toUpperCase() || null,
        type: form.type.value,
        value: parseFloat(form.value.value),
        min_order_amount: form.min_order_amount.value ? parseFloat(form.min_order_amount.value) : null,
        max_uses: form.max_uses.value ? parseInt(form.max_uses.value, 10) : null,
        start_date: form.start_date.value || null,
        end_date: form.end_date.value || null,
        is_active: 1,
      });
      CeylonAPI.showToast('Coupon / discount created successfully!', 'success');
      CeylonAPI.closeModal('create-coupon-modal');
      form.reset();
      loadCoupons();
    } catch (err) {
      CeylonAPI.showToast(err.message || 'Failed to create coupon', 'error');
    }
  }

  // 8c. Categories Manager Modal
  window.openCategoriesManagerModal = async function () {
    const list = document.getElementById('categories-modal-list');
    list.innerHTML = '<div style="color:var(--color-text-muted);">Loading categories...</div>';
    CeylonAPI.openModal('categories-manager-modal');
    try {
      const res = await CeylonAPI.get('/categories');
      const cats = res.data || [];
      if (cats.length === 0) {
        list.innerHTML = '<div style="color:var(--color-text-muted);">No categories found.</div>';
        return;
      }
      list.innerHTML = cats.map((c) => `
        <div style="display:flex;justify-content:space-between;align-items:center;padding:0.5rem 0;border-bottom:1px solid var(--color-border);">
          <div>
            <strong>${c.name}</strong>
            <span style="font-size:0.75rem;color:var(--color-text-muted);margin-left:0.5rem;">${c.slug}</span>
            ${c.product_count !== undefined ? `<span class="badge badge-dark" style="margin-left:0.5rem;">${c.product_count} products</span>` : ''}
          </div>
          <button class="btn btn-danger btn-sm" onclick="deleteCategoryAction(${c.id},'${c.name.replace(/'/g,"\\'")}')">Delete</button>
        </div>
      `).join('');
    } catch (e) {
      list.innerHTML = `<div style="color:var(--color-danger);">Failed to load categories: ${e.message}</div>`;
    }
  };

  window.deleteCategoryAction = async function (catId, catName) {
    if (!confirm(`Delete category "${catName}"? Products in this category will become uncategorised.`)) return;
    try {
      await CeylonAPI.delete(`/categories/${catId}`);
      CeylonAPI.showToast('Category deleted.', 'success');
      openCategoriesManagerModal();
      loadCategories();
    } catch (e) {
      CeylonAPI.showToast(e.message || 'Failed to delete category', 'error');
    }
  };

  async function handleCreateCategorySubmit(e) {
    e.preventDefault();
    const form = e.target;
    try {
      await CeylonAPI.post('/categories', {
        name: form.name.value.trim(),
        description: form.description.value.trim(),
      });
      CeylonAPI.showToast('Category created!', 'success');
      form.reset();
      openCategoriesManagerModal();
      loadCategories();
    } catch (err) {
      CeylonAPI.showToast(err.message || 'Failed to create category', 'error');
    }
  }

  // 10b. Movements Audit Modal
  window.openMovementsAuditModal = async function () {
    const tbody = document.getElementById('movements-modal-tbody');
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;">Loading movement audit trail...</td></tr>';
    CeylonAPI.openModal('movements-audit-modal');
    try {
      const res = await CeylonAPI.get('/inventory/movements');
      const list = res.data || [];
      if (list.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--color-text-muted);">No stock movements recorded.</td></tr>';
        return;
      }
      tbody.innerHTML = list.map((m) => `
        <tr>
          <td>${CeylonAPI.formatDate(m.created_at)}</td>
          <td><strong>${m.product_name || m.product_id}</strong></td>
          <td><span class="badge ${m.location_id === 1 ? 'badge-dark' : 'badge-emerald'}">${m.location_name || (m.location_id === 1 ? 'Warehouse' : 'Shop')}</span></td>
          <td><span class="badge badge-dark">${(m.movement_type || '').replace(/_/g,' ').toUpperCase()}</span></td>
          <td style="font-weight:700;color:${m.quantity >= 0 ? 'var(--color-primary)' : 'var(--color-danger)'};">${m.quantity >= 0 ? '+' : ''}${m.quantity}</td>
          <td>${m.quantity_before} → <strong>${m.quantity_after}</strong></td>
          <td style="font-size:0.8rem;color:var(--color-text-muted);">${m.notes || '—'}</td>
        </tr>
      `).join('');
    } catch (e) {
      tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;color:var(--color-danger);">Failed to load movements: ${e.message}</td></tr>`;
    }
  };

  // Helper Badge Color
  function getStatusBadgeClass(status) {
    switch (status) {
      case 'confirmed':
      case 'delivered':
      case 'paid':
        return 'badge-emerald';
      case 'pending':
      case 'processing':
        return 'badge-gold';
      case 'cancelled':
      case 'failed':
        return 'badge-danger';
      default:
        return 'badge-dark';
    }
  }

})();
