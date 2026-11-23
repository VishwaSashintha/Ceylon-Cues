/**
 * Ceylon Cues - Website Shell (Header, Navigation, Topbar, Footer)
 * Injects consistent, dynamic brand navigation across all customer-facing store pages.
 */
(function() {
  'use strict';

  function getRootPath() {
    // Determine relative path depth
    const path = window.location.pathname;
    if (path.includes('/pages/')) {
      return '../';
    }
    return './';
  }

  const root = getRootPath();

  function renderShell() {
    // 1. Render Topbar
    const topbarMount = document.getElementById('cc-topbar-mount');
    if (topbarMount) {
      topbarMount.innerHTML = `
        <div class="cc-topbar">
          <span id="cc-topbar-text">Free delivery over LKR 15,000 · Island-wide delivery · Collect from our shop</span>
        </div>
      `;
    }

    // 2. Render Header
    const headerMount = document.getElementById('cc-header-mount');
    if (headerMount) {
      const activeNav = headerMount.getAttribute('data-active') || '';
      const user = window.Auth ? window.Auth.getUser() : null;
      const isAuth = Boolean(user);

      headerMount.innerHTML = `
        <header class="cc-header">
          <a href="${root}index.html" class="cc-brand">
            CEYLON <span>CUES</span>
          </a>
          <nav class="cc-nav">
            <a href="${root}index.html" class="${activeNav === 'home' ? 'active' : ''}">Home</a>
            <a href="${root}pages/products.html?category=cues" class="${activeNav === 'cues' ? 'active' : ''}">Cues</a>
            <a href="${root}pages/products.html?category=chalk" class="${activeNav === 'chalk' ? 'active' : ''}">Chalk & Tips</a>
            <a href="${root}pages/products.html" class="${activeNav === 'products' ? 'active' : ''}">Catalogue</a>
            <a href="${root}pages/wholesale.html" class="${activeNav === 'wholesale' ? 'active' : ''}">Wholesale</a>
            <a href="${root}pages/track-order.html" class="${activeNav === 'track' ? 'active' : ''}">Track Order</a>
            <a href="${root}pages/about.html" class="${activeNav === 'about' ? 'active' : ''}">About</a>
          </nav>
          <form class="cc-search-form" action="${root}pages/products.html" method="GET">
            <input type="text" name="q" class="cc-search-input" placeholder="Search cues, chalk, tips, SKU..." aria-label="Search">
            <button type="submit" class="cc-search-btn" title="Search">
              <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.35-4.35"></path></svg>
            </button>
          </form>
          <div class="cc-header-actions">
            ${isAuth ? `
              <div style="position:relative; display:inline-block;">
                <a href="${root}pages/account.html" class="b bo" style="height:44px; padding:0 16px; font-size:14px; border-radius:var(--radius-full);">
                  <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                  <span>${Utils.escapeHtml(user.name ? user.name.split(' ')[0] : 'Account')}</span>
                </a>
              </div>
            ` : `
              <a href="${root}pages/login.html" class="b bo" style="height:44px; padding:0 18px; font-size:14px; border-radius:var(--radius-full);">Sign In</a>
            `}
            <a href="${root}pages/cart.html" class="cc-cart-btn">
              <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
              <span>Cart</span>
              <span class="cc-cart-badge">${window.CartState ? window.CartState.getCount() : 0}</span>
            </a>
          </div>
        </header>
      `;
    }

    // 3. Render Features & Footer
    const footerMount = document.getElementById('cc-footer-mount');
    if (footerMount) {
      footerMount.innerHTML = `
        <section class="cc-features-banner">
          <div class="cc-features-item">
            <b>Pay Your Way</b>
            <p>Visa, Mastercard, KOKO, Mintpay, Direct Bank Transfer &amp; Cash on Delivery accepted.</p>
          </div>
          <div class="cc-features-item">
            <b>Delivery &amp; Collection</b>
            <p>Reliable island-wide door delivery across Sri Lanka or pick up directly from our retail shop.</p>
          </div>
          <div class="cc-features-item">
            <b>Order Updates</b>
            <p>Instant SMS, WhatsApp &amp; email notifications from preparation to final delivery.</p>
          </div>
        </section>

        <footer class="cc-footer">
          <div class="cc-footer-grid">
            <div class="cc-footer-col">
              <div class="cc-brand" style="color:#fff; margin-bottom:14px;">
                CEYLON <span style="color:var(--gold);">CUES</span>
              </div>
              <p style="font-size:14px; line-height:1.6; max-width:320px; color:var(--text-on-dark); margin-bottom:20px;">
                Sri Lanka's premier destination for precision billiards, pool cues, chalk, tips, and professional table accessories.
              </p>
              <div style="font-size:13px; color:var(--gold);">
                <p>📍 <span id="cc-footer-address">No. 45, Galle Road, Colombo 03, Sri Lanka</span></p>
                <p style="margin-top:6px;">📞 <span id="cc-footer-phone">+94 77 123 4567</span></p>
                <p style="margin-top:6px;">✉️ <span id="cc-footer-email">support@ceyloncues.lk</span></p>
              </div>
            </div>
            <div class="cc-footer-col">
              <h4>Shop Gear</h4>
              <ul>
                <li><a href="${root}pages/products.html?category=cues">Billiard &amp; Pool Cues</a></li>
                <li><a href="${root}pages/products.html?category=chalk">Cue Tips &amp; Chalk</a></li>
                <li><a href="${root}pages/products.html?category=cases">Cue Hard &amp; Soft Cases</a></li>
                <li><a href="${root}pages/products.html?category=maintenance">Maintenance Tools &amp; Glue</a></li>
                <li><a href="${root}pages/products.html?tier=premium">Premium Collection</a></li>
              </ul>
            </div>
            <div class="cc-footer-col">
              <h4>Customer Care</h4>
              <ul>
                <li><a href="${root}pages/track-order.html">Track Your Order</a></li>
                <li><a href="${root}pages/wholesale.html">Wholesale Inquiries</a></li>
                <li><a href="${root}pages/contact.html">Store Location &amp; Contact</a></li>
                <li><a href="${root}pages/policies.html">Shipping &amp; Return Policy</a></li>
                <li><a href="${root}pages/support.html">Help &amp; Support Tickets</a></li>
              </ul>
            </div>
            <div class="cc-footer-col">
              <h4>Quick Access</h4>
              <ul>
                <li><a href="${root}pages/login.html">Customer Sign In</a></li>
                <li><a href="${root}pages/account.html">My Account &amp; Orders</a></li>
                <li><a href="${root}pages/wishlist.html">Saved Wishlist</a></li>
                <li><a href="${root}../erp/index.html" style="color:var(--gold);">ERP Staff Portal &rarr;</a></li>
                <li><a href="${root}../pos/terminal.html" style="color:var(--gold);">POS Terminal &rarr;</a></li>
              </ul>
            </div>
          </div>
          <div class="cc-footer-bottom">
            <div>&copy; ${new Date().getFullYear()} Ceylon Cues (Pvt) Ltd. All rights reserved. Precision. Performance. Passion.</div>
            <div style="display:flex; gap:20px;">
              <a href="${root}pages/policies.html#privacy">Privacy Policy</a>
              <a href="${root}pages/policies.html#terms">Terms of Service</a>
              <a href="${root}pages/policies.html#returns">Refund Policy</a>
            </div>
          </div>
        </footer>
      `;
    }

    // 4. Load Public Settings & update placeholders dynamically
    if (window.WebsiteAPI) {
      window.WebsiteAPI.getSettings().then(settings => {
        if (!settings) return;
        if (settings.free_delivery_min_order) {
          const formatted = Utils.formatLKR(settings.free_delivery_min_order);
          const topbarText = document.getElementById('cc-topbar-text');
          if (topbarText) {
            topbarText.textContent = `Free delivery over ${formatted} · Island-wide delivery · Collect from our shop`;
          }
        }
        if (settings.shop_address) {
          const addr = document.getElementById('cc-footer-address');
          if (addr) addr.textContent = settings.shop_address;
        }
        if (settings.support_phone) {
          const ph = document.getElementById('cc-footer-phone');
          if (ph) ph.textContent = settings.support_phone;
        }
        if (settings.support_email) {
          const em = document.getElementById('cc-footer-email');
          if (em) em.textContent = settings.support_email;
        }
      }).catch(err => {
        console.warn('[SiteShell] Could not load settings:', err);
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', renderShell);
  } else {
    renderShell();
  }
})();
