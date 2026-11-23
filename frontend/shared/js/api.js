/**
 * Ceylon Cues - Central API Client
 * Connects to the backend REST API with unified error handling, token injection, and response parsing.
 */
(function(window) {
  'use strict';

  // Configurable API Base URL
  const DEFAULT_API_BASE = window.location.origin.includes('localhost') 
    ? 'http://localhost/ceylon-cues/api/v1' 
    : '/ceylon-cues/api/v1';

  const ApiClient = {
    baseUrl: window.CEYLON_API_BASE || DEFAULT_API_BASE,

    setBaseUrl(url) {
      this.baseUrl = url.replace(/\/+$/, '');
    },

    getBaseUrl() {
      return this.baseUrl;
    },

    /**
     * Resolves full asset/image URL from backend path
     */
    getImageUrl(path) {
      if (!path) return '';
      if (path.startsWith('http://') || path.startsWith('https://')) return path;
      const clean = path.replace(/^\/+/, '');
      const host = this.baseUrl.replace(/\/api\/v1\/?$/, '');
      return `${host}/${clean}`;
    },

    async request(endpoint, options = {}) {
      const url = new URL(
        endpoint.startsWith('http') ? endpoint : `${this.baseUrl}/${endpoint.replace(/^\/+/, '')}`
      );

      // Append query params if GET or specified
      if (options.params) {
        Object.keys(options.params).forEach(key => {
          if (options.params[key] !== undefined && options.params[key] !== null && options.params[key] !== '') {
            url.searchParams.append(key, options.params[key]);
          }
        });
      }

      const headers = {
        'Accept': 'application/json',
        ...(options.headers || {})
      };

      // Inject Token if available
      const token = window.Auth ? window.Auth.getToken() : localStorage.getItem('ceylon_cues_token');
      if (token) {
        headers['Authorization'] = `Bearer ${token}`;
        headers['X-Session-Token'] = token;
      }

      // Check if body is FormData or JSON
      const isFormData = options.body instanceof FormData;
      if (!isFormData && options.body && typeof options.body === 'object') {
        headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(options.body);
      }

      const fetchOptions = {
        method: options.method || 'GET',
        headers,
        body: options.body,
        credentials: 'omit'
      };

      try {
        const response = await fetch(url.toString(), fetchOptions);
        let result;
        const text = await response.text();

        try {
          result = JSON.parse(text);
        } catch (e) {
          result = {
            success: response.ok,
            message: text || `HTTP ${response.status}: ${response.statusText}`,
            data: null
          };
        }

        if (!response.ok || (result && result.success === false)) {
          // Handle 401 Unauthorized
          if (response.status === 401) {
            window.dispatchEvent(new CustomEvent('ceylon:unauthorized', { detail: result }));
          }

          const error = new Error(result.message || `Request failed with status ${response.status}`);
          error.status = response.status;
          error.errors = result.errors || null;
          error.data = result.data || null;
          throw error;
        }

        // Return unwrapped payload
        return result.data !== undefined ? result.data : result;
      } catch (err) {
        console.error(`[API Error] ${options.method || 'GET'} ${url.pathname}:`, err);
        throw err;
      }
    },

    get(endpoint, params, headers) {
      return this.request(endpoint, { method: 'GET', params, headers });
    },

    post(endpoint, body, headers) {
      return this.request(endpoint, { method: 'POST', body, headers });
    },

    put(endpoint, body, headers) {
      return this.request(endpoint, { method: 'PUT', body, headers });
    },

    patch(endpoint, body, headers) {
      return this.request(endpoint, { method: 'PATCH', body, headers });
    },

    delete(endpoint, body, headers) {
      return this.request(endpoint, { method: 'DELETE', body, headers });
    },

    upload(endpoint, formData, headers = {}) {
      return this.request(endpoint, { method: 'POST', body: formData, headers });
    }
  };

  window.API = ApiClient;
})(window);
