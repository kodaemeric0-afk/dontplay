'use strict';

const crypto = require('crypto');
const MessageVariableEngine = require('./MessageVariableEngine');

class SMSSender {
  constructor(storage, providerManager) {
    this.storage = storage;
    this.providerManager = providerManager;
    this.engine = new MessageVariableEngine();
    this.timers = new Map();
  }

  createCampaign({ userId, name, message, recipients, speed = 1, scheduledFor = null, senderId = '', preferredProviderType = 'auto', preferredRouteType = 'auto', costPerSms = 0 }) {
    const now = new Date().toISOString();
    const campaign = {
      id: `smscamp_${crypto.randomUUID()}`,
      userId,
      name: String(name || 'Campagne SMS').trim(),
      message: String(message || '').trim(),
      variables: this.engine.detectVariables(message),
      recipients: recipients.map((r, idx) => ({ id: idx + 1, ...r, status: 'pending', attempts: 0, error: null, providerUsed: null, sentAt: null })),
      totalNumbers: recipients.length,
      sentCount: 0,
      failedCount: 0,
      speed: Math.max(1, Math.min(100, Number(speed || 1))),
      senderId: String(senderId || '').trim(),
      preferredProviderType: String(preferredProviderType || 'auto').trim(),
      preferredRouteType: String(preferredRouteType || 'auto').trim(),
      costPerSms: Number.isFinite(Number(costPerSms)) ? Number(costPerSms) : 0,
      status: scheduledFor ? 'scheduled' : 'pending',
      scheduledFor,
      startedAt: null,
      completedAt: null,
      createdAt: now,
      updatedAt: now,
      debitDone: false,
      lastPhone: null,
    };
    return this.storage.upsertCampaign(campaign);
  }

  async startCampaign(campaignId, { debit } = {}) {
    let campaign = this.storage.getCampaign(campaignId);
    if (!campaign) throw new Error('Campagne introuvable.');
    if (campaign.status === 'running') return campaign;
    if (['completed', 'stopped'].includes(campaign.status)) throw new Error('Campagne déjà terminée.');

    if (!campaign.debitDone && typeof debit === 'function') {
      await debit(campaign.totalNumbers);
      campaign = await this.storage.updateCampaign(campaignId, { debitDone: true });
    }

    campaign = await this.storage.updateCampaign(campaignId, { status: 'running', startedAt: campaign.startedAt || new Date().toISOString() });
    this._schedule(campaignId);
    return campaign;
  }

  async pauseCampaign(campaignId) {
    this._clear(campaignId);
    return this.storage.updateCampaign(campaignId, { status: 'paused' });
  }

  async resumeCampaign(campaignId) {
    const c = await this.storage.updateCampaign(campaignId, { status: 'running' });
    this._schedule(campaignId);
    return c;
  }

  async stopCampaign(campaignId) {
    this._clear(campaignId);
    return this.storage.updateCampaign(campaignId, { status: 'stopped', completedAt: new Date().toISOString() });
  }

  _clear(campaignId) {
    if (this.timers.has(campaignId)) clearInterval(this.timers.get(campaignId));
    this.timers.delete(campaignId);
  }

  _schedule(campaignId) {
    this._clear(campaignId);
    const timer = setInterval(() => this._tick(campaignId).catch(err => console.error('[sms/tick]', err)), 1000);
    this.timers.set(campaignId, timer);
  }

  async _tick(campaignId) {
    let campaign = this.storage.getCampaign(campaignId);
    if (!campaign || campaign.status !== 'running') { this._clear(campaignId); return; }
    const pending = campaign.recipients.filter(r => r.status === 'pending').slice(0, campaign.speed);
    if (!pending.length) {
      this._clear(campaignId);
      await this.storage.updateCampaign(campaignId, { status: 'completed', completedAt: new Date().toISOString() });
      return;
    }

    for (const r of pending) {
      const msg = this.engine.render(campaign.message, r);
      try {
        const sent = await this.providerManager.sendSms({
          phone: r.phone,
          message: msg,
          campaignId,
          preferredProviderType: campaign.preferredProviderType,
          preferredRouteType: campaign.preferredRouteType,
        });
        r.status = 'sent';
        r.providerUsed = sent.providerId || null;
        r.sentAt = new Date().toISOString();
        campaign.sentCount++;
      } catch (err) {
        r.status = 'failed';
        r.error = err.message;
        campaign.failedCount++;
      }
      r.attempts = (r.attempts || 0) + 1;
      campaign.lastPhone = r.phone;
    }

    await this.storage.updateCampaign(campaignId, { recipients: campaign.recipients, sentCount: campaign.sentCount, failedCount: campaign.failedCount, lastPhone: campaign.lastPhone });
  }
}

module.exports = SMSSender;