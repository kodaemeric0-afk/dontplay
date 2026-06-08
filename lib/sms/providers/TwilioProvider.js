'use strict';
const crypto = require('crypto');

class TwilioProvider {
  constructor(provider) {
    this.provider = provider;
    this.baseUrl = 'https://api.twilio.com/2010-04-01';
  }

  async test() {
    try {
      await this._request(`/Accounts/${this.provider.accountSid}.json`, 'GET');
      return { ok: true, message: 'Twilio connecté' };
    } catch (e) {
      return { ok: false, message: e.message };
    }
  }

  async send({ to, message }) {
    if (!to || !message) throw new Error('to et message requis');
    const data = new URLSearchParams({
      To: to,
      From: this.provider.from || this.provider.sender,
      Body: message,
    });
    const result = await this._request(
      `/Accounts/${this.provider.accountSid}/Messages.json`,
      'POST',
      data.toString(),
      { 'Content-Type': 'application/x-www-form-urlencoded' }
    );
    return {
      ok: true,
      providerId: this.provider.id,
      messageId: result.sid || crypto.randomUUID(),
      cost: result.price ? parseFloat(result.price) : null,
      segments: result.numSegments ? parseInt(result.numSegments) : 1,
      status: result.status || 'sent',
    };
  }

  async _request(path, method, body, extraHeaders) {
    const auth = Buffer.from(
      `${this.provider.accountSid}:${this.provider.authToken}`
    ).toString('base64');
    const response = await fetch(`${this.baseUrl}${path}`, {
      method,
      headers: {
        'Authorization': `Basic ${auth}`,
        ...extraHeaders,
      },
      body,
    });
    const data = await response.json();
    if (!response.ok) {
      throw new Error(data.message || `Twilio error: ${response.status}`);
    }
    return data;
  }
}

module.exports = TwilioProvider;