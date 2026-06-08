'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

function nowIso() { return new Date().toISOString(); }
function id(prefix) { return `${prefix}_${crypto.randomUUID()}`; }

class SmsStorage {
  constructor(filePath) {
    this.filePath = path.resolve(filePath);
    this._lock = Promise.resolve();
    this._ensure();
  }

  _ensure() {
    fs.mkdirSync(path.dirname(this.filePath), { recursive: true });
    if (!fs.existsSync(this.filePath)) {
      this._write({ providers: [], campaigns: [], templates: [], logs: [] });
    }
  }

  _read() {
    try {
      const data = JSON.parse(fs.readFileSync(this.filePath, 'utf8'));
      return {
        providers: Array.isArray(data.providers) ? data.providers : [],
        campaigns: Array.isArray(data.campaigns) ? data.campaigns : [],
        templates: Array.isArray(data.templates) ? data.templates : [],
        logs: Array.isArray(data.logs) ? data.logs : [],
      };
    } catch {
      return { providers: [], campaigns: [], templates: [], logs: [] };
    }
  }

  _write(data) {
    const tmp = this.filePath + '.tmp';
    fs.writeFileSync(tmp, JSON.stringify(data, null, 2), 'utf8');
    fs.renameSync(tmp, this.filePath);
  }

  _withLock(fn) {
    const next = this._lock.then(() => fn());
    this._lock = next.catch(() => {});
    return next;
  }

  listProviders() { return this._read().providers; }
  getProvider(providerId) { return this.listProviders().find(p => p.id === providerId) || null; }

  upsertProvider(provider) {
    return this._withLock(() => {
      const data = this._read();
      const ts = nowIso();
      const normalized = {
        id: provider.id || id('smsprov'),
        type: String(provider.type || 'generic_http').toLowerCase(),
        name: String(provider.name || 'Provider SMS').trim(),
        active: provider.active !== false,
        countries: Array.isArray(provider.countries) && provider.countries.length ? provider.countries : ['default'],
        priority: Number.isFinite(Number(provider.priority)) ? Number(provider.priority) : 10,
        config: provider.config && typeof provider.config === 'object' ? provider.config : {},
        price: Number.isFinite(Number(provider.price)) ? Number(provider.price) : null,
        routeType: provider.routeType ? String(provider.routeType).trim().toLowerCase() : null,
        routeTypes: Array.isArray(provider.routeTypes) ? provider.routeTypes.map(t => String(t).trim().toLowerCase()).filter(Boolean) : [],
        rateLimit: Math.max(1, Math.min(100, Number(provider.rateLimit || provider.rate_limit || 10))),
        failThreshold: Math.max(1, Number(provider.failThreshold || provider.fail_threshold || 5)),
        failCount: Number(provider.failCount || provider.fail_count || 0),
        lastFail: provider.lastFail || provider.last_fail || null,
        disabledUntil: provider.disabledUntil || null,
        createdAt: provider.createdAt || ts,
        updatedAt: ts,
      };
      const idx = data.providers.findIndex(p => p.id === normalized.id);
      if (idx === -1) data.providers.push(normalized);
      else data.providers[idx] = { ...data.providers[idx], ...normalized };
      this._write(data);
      return normalized;
    });
  }

  deleteProvider(providerId) {
    return this._withLock(() => {
      const data = this._read();
      data.providers = data.providers.filter(p => p.id !== providerId);
      this._write(data);
      return true;
    });
  }

  listCampaigns(userId = null) {
    const list = this._read().campaigns;
    return userId ? list.filter(c => c.userId === userId) : list;
  }

  getCampaign(campaignId) {
    return this._read().campaigns.find(c => c.id === campaignId) || null;
  }

  upsertCampaign(campaign) {
    return this._withLock(() => {
      const data = this._read();
      const ts = nowIso();
      const normalized = { ...campaign, id: campaign.id || id('smscamp'), updatedAt: ts, createdAt: campaign.createdAt || ts };
      const idx = data.campaigns.findIndex(c => c.id === normalized.id);
      if (idx === -1) data.campaigns.push(normalized);
      else data.campaigns[idx] = { ...data.campaigns[idx], ...normalized };
      this._write(data);
      return normalized;
    });
  }

  updateCampaign(campaignId, updates) {
    return this._withLock(() => {
      const data = this._read();
      const idx = data.campaigns.findIndex(c => c.id === campaignId);
      if (idx === -1) throw new Error('Campagne introuvable.');
      data.campaigns[idx] = { ...data.campaigns[idx], ...updates, updatedAt: nowIso() };
      this._write(data);
      return data.campaigns[idx];
    });
  }

  listTemplates(userId) {
    return this._read().templates.filter(t => t.userId === userId);
  }

  upsertTemplate(template) {
    return this._withLock(() => {
      const data = this._read();
      const ts = nowIso();
      const normalized = { ...template, id: template.id || id('smstpl'), updatedAt: ts, createdAt: template.createdAt || ts };
      const idx = data.templates.findIndex(t => t.id === normalized.id && t.userId === normalized.userId);
      if (idx === -1) data.templates.push(normalized);
      else data.templates[idx] = { ...data.templates[idx], ...normalized };
      this._write(data);
      return normalized;
    });
  }

  deleteTemplate(userId, templateId) {
    return this._withLock(() => {
      const data = this._read();
      data.templates = data.templates.filter(t => !(t.userId === userId && t.id === templateId));
      this._write(data);
      return true;
    });
  }

  addLog(log) {
    return this._withLock(() => {
      const data = this._read();
      data.logs.push({ id: id('smslog'), createdAt: nowIso(), ...log });
      data.logs = data.logs.slice(-50000);
      this._write(data);
      return data.logs[data.logs.length - 1];
    });
  }

  listLogs(filter = {}) {
    let logs = this._read().logs;
    if (filter.campaignId) logs = logs.filter(l => l.campaignId === filter.campaignId);
    if (filter.providerId) logs = logs.filter(l => l.providerId === filter.providerId);
    return logs;
  }

  stats() {
    const data = this._read();
    const byProvider = {};
    for (const l of data.logs) {
      const key = l.providerId || 'none';
      byProvider[key] ||= { success: 0, failed: 0, total: 0 };
      byProvider[key].total++;
      if (l.status === 'success') byProvider[key].success++;
      else byProvider[key].failed++;
    }
    return {
      providers: data.providers.length,
      campaigns: data.campaigns.length,
      logs: data.logs.length,
      byProvider,
    };
  }
}

module.exports = SmsStorage;