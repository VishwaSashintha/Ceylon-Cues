/**
 * Ceylon Cues - POS API Service Layer
 * Connects directly to backend physical retail POS endpoints.
 */
(function(window) {
  'use strict';

  const PosAPI = {
    // Staff PIN verification for fast counter access
    async verifyPin(pin) {
      return window.API.post('/pos/verify-pin', { pin });
    },

    // Session / Shift Management
    async openShift(openingFloat) {
      return window.API.post('/pos/sessions/open', { opening_float: openingFloat });
    },

    async closeShift(sessionId, closingCash, notes = '') {
      return window.API.post(`/pos/sessions/${sessionId}/close`, {
        counted_cash: closingCash,
        notes: notes
      });
    },

    async getCurrentSession() {
      return window.API.get('/pos/sessions/current');
    },

    async getDailySummary(sessionId) {
      return window.API.get(`/pos/sessions/${sessionId}/summary`);
    },

    // POS Products (Uses physical shop inventory location_id = 2)
    async getShopProducts(params = {}) {
      return window.API.get('/products', {
        ...params,
        location_id: 2, // Physical Retail Shop stock!
        in_stock: 0
      });
    },

    async scanBarcode(barcode) {
      return window.API.get('/products/barcode', { barcode });
    },

    // Sales Transactions
    async completeSale(salePayload) {
      return window.API.post('/pos/sales', salePayload);
    },

    async getReceipt(saleId) {
      return window.API.get(`/pos/receipt/${saleId}`);
    },

    async getTransactions(sessionId) {
      return window.API.get('/pos/sales', { session_id: sessionId });
    },

    async voidSale(saleId, managerPin, reason = '') {
      return window.API.post(`/pos/sales/${saleId}/void`, {
        manager_pin: managerPin,
        reason: reason
      });
    },

    async processExchange(saleId, payload) {
      return window.API.post(`/pos/sales/${saleId}/exchange`, payload);
    },

    // Register Terminal
    async registerTerminal(deviceName) {
      return window.API.post('/pos/terminals/register', { device_name: deviceName });
    }
  };

  window.PosAPI = PosAPI;
})(window);
