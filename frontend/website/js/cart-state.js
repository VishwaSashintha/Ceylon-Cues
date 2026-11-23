/**
 * Ceylon Cues - Shopping Cart State Manager
 * Handles local cart storage and syncing with backend /api/v1/cart.
 */
(function(window) {
  'use strict';

  const CART_KEY = 'ceylon_cues_cart';

  const CartState = {
    items: [],

    init() {
      try {
        const stored = localStorage.getItem(CART_KEY);
        this.items = stored ? JSON.parse(stored) : [];
      } catch (e) {
        this.items = [];
      }
      this.syncWithBackend();
      this.broadcast();
    },

    getItems() {
      return this.items;
    },

    getCount() {
      return this.items.reduce((sum, item) => sum + (item.quantity || 1), 0);
    },

    getSubtotal() {
      return this.items.reduce((sum, item) => sum + ((item.unit_price || 0) * (item.quantity || 1)), 0);
    },

    async addItem(product, quantity = 1, variantId = null) {
      quantity = parseInt(quantity, 10) || 1;
      
      const existingIndex = this.items.findIndex(i => 
        i.product_id === product.id && (variantId ? i.variant_id === variantId : true)
      );

      if (existingIndex > -1) {
        this.items[existingIndex].quantity += quantity;
      } else {
        this.items.push({
          id: 'cart_' + Date.now() + '_' + Math.random().toString(36).substr(2, 4),
          product_id: product.id,
          variant_id: variantId,
          name: product.name,
          sku: product.sku || '',
          quality_tier: product.quality_tier || 'standard',
          unit_price: parseFloat(product.retail_price || product.price || 0),
          image_url: product.image_url || (product.images && product.images[0] ? product.images[0].image_url : ''),
          quantity: quantity,
          max_stock: product.online_allocation !== undefined ? product.online_allocation : 99
        });
      }

      this.save();

      // If logged in, sync with backend cart API
      if (window.Auth && window.Auth.isAuthenticated()) {
        try {
          await window.API.post('/cart/items', {
            product_id: product.id,
            variant_id: variantId,
            quantity: quantity
          });
        } catch (e) {
          console.warn('[Cart] Backend sync failed, kept in local cart:', e.message);
        }
      }

      if (window.Utils) {
        window.Utils.showToast(`Added "${product.name}" to your cart!`, 'success');
      }
    },

    async updateQuantity(cartItemId, newQty) {
      newQty = parseInt(newQty, 10);
      if (newQty <= 0) {
        return this.removeItem(cartItemId);
      }

      const item = this.items.find(i => i.id === cartItemId || i.product_id === cartItemId);
      if (item) {
        item.quantity = newQty;
        this.save();

        if (window.Auth && window.Auth.isAuthenticated() && item.backend_cart_item_id) {
          try {
            await window.API.put(`/cart/items/${item.backend_cart_item_id}`, { quantity: newQty });
          } catch (e) {
            console.warn('[Cart] Backend update error:', e);
          }
        }
      }
    },

    async removeItem(cartItemId) {
      const idx = this.items.findIndex(i => i.id === cartItemId || i.product_id === cartItemId);
      if (idx > -1) {
        const removed = this.items.splice(idx, 1)[0];
        this.save();

        if (window.Auth && window.Auth.isAuthenticated() && removed.backend_cart_item_id) {
          try {
            await window.API.delete(`/cart/items/${removed.backend_cart_item_id}`);
          } catch (e) {
            console.warn('[Cart] Backend remove error:', e);
          }
        }

        if (window.Utils) {
          window.Utils.showToast('Item removed from cart', 'info');
        }
      }
    },

    clear() {
      this.items = [];
      localStorage.removeItem(CART_KEY);
      this.broadcast();

      if (window.Auth && window.Auth.isAuthenticated()) {
        window.API.delete('/cart').catch(() => {});
      }
    },

    save() {
      localStorage.setItem(CART_KEY, JSON.stringify(this.items));
      this.broadcast();
    },

    broadcast() {
      window.dispatchEvent(new CustomEvent('ceylon:cart_updated', {
        detail: {
          items: this.items,
          count: this.getCount(),
          subtotal: this.getSubtotal()
        }
      }));

      // Update cart badges in DOM
      document.querySelectorAll('.cc-cart-badge').forEach(el => {
        el.textContent = this.getCount();
      });
      document.querySelectorAll('.cc-cart-total').forEach(el => {
        if (window.Utils) el.textContent = window.Utils.formatLKR(this.getSubtotal());
      });
    },

    async syncWithBackend() {
      if (window.Auth && window.Auth.isAuthenticated() && window.API) {
        try {
          const backendCart = await window.API.get('/cart');
          if (backendCart && Array.isArray(backendCart.items)) {
            this.items = backendCart.items.map(item => ({
              id: 'cart_' + item.id,
              backend_cart_item_id: item.id,
              product_id: item.product_id,
              variant_id: item.variant_id,
              name: item.product_name,
              sku: item.sku,
              quality_tier: item.quality_tier || 'standard',
              unit_price: parseFloat(item.unit_price),
              image_url: item.image_url || '',
              quantity: item.quantity,
              max_stock: item.available_quantity || 99
            }));
            localStorage.setItem(CART_KEY, JSON.stringify(this.items));
            this.broadcast();
          }
        } catch (e) {
          // Fall back to local
        }
      }
    }
  };

  CartState.init();
  window.CartState = CartState;
})(window);
