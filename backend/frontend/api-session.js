/* Aqari Pro staging-only same-site Laravel Sanctum session client.
 * Not loaded by the public GitHub Pages demo.
 * Configure an HTTPS API on the same site with stateful CORS/CSRF/session settings.
 */
(function (root) {
  'use strict';

  function create(options) {
    const config = options || {};
    const fetcher = config.fetch || (typeof fetch !== 'undefined' ? fetch.bind(root) : null);
    if (typeof fetcher !== 'function') throw new Error('fetch is required');
    const base = String(config.baseUrl || '').replace(/\/$/, '');

    function url(path) { return base + path; }

    async function send(path, method, payload) {
      const options = {
        method: method || 'GET',
        credentials: 'include',
        headers: { Accept: 'application/json' },
      };
      if (payload !== undefined) {
        options.headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(payload);
      }
      // On same-site Laravel deployments, CSRF is provided via XSRF-TOKEN cookie.
      // The browser's cookie API can read that non-HttpOnly cookie but must never read
      // the authentication session cookie. Provide a cookie reader via configuration.
      if (options.method !== 'GET') {
        const csrf = config.csrfToken ? config.csrfToken() : null;
        if (csrf) options.headers['X-XSRF-TOKEN'] = decodeURIComponent(csrf);
      }
      const response = await fetcher(url(path), options);
      const result = await response.json().catch(() => null);
      if (!response.ok) {
        const error = new Error(result?.message || 'فشل اتصال API');
        error.status = response.status;
        error.errors = result?.errors || {};
        throw error;
      }
      return result;
    }

    async function upload(path, file) {
      const body = config.formDataFactory ? config.formDataFactory() : new FormData();
      body.append('file', file);
      const headers = {Accept: 'application/json'};
      const csrf = config.csrfToken ? config.csrfToken() : null;
      if (csrf) headers['X-XSRF-TOKEN'] = decodeURIComponent(csrf);
      // Never set Content-Type on multipart: browser must add its boundary.
      const response = await fetcher(url(path), {
        method: 'POST', credentials: 'include', headers, body,
      });
      const result = await response.json().catch(() => null);
      if (!response.ok) {
        const error = new Error(result?.message || 'فشل رفع الملف');
        error.status = response.status;
        error.errors = result?.errors || {};
        throw error;
      }
      return result;
    }

    return Object.freeze({
      async login(email, password) {
        await send('/sanctum/csrf-cookie', 'GET');
        return send('/login', 'POST', { email, password });
      },
      me() { return send('/api/v1/me', 'GET'); },
      account() { return send('/api/v1/account', 'GET'); },
      updateAccount(data) { return send('/api/v1/account', 'PATCH', data); },
      changePassword(data) { return send('/api/v1/account/change-password', 'POST', data); },
      async requestPasswordReset(email) {
        await send('/sanctum/csrf-cookie', 'GET');
        return send('/forgot-password', 'POST', {email});
      },
      async resetPassword(data) {
        await send('/sanctum/csrf-cookie', 'GET');
        return send('/reset-password', 'POST', data);
      },
      properties(page=1) { return send('/api/v1/properties?page=' + encodeURIComponent(page), 'GET'); },
      createProperty(data) { return send('/api/v1/properties', 'POST', data); },
      updateProperty(id, data) { return send('/api/v1/properties/' + encodeURIComponent(id), 'PATCH', data); },
      units(propertyId, page=1) { return send('/api/v1/properties/' + encodeURIComponent(propertyId) + '/units?page=' + encodeURIComponent(page), 'GET'); },
      createUnit(propertyId, data) { return send('/api/v1/properties/' + encodeURIComponent(propertyId) + '/units', 'POST', data); },
      updateUnit(propertyId, id, data) { return send('/api/v1/properties/' + encodeURIComponent(propertyId) + '/units/' + encodeURIComponent(id), 'PATCH', data); },
      tenants(page=1) { return send('/api/v1/tenants?page=' + encodeURIComponent(page), 'GET'); },
      createTenant(data) { return send('/api/v1/tenants', 'POST', data); },
      updateTenant(id, data) { return send('/api/v1/tenants/' + encodeURIComponent(id), 'PATCH', data); },
      contracts(page=1) { return send('/api/v1/contracts?page=' + encodeURIComponent(page), 'GET'); },
      contract(id) { return send('/api/v1/contracts/' + encodeURIComponent(id), 'GET'); },
      createContract(data) { return send('/api/v1/contracts', 'POST', data); },
      payments(page=1) { return send('/api/v1/payments?page=' + encodeURIComponent(page), 'GET'); },
      payment(id) { return send('/api/v1/payments/' + encodeURIComponent(id), 'GET'); },
      postPayment(dueId, data) { return send('/api/v1/dues/' + encodeURIComponent(dueId) + '/payments', 'POST', data); },
      reversePayment(id, reason) { return send('/api/v1/payments/' + encodeURIComponent(id) + '/reverse', 'POST', {reason}); },
      receivables(propertyId) {
        return send('/api/v1/reports/receivables' + (propertyId ? '?property_id=' + encodeURIComponent(propertyId) : ''), 'GET');
      },
      propertyAttachments(propertyId, page=1) {
        return send('/api/v1/properties/' + encodeURIComponent(propertyId) + '/attachments?page=' + encodeURIComponent(page), 'GET');
      },
      contractAttachments(contractId, page=1) {
        return send('/api/v1/contracts/' + encodeURIComponent(contractId) + '/attachments?page=' + encodeURIComponent(page), 'GET');
      },
      uploadPropertyAttachment(propertyId, file) {
        return upload('/api/v1/properties/' + encodeURIComponent(propertyId) + '/attachments', file);
      },
      uploadContractAttachment(contractId, file) {
        return upload('/api/v1/contracts/' + encodeURIComponent(contractId) + '/attachments', file);
      },
      attachmentDownloadUrl(id) {
        return url('/api/v1/attachments/' + encodeURIComponent(id) + '/download');
      },
      logout() { return send('/logout', 'POST'); },
    });
  }

  root.AqariApiSession = Object.freeze({ create });
})(typeof window !== 'undefined' ? window : globalThis);
