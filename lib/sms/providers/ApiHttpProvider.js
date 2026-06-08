'use strict';

class ApiHttpProvider {
  constructor(provider) {
    this.provider = provider || {};
  }

  async test() {
    return { ok: true, message: 'Provider HTTP API configuré.' };
  }

  buildRequestPayload(to, message) {
    const cfg = this.provider.config || {};
    const bodyTemplate = cfg.bodyTemplate || {
      account: cfg.account || '',
      password: cfg.password || '',
      from: cfg.phone_number || cfg.from || cfg.sender || '',
      to,
      message,
    };

    const templateString = JSON.stringify(bodyTemplate);
    const filled = templateString
      .replace(/\{\{to\}\}/g, to)
      .replace(/\{\{message\}\}/g, message)
      .replace(/\{\{from\}\}/g, cfg.phone_number || cfg.from || cfg.sender || '')
      .replace(/\{\{account\}\}/g, cfg.account || '')
      .replace(/\{\{password\}\}/g, cfg.password || '');

    try {
      return JSON.parse(filled);
    } catch {
      return { account: cfg.account || '', password: cfg.password || '', from: cfg.phone_number || cfg.from || '', to, message };
    }
  }

  async send({ to, message }) {
    if (!to || !message) throw new Error('to et message requis');
    const cfg = this.provider.config || {};
    const host = String(cfg.http_host || cfg.host || '').trim();
    const port = cfg.http_port || cfg.port || 80;
    const endpoint = String(cfg.endpoint || cfg.path || '/').trim();
    const protocol = String(cfg.protocol || 'http').trim().replace(/:\/\//g, '');
    const method = String(cfg.method || 'POST').toUpperCase();
    const contentType = String(cfg.contentType || 'application/json').trim();
    if (!host) throw new Error('http_host requis pour provider api_http');

    const url = `${protocol}://${host}${port ? `:${port}` : ''}${endpoint.startsWith('/') ? endpoint : '/' + endpoint}`;
    const headers = { 'Content-Type': contentType, ...(cfg.headers || {}) };
    let body = null;
    let query = '';

    const payload = this.buildRequestPayload(to, message);
    if (method === 'GET') {
      const params = new URLSearchParams();
      Object.entries(payload).forEach(([key, value]) => {
        if (value !== undefined && value !== null) params.append(key, String(value));
      });
      query = `?${params.toString()}`;
    } else if (contentType.includes('application/json')) {
      body = JSON.stringify(payload);
    } else if (contentType.includes('x-www-form-urlencoded')) {
      body = new URLSearchParams(payload).toString();
    } else {
      body = String(payload);
    }

    const response = await fetch(url + query, {
      method,
      headers,
      body,
    });

    if (!response.ok) {
      const text = await response.text();
      throw new Error(`HTTP ${response.status}: ${text.slice(0, 300)}`);
    }
    let result = null;
    try {
      result = await response.json();
    } catch {
      result = { ok: true, raw: await response.text() };
    }

    return { ok: true, providerId: this.provider.id, messageId: result.messageId || result.id || result.result || `api_http_${Date.now()}` };
  }
}

module.exports = ApiHttpProvider;
