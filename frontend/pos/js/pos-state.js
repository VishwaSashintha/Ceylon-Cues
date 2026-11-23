/**
 * Ceylon Cues - POS State Manager
 * Handles active sale cart, held sales, line item discounts, and tender calculation.
 */
(function(window) {
  'use strict';

  const POS_HOLD_KEY = 'ceylon_pos_held_sales';

  const PosState = {
    cartItems: [],
    discountAmount: 0,
    customerPhone: '',
    customerName: 'Walk-in Retail Customer',
    heldSales: [],

    init() {
      try {
        const stored = localStorage.getItem(POS_HOLD_KEY);
        this.heldSales = stored ? JSON.parse(stored) : [];
      } catch (e) {
        this.heldSales = [];
      }
    },

    getCart() {
      return this.cartItems;
    },

    getSubtotal() {
      return this.cartItems.reduce((sum, item) => sum + (item.price * item.quantity), 0);
    },

    getTotal() {
      return Math.max(0, this.getSubtotal() - this.discountAmount);
    },

    addItem(product, quantity = 1) {
      const existing = this.cartItems.find(i => i.product_id === product.id);
      if (existing) {
        existing.quantity += quantity;
      } else {
        this.cartItems.push({
          product_id: product.id,
          name: product.name,
          sku: product.sku || '',
          price: parseFloat(product.retail_price || product.price || 0),
          quantity: quantity,
          shop_stock: product.shop_stock !== undefined ? product.shop_stock : 10
        });
      }
      this.broadcast();
    },

    updateQuantity(productId, delta) {
      const item = this.cartItems.find(i => i.product_id === productId);
      if (item) {
        item.quantity += delta;
        if (item.quantity <= 0) {
          this.removeItem(productId);
        } else {
          this.broadcast();
        }
      }
    },

    removeItem(productId) {
      this.cartItems = this.cartItems.filter(i => i.product_id !== productId);
      this.broadcast();
    },

    setDiscount(amount) {
      this.discountAmount = Math.max(0, parseFloat(amount) || 0);
      this.broadcast();
    },

    clearCart() {
      this.cartItems = [];
      this.discountAmount = 0;
      this.customerPhone = '';
      this.customerName = 'Walk-in Retail Customer';
      this.broadcast();
    },

    holdSale() {
      if (this.cartItems.length === 0) return false;
      const held = {
        id: 'HOLD-' + Date.now(),
        time: new Date().toLocaleTimeString(),
        items: [...this.cartItems],
        discount: this.discountAmount,
        total: this.getTotal()
      };
      this.heldSales.push(held);
      localStorage.setItem(POS_HOLD_KEY, JSON.stringify(this.heldSales));
      this.clearCart();
      return true;
    },

    resumeHeldSale(heldId) {
      const idx = this.heldSales.findIndex(h => h.id === heldId);
      if (idx > -1) {
        const held = this.heldSales.splice(idx, 1)[0];
        localStorage.setItem(POS_HOLD_KEY, JSON.stringify(this.heldSales));
        this.cartItems = held.items;
        this.discountAmount = held.discount;
        this.broadcast();
        return true;
      }
      return false;
    },

    broadcast() {
      window.dispatchEvent(new CustomEvent('ceylon:pos_cart_updated', {
        detail: {
          items: this.cartItems,
          subtotal: this.getSubtotal(),
          discount: this.discountAmount,
          total: this.getTotal()
        }
      }));
    }
  };

  PosState.init();
  window.PosState = PosState;
})(window);
