/**
 * Ceylon Cues - ERP API Client
 * Complete API service layer for ERP administrative and inventory operations.
 */
(function(window) {
  'use strict';

  const ErpAPI = {
    // Dashboard & Metrics
    async getDashboardMetrics() {
      return window.API.get('/reports/executive');
    },

    // Products Management
    async getProducts(params = {}) {
      return window.API.get('/products', params);
    },

    async getProduct(id) {
      return window.API.get(`/products/${id}`);
    },

    async createProduct(data) {
      return window.API.post('/products', data);
    },

    async updateProduct(id, data) {
      return window.API.put(`/products/${id}`, data);
    },

    async deleteProduct(id) {
      return window.API.delete(`/products/${id}`);
    },

    async uploadProductImage(productId, file) {
      const formData = new FormData();
      formData.append('image', file);
      return window.API.upload(`/products/${productId}/images`, formData);
    },

    // Categories
    async getCategories() {
      return window.API.get('/categories');
    },

    async createCategory(data) {
      return window.API.post('/categories', data);
    },

    async updateCategory(id, data) {
      return window.API.put(`/categories/${id}`, data);
    },

    async deleteCategory(id) {
      return window.API.delete(`/categories/${id}`);
    },

    // Inventory Locations & Stock
    async getInventory(params = {}) {
      return window.API.get('/inventory/stock', params);
    },

    async getInventoryMovements(params = {}) {
      return window.API.get('/inventory/movements', params);
    },

    async createStockTransfer(data) {
      return window.API.post('/inventory/transfers', data);
    },

    async getStockTransfers(params = {}) {
      return window.API.get('/inventory/transfers', params);
    },

    async createStockAdjustment(data) {
      return window.API.post('/inventory/adjustments', data);
    },

    async getStockAdjustments(params = {}) {
      return window.API.get('/inventory/adjustments', params);
    },

    async createStockCount(data) {
      return window.API.post('/inventory/counts', data);
    },

    async getStockCounts(params = {}) {
      return window.API.get('/inventory/counts', params);
    },

    // Suppliers & Procurement
    async getSuppliers() {
      return window.API.get('/suppliers');
    },

    async createSupplier(data) {
      return window.API.post('/suppliers', data);
    },

    async updateSupplier(id, data) {
      return window.API.put(`/suppliers/${id}`, data);
    },

    async getPurchaseOrders(params = {}) {
      return window.API.get('/purchase-orders', params);
    },

    async createPurchaseOrder(data) {
      return window.API.post('/purchase-orders', data);
    },

    async receivePurchaseOrder(id, data) {
      return window.API.post(`/purchase-orders/${id}/receive`, data);
    },

    // Orders Management & Fulfilment
    async getOrders(params = {}) {
      return window.API.get('/orders', params);
    },

    async getOrder(id) {
      return window.API.get(`/orders/${id}`);
    },

    async updateOrderStatus(id, status, notes = '') {
      return window.API.patch(`/orders/${id}/status`, { status, notes });
    },

    async updateOrderFulfilment(id, carrier, trackingNumber) {
      return window.API.post(`/orders/${id}/fulfill`, { carrier, tracking_number: trackingNumber });
    },

    // Customers & Wholesale
    async getCustomers(params = {}) {
      return window.API.get('/customers', params);
    },

    async getCustomer(id) {
      return window.API.get(`/customers/${id}`);
    },

    async eraseCustomerData(id) {
      return window.API.post(`/customers/${id}/erase-data`);
    },

    async getWholesaleEnquiries() {
      return window.API.get('/wholesale/enquiries');
    },

    async approveWholesaleEnquiry(id, tier = 'standard') {
      return window.API.post(`/wholesale/enquiries/${id}/approve`, { tier });
    },

    // Payments & Bank Verification
    async getPayments(params = {}) {
      return window.API.get('/payments', params);
    },

    async getPendingBankTransfers() {
      return window.API.get('/payments/bank-transfers/pending');
    },

    async verifyBankTransfer(id, status, rejectionReason = '') {
      return window.API.post(`/payments/bank-transfers/${id}/verify`, {
        status: status, // 'approved' or 'rejected'
        rejection_reason: rejectionReason
      });
    },

    // Delivery Zones
    async getDeliveryZones() {
      return window.API.get('/delivery/zones');
    },

    async createDeliveryZone(data) {
      return window.API.post('/delivery/zones', data);
    },

    // Returns & Refunds
    async getReturns(params = {}) {
      return window.API.get('/returns', params);
    },

    async processRefund(paymentId, amount, reason = '') {
      return window.API.post('/payments/refunds', { payment_id: paymentId, amount, reason });
    },

    // Discounts & Promotions
    async getDiscounts() {
      return window.API.get('/marketing/discounts');
    },

    async createDiscount(data) {
      return window.API.post('/marketing/discounts', data);
    },

    async deleteDiscount(id) {
      return window.API.delete(`/marketing/discounts/${id}`);
    },

    // Expenses
    async getExpenses(params = {}) {
      return window.API.get('/expenses', params);
    },

    async createExpense(data) {
      return window.API.post('/expenses', data);
    },

    // Reports
    async getReport(type, params = {}) {
      return window.API.get(`/reports/${type}`, params);
    },

    getReportExportUrl(type, format = 'csv') {
      const token = window.Auth ? window.Auth.getToken() : '';
      return `${window.API.getBaseUrl()}/reports/${type}/export?format=${format}&token=${encodeURIComponent(token)}`;
    },

    // Employees & Permissions
    async getEmployees() {
      return window.API.get('/employees');
    },

    async createEmployee(data) {
      return window.API.post('/employees', data);
    },

    async updateEmployee(id, data) {
      return window.API.put(`/employees/${id}`, data);
    },

    // Notifications & WhatsApp
    async getNotificationQueue() {
      return window.API.get('/notifications/queue');
    },

    async processNotificationQueue() {
      return window.API.post('/notifications/process');
    },

    async testWhatsApp(phone) {
      return window.API.post('/notifications/test-whatsapp', { phone });
    },

    // Settings & Maintenance
    async getBusinessSettings() {
      return window.API.get('/settings');
    },

    async updateBusinessSettings(data) {
      return window.API.put('/settings', data);
    },

    async getAuditLogs(params = {}) {
      return window.API.get('/audit-logs', params);
    },

    async triggerBackup() {
      return window.API.post('/maintenance/backup');
    }
  };

  window.ErpAPI = ErpAPI;
})(window);
