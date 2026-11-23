/**
 * Ceylon Cues - Common Utilities
 * Formatters, toast notifications, dialogs, pagination renderer.
 */
(function(window) {
  'use strict';

  const Utils = {
    /**
     * Formats number as Sri Lankan Rupee currency (LKR)
     */
    formatLKR(amount) {
      if (amount === null || amount === undefined || isNaN(amount)) return 'LKR 0.00';
      const num = Number(amount);
      return 'LKR ' + num.toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });
    },

    /**
     * Formats ISO date string
     */
    formatDate(dateString, includeTime = false) {
      if (!dateString) return '-';
      const date = new Date(dateString);
      if (isNaN(date.getTime())) return dateString;
      
      const options = {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
      };

      if (includeTime) {
        options.hour = '2-digit';
        options.minute = '2-digit';
        options.hour12 = true;
      }

      return date.toLocaleDateString('en-LK', options);
    },

    /**
     * Creates or retrieves the Toast container
     */
    getToastContainer() {
      let container = document.getElementById('cc-toast-container');
      if (!container) {
        container = document.createElement('div');
        container.id = 'cc-toast-container';
        document.body.appendChild(container);
      }
      return container;
    },

    /**
     * Shows a toast notification
     */
    showToast(message, type = 'info', duration = 3500) {
      const container = this.getToastContainer();
      const toast = document.createElement('div');
      toast.className = `cc-toast ${type}`;
      
      let icon = 'ℹ️';
      if (type === 'success') icon = '✓';
      if (type === 'error') icon = '⚠️';
      
      toast.innerHTML = `<span style="font-weight:700">${icon}</span> <span>${this.escapeHtml(message)}</span>`;
      container.appendChild(toast);

      setTimeout(() => {
        toast.style.transition = 'all 0.3s ease-out';
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(50px)';
        setTimeout(() => toast.remove(), 300);
      }, duration);
    },

    /**
     * Shows a custom confirm modal
     */
    showConfirm(title, message, onConfirm, confirmText = 'Confirm', isDestructive = false) {
      const backdrop = document.createElement('div');
      backdrop.className = 'cc-modal-backdrop active';
      backdrop.innerHTML = `
        <div class="cc-modal" style="max-width: 440px;">
          <div class="cc-modal-header">
            <h3 style="font:700 18px var(--font-heading); margin:0;">${this.escapeHtml(title)}</h3>
            <button class="cc-modal-close" style="font-size:20px; line-height:1; cursor:pointer;">&times;</button>
          </div>
          <div class="cc-modal-body">
            <p style="color:var(--text-muted); font-size:14px; margin:0;">${this.escapeHtml(message)}</p>
          </div>
          <div class="cc-modal-footer">
            <button class="b bo cc-btn-cancel">Cancel</button>
            <button class="b ${isDestructive ? 'bg' : ''} cc-btn-confirm" ${isDestructive ? 'style="background:#DC2626; color:#fff;"' : ''}>${this.escapeHtml(confirmText)}</button>
          </div>
        </div>
      `;

      document.body.appendChild(backdrop);

      const close = () => {
        backdrop.classList.remove('active');
        setTimeout(() => backdrop.remove(), 200);
      };

      backdrop.querySelector('.cc-modal-close').onclick = close;
      backdrop.querySelector('.cc-btn-cancel').onclick = close;
      backdrop.querySelector('.cc-btn-confirm').onclick = () => {
        close();
        if (typeof onConfirm === 'function') onConfirm();
      };
    },

    /**
     * Debounce helper for search inputs
     */
    debounce(fn, delay = 300) {
      let timeout;
      return function(...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => fn.apply(this, args), delay);
      };
    },

    /**
     * Renders standard pagination HTML controls
     */
    renderPagination(pagination, onPageChangeName = 'changePage') {
      if (!pagination || pagination.total_pages <= 1) return '';
      
      const current = pagination.current_page || 1;
      const total = pagination.total_pages || 1;
      const count = pagination.total_items || 0;

      let buttons = '';
      for (let p = 1; p <= total; p++) {
        if (p === 1 || p === total || (p >= current - 1 && p <= current + 1)) {
          buttons += `<button class="cc-page-btn ${p === current ? 'active' : ''}" onclick="${onPageChangeName}(${p})">${p}</button>`;
        } else if (p === current - 2 || p === current + 2) {
          buttons += `<span style="padding: 0 4px; color:var(--text-muted)">...</span>`;
        }
      }

      return `
        <div class="cc-pagination">
          <div>Showing page <b>${current}</b> of <b>${total}</b> (${count} total)</div>
          <div class="cc-pagination-pages">
            <button class="cc-page-btn" ${!pagination.has_prev ? 'disabled style="opacity:0.4; cursor:not-allowed;"' : `onclick="${onPageChangeName}(${current - 1})"`}>&larr;</button>
            ${buttons}
            <button class="cc-page-btn" ${!pagination.has_next ? 'disabled style="opacity:0.4; cursor:not-allowed;"' : `onclick="${onPageChangeName}(${current + 1})"`}>&rarr;</button>
          </div>
        </div>
      `;
    },

    /**
     * Escapes HTML strings to prevent XSS
     */
    escapeHtml(str) {
      if (!str) return '';
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }
  };

  window.Utils = Utils;
})(window);
