'use strict';
const crypto = require('crypto');

class VonageProvider {
  constructor(provider) {
    this.provider = provider;
    this.baseUrl = 'https://rest.nexmo.com/sms';
  }

  async test() {
    try {
      const r = await fetch(`${this.baseUrl}?api_key=${this.provider.apiKey}&api_secret=${this.provider.apiSecret}&to=33600000000&text=test`);
      return { ok: true, message: 'Vonage connecté' };
    } catch (e) {
      return { ok: false, message: e.message };
    }
  }

  async send({ to, message }) {
    if (!to || !message) throw new Error('to et message requis');
    const params = new URLSearchParams({
      api_key: this.provider.apiKey,
      api_secret: this.provider.apiSecret,
      from: this.provider.from || this.provider.sender || 'Dontplay',
      to,
      text: message,
      status_report_req: '1',
    });
    const r = await fetch(this.baseUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString(),
    });
    const data = await r.json();
    const msg = data.messages?.[0];
    if (!msg || msg.status !== '0') {
      throw new Error(msg?.errorText || `Vonage error: ${msg?.status || r.status}`);
    }
    return {
      ok: true,
      providerId: this.provider.id,
      messageId: msg['message-id'] || crypto.randomUUID(),
      cost: msg.messagePrice ? parseFloat(msg.messagePrice) : null,
      remainingBalance: msg.remainingBalance ? parseFloat(msg.remainingBalance) : null,
      status: 'sent',
    };
  }
}

module.exports = VonageProvider;