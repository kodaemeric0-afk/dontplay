'use strict';

const PhoneParser = require('./PhoneParser');
const BaseProvider = require('./providers/BaseProvider');
const TwilioProvider = require('./providers/TwilioProvider');
const PlivoProvider = require('./providers/PlivoProvider');
const VonageProvider = require('./providers/VonageProvider');
const VeroHttpProvider = require('./providers/VeroHttpProvider');
const ApiHttpProvider = require('./providers/ApiHttpProvider');
const SmppProvider = require('./providers/SmppProvider');
const GenericHttpProvider = require('./providers/GenericHttpProvider');

const TYPE_CLASS = {
  twilio: TwilioProvider,
  plivo: PlivoProvider,
  vonage: VonageProvider,
  nexmo: VonageProvider,
  vero_http: VeroHttpProvider,
  api_http: ApiHttpProvider,
  smpp: SmppProvider,
  generic_http: GenericHttpProvider,
};

class SMSProviderManager {
  constructor(storage) {
    this.storage = storage;
    this.parser = new PhoneParser();
  }

  list() { return this.storage.listProviders().sort((a, b) => (a.priority || 0) - (b.priority || 0)); }
  listByCountry(countryOrDial) {
    const key = String(countryOrDial || 'default');
    const country = this.parser.detectCountry(key) || {};
    const keys = [key];
    if (country.dial) keys.push(country.dial);
    if (country.code) keys.push(country.code);
    return this.list().filter(p => Array.isArray(p.countries) && keys.some(k => p.countries.includes(k) || p.countries.includes('default')));
  }
  listActiveByCountry(countryOrDial) {
    const key = String(countryOrDial || 'default');
    const country = this.parser.detectCountry(key) || {};
    const keys = [key];
    if (country.dial) keys.push(country.dial);
    if (country.code) keys.push(country.code);
    return this.list().filter(p => {
      if (!p.active) return false;
      if (p.disabledUntil && new Date(p.disabledUntil) > new Date()) return false;
      const countries = Array.isArray(p.countries) ? p.countries : [];
      return keys.some(k => countries.includes(k) || countries.includes('default'));
    });
  }
  create(payload) { return this.storage.upsertProvider(payload); }
  update(id, payload) { return this.storage.upsertProvider({ ...this.storage.getProvider(id), ...payload, id }); }
  delete(id) { return this.storage.deleteProvider(id); }

  providersForPhone(phone, { preferredProviderType = 'auto', preferredRouteType = 'auto' } = {}) {
    const country = this.parser.detectCountry(phone);
    const all = this.list();
    if (!all.length) {
      // No providers configured — do not fallback to a simulation provider.
      return [];
    }

    const providerTypeFilter = String(preferredProviderType || 'auto').trim().toLowerCase();
    const routeTypeFilter = String(preferredRouteType || 'auto').trim().toLowerCase();

    const providers = all.filter(p => {
      if (!p.active) return false;
      if (p.disabledUntil && new Date(p.disabledUntil) > new Date()) return false;
      const countries = Array.isArray(p.countries) ? p.countries : [];
      if (!countries.includes(country.dial) && !countries.includes(country.code) && !countries.includes('default')) return false;
      if (providerTypeFilter !== 'auto' && String(p.type || '').toLowerCase() !== providerTypeFilter) return false;
      if (routeTypeFilter !== 'auto') {
        const providerRouteType = String(p.routeType || '').toLowerCase();
        const providerRouteTypes = Array.isArray(p.routeTypes) ? p.routeTypes.map(t => String(t).toLowerCase()) : [];
        if (providerRouteType && providerRouteType !== routeTypeFilter && !providerRouteTypes.includes(routeTypeFilter)) return false;
        if (!providerRouteType && providerRouteTypes.length && !providerRouteTypes.includes(routeTypeFilter)) return false;
      }
      return true;
    });
    return providers.sort((a, b) => (a.priority || 10) - (b.priority || 10));
  }

  providerInstance(provider) {
    const Cls = TYPE_CLASS[provider.type] || BaseProvider;
    return new Cls(provider);
  }

  async test(id) {
    const provider = this.storage.getProvider(id);
    if (!provider) throw new Error('Provider introuvable.');
    return this.providerInstance(provider).test();
  }

  async sendSms({ phone, message, campaignId, preferredProviderType = 'auto', preferredRouteType = 'auto' }) {
    const providers = this.providersForPhone(phone, { preferredProviderType, preferredRouteType });
    if (!providers.length) throw new Error('Aucun provider SMS disponible pour ce pays.');
    let lastErr = null;
    for (const p of providers) {
      const started = Date.now();
      try {
        const result = await this.providerInstance(p).send({ to: phone, message, preferredRouteType, campaignId });
        await this.storage.addLog({ providerId: p.id, campaignId, phone, status: 'success', durationMs: Date.now() - started, error: null, routeType: preferredRouteType !== 'auto' ? preferredRouteType : p.routeType || null });
        return result;
      } catch (err) {
        lastErr = err;
        await this.storage.addLog({ providerId: p.id, campaignId, phone, status: 'failed', durationMs: Date.now() - started, error: err.message });
        await this.storage.upsertProvider({ ...p, failCount: (p.failCount || 0) + 1, lastFail: new Date().toISOString() });
      }
    }
    throw lastErr || new Error('Envoi impossible.');
  }

  stats() { return this.storage.stats(); }
}

module.exports = SMSProviderManager;