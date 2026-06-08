'use strict';

const crypto = require('crypto');

class BaseProvider {
  constructor(provider) { this.provider = provider; }
  async test() { return { ok: true, message: 'Provider configuré.' }; }
  async send({ to, message }) {
    if (!to || !message) throw new Error('to et message requis');
    await new Promise(r => setTimeout(r, 30));
    return { ok: true, providerId: this.provider.id, messageId: crypto.randomUUID() };
  }
}

module.exports = BaseProvider;