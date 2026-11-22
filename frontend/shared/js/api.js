/**
 * Ceylon Cues — Master Frontend API Client & State Management
 * Connects Customer Storefront, Owner ERP, and Shop POS to backend /api/v1/
 */

(function (window) {
  'use strict';

  // Base API URL Resolution
  function getApiBase() {
    if (window.CEYLON_API_BASE) {
      return window.CEYLON_API_BASE;
    }
    const path = window.location.pathname;
    const feIndex = path.indexOf('/frontend');
    if (feIndex !== -1) {
      return window.location.origin + path.substring(0, feIndex) + '/backend/public/api/v1';
    }
    const pubIndex = path.indexOf('/public');
    if (pubIndex !== -1) {
      return window.location.origin + path.substring(0, pubIndex + 7) + '/api/v1';
    }
    // If root or custom domain
    return window.location.origin + '/backend/public/api/v1';
  }

  const API_BASE = getApiBase();

  function getBackendBase() {
    return API_BASE.replace(/\/api\/v1\/?$/, '');
  }

  function getImageUrl(filePath) {
    if (!filePath) return '../shared/img/ceylon-cues-logo.png';
    if (filePath.startsWith('http://') || filePath.startsWith('https://') || filePath.startsWith('data:')) {
      return filePath;
    }
    const cleanPath = filePath.replace(/^\/+/, '');
    return getBackendBase() + '/' + cleanPath;
  }

  // Session ID generation for persistent guest carts
  function getSessionId() {
    let sid = localStorage.getItem('ceylon_cues_session_id');
    if (!sid) {
      sid = 'sid_' + Math.random().toString(36).substring(2, 15) + '_' + Date.now();
      localStorage.setItem('ceylon_cues_session_id', sid);
    }
    return sid;
  }

  // Token & User Persistence
  function getToken() {
    return localStorage.getItem('ceylon_cues_token');
  }

  function setToken(token) {
    if (token) {
      localStorage.setItem('ceylon_cues_token', token);
    } else {
      localStorage.removeItem('ceylon_cues_token');
    }
  }

  function getUser() {
    try {
      const u = localStorage.getItem('ceylon_cues_user');
      return u ? JSON.parse(u) : null;
    } catch (e) {
      return null;
    }
  }

  function setUser(user) {
    if (user) {
      localStorage.setItem('ceylon_cues_user', JSON.stringify(user));
    } else {
      localStorage.removeItem('ceylon_cues_user');
    }
  }

  function clearAuth() {
    localStorage.removeItem('ceylon_cues_token');
    localStorage.removeItem('ceylon_cues_user');
  }

  // Currency & Date Formatters
  function formatLKR(amount) {
    const num = parseFloat(amount || 0);
    return 'LKR ' + num.toLocaleString('en-LK', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
  }

  function formatDate(dateStr) {
    if (!dateStr) return '—';
    try {
      const d = new Date(dateStr.replace(' ', 'T'));
      return d.toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
      });
    } catch (e) {
      return dateStr;
    }
  }

  // Toast Notification System
  function showToast(message, type = 'success') {
    let container = document.getElementById('toast-container');
    if (!container) {
      container = document.createElement('div');
      container.id = 'toast-container';
      document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    
    // Choose icon
    let icon = '✓';
    if (type === 'error') icon = '✕';
    else if (type === 'warning') icon = '⚠';
    else if (type === 'info') icon = 'ℹ';

    toast.innerHTML = `
      <span style="font-weight: 800; font-size: 1.1rem; line-height: 1;">${icon}</span>
      <div style="flex: 1;">${message}</div>
    `;

    container.appendChild(toast);

    setTimeout(() => {
      toast.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
      toast.style.opacity = '0';
      toast.style.transform = 'translateY(10px)';
      setTimeout(() => toast.remove(), 300);
    }, 4000);
  }

  // Modal Dialog System
  function openModal(id) {
    const el = document.getElementById(id);
    if (el) {
      el.classList.add('active');
      document.body.style.overflow = 'hidden';
    }
  }

  function closeModal(id) {
    const el = document.getElementById(id);
    if (el) {
      el.classList.remove('active');
      document.body.style.overflow = '';
    }
  }

  // Core API Request Helper
  async function request(endpoint, options = {}) {
    const method = options.method || 'GET';
    const headers = {
      'Accept': 'application/json',
      'X-Session-ID': getSessionId(),
      ...(options.headers || {}),
    };

    const token = getToken();
    if (token) {
      headers['Authorization'] = `Bearer ${token}`;
    }

    let body = options.body;
    if (body && typeof body === 'object' && !(body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(body);
    }

    const cleanEndpoint = endpoint.startsWith('/') ? endpoint : `/${endpoint}`;
    const url = `${API_BASE}${cleanEndpoint}`;

    try {
      const res = await fetch(url, {
        method,
        headers,
        body,
      });

      const json = await res.json().catch(() => null);

      if (!res.ok) {
        if (res.status === 401) {
          // Token expired or invalid
          if (token && !endpoint.includes('/auth/login')) {
            clearAuth();
            showToast('Session expired. Please log in again.', 'warning');
          }
        }
        const errMessage = json?.message || `HTTP ${res.status}: ${res.statusText}`;
        const err = new Error(errMessage);
        err.status = res.status;
        err.data = json;
        throw err;
      }

      return json;
    } catch (err) {
      console.error(`[API Error] ${method} ${cleanEndpoint}:`, err);
      throw err;
    }
  }

  // Cart State & Cart Operations
  let cartListeners = [];
  function onCartUpdate(fn) {
    cartListeners.push(fn);
  }

  function emitCartUpdate(cartData) {
    cartListeners.forEach(fn => fn(cartData));
  }

  async function getCart() {
    try {
      const res = await request('/cart');
      emitCartUpdate(res.data);
      return res.data;
    } catch (e) {
      return { items: [], subtotal: 0, item_count: 0 };
    }
  }

  async function addToCart(productId, quantity = 1, variantId = null) {
    const res = await request('/cart/items', {
      method: 'POST',
      body: {
        product_id: parseInt(productId, 10),
        quantity: parseInt(quantity, 10),
        variant_id: variantId ? parseInt(variantId, 10) : null,
      },
    });
    showToast('Product added to your cart!', 'success');
    emitCartUpdate(res.data);
    return res.data;
  }

  async function updateCartItem(itemId, quantity) {
    const res = await request(`/cart/items/${itemId}`, {
      method: 'PUT',
      body: { quantity: parseInt(quantity, 10) },
    });
    emitCartUpdate(res.data);
    return res.data;
  }

  async function removeCartItem(itemId) {
    const res = await request(`/cart/items/${itemId}`, {
      method: 'DELETE',
    });
    showToast('Item removed from cart', 'info');
    emitCartUpdate(res.data);
    return res.data;
  }

  async function clearCart() {
    const res = await request('/cart', { method: 'DELETE' });
    emitCartUpdate(res.data);
    return res.data;
  }

  // Authentication Helpers
  async function login(email, password) {
    const res = await request('/auth/login', {
      method: 'POST',
      body: { email, password },
    });
    setToken(res.data.token);
    setUser(res.data.user);
    showToast(`Welcome back, ${res.data.user.first_name}!`, 'success');
    return res.data;
  }

  async function register(data) {
    const res = await request('/auth/register', {
      method: 'POST',
      body: data,
    });
    showToast('Registration successful! Please log in.', 'success');
    return res.data;
  }

  async function logout() {
    try {
      await request('/auth/logout', { method: 'POST' });
    } catch (e) {
      // Continue client cleanup even if network fails
    }
    clearAuth();
    showToast('You have been logged out.', 'info');
    setTimeout(() => {
      window.location.reload();
    }, 500);
  }

  async function getMe() {
    const token = getToken();
    if (!token) return null;
    try {
      const res = await request('/auth/me');
      setUser(res.data);
      return res.data;
    } catch (e) {
      clearAuth();
      return null;
    }
  }

  // Expose Ceylon API globally
  window.CeylonAPI = {
    BASE_URL: API_BASE,
    request,
    get: (url) => request(url, { method: 'GET' }),
    post: (url, body) => request(url, { method: 'POST', body }),
    put: (url, body) => request(url, { method: 'PUT', body }),
    delete: (url) => request(url, { method: 'DELETE' }),
    upload: (url, formData) => request(url, { method: 'POST', body: formData }),

    // Auth
    getToken,
    getUser,
    login,
    register,
    logout,
    getMe,
    isLoggedIn: () => !!getToken(),
    isOwner: () => getUser()?.role === 'owner',
    isStaff: () => {
      const u = getUser();
      return u && ['owner', 'shop_manager', 'shop_cashier', 'warehouse_manager', 'inventory_staff', 'order_staff', 'finance_staff'].includes(u.role);
    },
    isPosAuthorized: () => {
      const u = getUser();
      return u && ['owner', 'shop_manager', 'shop_cashier'].includes(u.role);
    },

    // Cart
    getCart,
    addToCart,
    updateCartItem,
    removeCartItem,
    clearCart,
    onCartUpdate,

    // UI helpers
    showToast,
    openModal,
    closeModal,
    formatLKR,
    formatDate,
    getImageUrl,
    getBackendBase,
  };

  // Close modals on backdrop click or ESC
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.modal-backdrop').forEach((backdrop) => {
      backdrop.addEventListener('click', (e) => {
        if (e.target === backdrop) {
          backdrop.classList.remove('active');
          document.body.style.overflow = '';
        }
      });
    });

    document.querySelectorAll('[data-close-modal]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const modal = btn.closest('.modal-backdrop');
        if (modal) {
          modal.classList.remove('active');
          document.body.style.overflow = '';
        }
      });
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        document.querySelectorAll('.modal-backdrop.active').forEach((modal) => {
          modal.classList.remove('active');
        });
        document.body.style.overflow = '';
      }
    });
  });

})(window);
