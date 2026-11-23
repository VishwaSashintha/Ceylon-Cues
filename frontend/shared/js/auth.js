/**
 * Ceylon Cues - Authentication & Role Manager
 */
(function(window) {
  'use strict';

  const TOKEN_KEY = 'ceylon_cues_token';
  const USER_KEY = 'ceylon_cues_user';
  const POS_SHIFT_KEY = 'ceylon_pos_session';

  const Auth = {
    getToken() {
      return localStorage.getItem(TOKEN_KEY) || null;
    },

    getUser() {
      try {
        const u = localStorage.getItem(USER_KEY);
        return u ? JSON.parse(u) : null;
      } catch (e) {
        return null;
      }
    },

    isAuthenticated() {
      return Boolean(this.getToken());
    },

    getUserRole() {
      const u = this.getUser();
      return u ? (u.role || 'customer') : 'guest';
    },

    hasRole(...allowedRoles) {
      const currentRole = this.getUserRole();
      if (currentRole === 'owner') return true; // Owner has all permissions
      return allowedRoles.includes(currentRole);
    },

    isStaff() {
      const role = this.getUserRole();
      return ['owner', 'shop_manager', 'shop_cashier', 'warehouse_manager', 'inventory_staff', 'order_staff', 'customer_support', 'finance_staff'].includes(role);
    },

    login(token, user) {
      localStorage.setItem(TOKEN_KEY, token);
      localStorage.setItem(USER_KEY, JSON.stringify(user));
      window.dispatchEvent(new CustomEvent('ceylon:auth_change', { detail: { token, user } }));
    },

    updateUser(updates) {
      const current = this.getUser() || {};
      const updated = { ...current, ...updates };
      localStorage.setItem(USER_KEY, JSON.stringify(updated));
      return updated;
    },

    logout(redirectUrl) {
      const token = this.getToken();
      if (token && window.API) {
        // Fire and forget logout on backend
        window.API.post('/auth/logout').catch(() => {});
      }
      localStorage.removeItem(TOKEN_KEY);
      localStorage.removeItem(USER_KEY);
      window.dispatchEvent(new CustomEvent('ceylon:auth_change', { detail: null }));

      if (redirectUrl) {
        window.location.href = redirectUrl;
      }
    },

    // POS Session Management
    getPosSession() {
      try {
        const s = localStorage.getItem(POS_SHIFT_KEY);
        return s ? JSON.parse(s) : null;
      } catch (e) {
        return null;
      }
    },

    setPosSession(sessionData) {
      localStorage.setItem(POS_SHIFT_KEY, JSON.stringify(sessionData));
    },

    clearPosSession() {
      localStorage.removeItem(POS_SHIFT_KEY);
    }
  };

  // Listen to unauthorized events
  window.addEventListener('ceylon:unauthorized', () => {
    console.warn('[Auth] Session expired or invalid token.');
    const isErp = window.location.pathname.includes('/erp/');
    const isPos = window.location.pathname.includes('/pos/');
    
    // Clear storage
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);

    if (isErp || isPos) {
      const loginTarget = isPos ? '../pos/index.html' : '../website/pages/login.html?type=staff';
      window.location.href = loginTarget;
    }
  });

  window.Auth = Auth;
})(window);
