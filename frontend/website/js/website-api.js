/**
 * Ceylon Cues - Website API Layer
 */
(function(window) {
  'use strict';

  const WebsiteAPI = {
    // Public Settings
    async getSettings() {
      return window.API.get('/settings/public');
    },

    // Categories
    async getCategories() {
      return window.API.get('/categories');
    },

    // Banners
    async getBanners() {
      return window.API.get('/banners');
    },

    // Products Catalogue
    async getProducts(params = {}) {
      return window.API.get('/products', params);
    },

    // Single Product Detail
    async getProduct(idOrSlug) {
      return window.API.get(`/products/${idOrSlug}`);
    },

    // Reviews for Product
    async getProductReviews(productId, params = {}) {
      return window.API.get(`/products/${productId}/reviews`, params);
    },

    async submitReview(productId, data) {
      return window.API.post(`/products/${productId}/reviews`, data);
    },

    // Delivery Calculation
    async calculateDelivery(data) {
      return window.API.post('/delivery/calculate', data);
    },

    // Coupons
    async validateCoupon(code, subtotal) {
      return window.API.post('/marketing/discounts/validate', { code, subtotal });
    },

    // Checkout Calculation & Order Placement
    async calculateCheckout(payload) {
      return window.API.post('/checkout/calculate', payload);
    },

    async requestOtp(payload) {
      return window.API.post('/checkout/request-otp', payload);
    },

    async placeOrder(payload) {
      return window.API.post('/checkout/place-order', payload);
    },

    // Customer Auth & Profile
    async login(email, password, otp = '') {
      const payload = { email, password };
      if (otp) payload.totp_code = otp;
      return window.API.post('/auth/login', payload);
    },

    async register(data) {
      return window.API.post('/auth/register', data);
    },

    async getProfile() {
      return window.API.get('/auth/me');
    },

    async updateProfile(data) {
      return window.API.put('/auth/profile', data);
    },

    // Customer Orders
    async getMyOrders(params = {}) {
      return window.API.get('/orders', params);
    },

    async getOrder(orderId) {
      return window.API.get(`/orders/${orderId}`);
    },

    async trackOrder(tokenOrQuery) {
      return window.API.get('/orders/track', { token: tokenOrQuery });
    },

    // Wholesale Inquiry
    async submitWholesaleEnquiry(data) {
      return window.API.post('/wholesale/enquiry', data);
    },

    // Contact / Tickets
    async createSupportTicket(data) {
      return window.API.post('/support/tickets', data);
    },

    // Wishlist
    async getWishlist() {
      return window.API.get('/wishlist');
    },

    async addToWishlist(productId) {
      return window.API.post('/wishlist', { product_id: productId });
    },

    async removeFromWishlist(productId) {
      return window.API.delete(`/wishlist/${productId}`);
    }
  };

  window.WebsiteAPI = WebsiteAPI;
})(window);
