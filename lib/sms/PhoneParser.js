'use strict';

const COUNTRY_BY_DIAL = {
  '+33': { code: 'FR', name: 'France' },
  '+34': { code: 'ES', name: 'Espagne' },
  '+32': { code: 'BE', name: 'Belgique' },
  '+41': { code: 'CH', name: 'Suisse' },
  '+44': { code: 'GB', name: 'Royaume-Uni' },
  '+351': { code: 'PT', name: 'Portugal' },
  '+48': { code: 'PL', name: 'Pologne' },
  '+36': { code: 'HU', name: 'Hongrie' },
  '+27': { code: 'ZA', name: 'Afrique du Sud' },
};

const HEADER_ALIASES = {
  phone: ['phone', 'tel', 'telephone', 'mobile', 'msisdn', 'numero', 'number'],
  first: ['first', 'first_name', 'firstname', 'prenom', 'prénom'],
  last: ['last', 'last_name', 'lastname', 'nom'],
};

function cleanHeader(v) {
  return String(v || '').trim().toLowerCase().replace(/\s+/g, '_');
}

function splitLine(line, forcedDelimiter = null) {
  const delimiter = forcedDelimiter || (line.includes(';') ? ';' : line.includes('\t') ? '\t' : ',');
  return String(line).split(delimiter).map(v => v.trim());
}

function looksLikePhone(v) {
  return String(v || '').replace(/\D/g, '').length >= 6;
}

class PhoneParser {
  constructor(opts = {}) {
    this.defaultDial = opts.defaultDial || '+33';
  }

  normalizePhone(raw, fallbackDial = this.defaultDial) {
    let value = String(raw || '').trim();
    if (!value) return null;
    value = value.replace(/[\s().-]/g, '');
    if (value.startsWith('00')) value = '+' + value.slice(2);
    if (value.startsWith('+')) {
      value = '+' + value.slice(1).replace(/\D/g, '');
    } else {
      value = value.replace(/\D/g, '');
      if (!value) return null;
      if (value.startsWith('0')) value = value.slice(1);
      const dialDigits = String(fallbackDial || this.defaultDial).replace(/\D/g, '') || '33';
      value = '+' + dialDigits + value;
    }
    const digits = value.replace('+', '');
    if (digits.length < 8 || digits.length > 15) return null;
    return '+' + digits;
  }

  detectCountry(phone) {
    const value = String(phone || '');
    const dials = Object.keys(COUNTRY_BY_DIAL).sort((a, b) => b.length - a.length);
    const dial = dials.find(d => value.startsWith(d));
    return dial ? { dial, ...COUNTRY_BY_DIAL[dial] } : { dial: 'default', code: 'default', name: 'Inconnu' };
  }

  mapHeaders(headers) {
    const normalized = headers.map(cleanHeader);
    const map = {};
    for (const [key, aliases] of Object.entries(HEADER_ALIASES)) {
      const idx = normalized.findIndex(h => aliases.includes(h));
      if (idx !== -1) map[key] = idx;
    }
    normalized.forEach((h, idx) => {
      if (!Object.values(map).includes(idx) && h) map[h] = idx;
    });
    return map;
  }

  parseRows(rows, opts = {}) {
    const fallbackDial = opts.defaultDial || this.defaultDial;
    const invalid = [];
    const duplicates = [];
    const contacts = [];
    const seen = new Set();
    let headerMap = null;

    if (rows.length > 1) {
      const first = rows[0].map(cleanHeader);
      const hasHeader = first.some(h => Object.values(HEADER_ALIASES).flat().includes(h));
      if (hasHeader) headerMap = this.mapHeaders(rows.shift());
    }

    rows.forEach((parts, rowIndex) => {
      if (!parts || !parts.length || parts.every(v => !String(v).trim())) return;
      let phoneRaw = '';
      const variables = {};

      if (headerMap) {
        phoneRaw = parts[headerMap.phone] || '';
        for (const [key, idx] of Object.entries(headerMap)) {
          if (idx !== undefined && parts[idx] !== undefined) variables[key] = String(parts[idx]).trim();
        }
      } else {
        let phoneIdx = parts.findIndex(looksLikePhone);
        if (phoneIdx < 0) phoneIdx = 0;
        phoneRaw = parts[phoneIdx] || '';
        const other = parts.filter((_, idx) => idx !== phoneIdx);
        variables.first = other[0] || '';
        variables.last = other[1] || '';
        other.slice(2, 12).forEach((v, i) => { variables[`var${i + 1}`] = v; });
      }

      const phone = this.normalizePhone(phoneRaw, fallbackDial);
      if (!phone) {
        invalid.push({ row: rowIndex + 1, raw: parts.join(';'), reason: 'Numéro invalide' });
        return;
      }
      if (seen.has(phone)) {
        duplicates.push({ row: rowIndex + 1, phone, reason: 'Doublon' });
        return;
      }
      seen.add(phone);
      const country = this.detectCountry(phone);
      contacts.push({ phone, country: country.code, dial: country.dial, variables });
    });

    const variableSet = new Set(['phone', 'country', 'date', 'time', 'datetime', 'day', 'random', 'uuid']);
    contacts.forEach(c => Object.keys(c.variables || {}).forEach(k => variableSet.add(k)));
    return {
      contacts,
      invalid,
      duplicates,
      variables: [...variableSet].slice(0, 40),
      stats: {
        totalRows: contacts.length + invalid.length + duplicates.length,
        valid: contacts.length,
        invalid: invalid.length,
        duplicates: duplicates.length,
        withVariables: contacts.filter(c => Object.keys(c.variables || {}).length > 0).length,
      },
      preview: contacts.slice(0, 20),
    };
  }

  parseText(text, opts = {}) {
    const lines = String(text || '').split(/\r?\n/).map(l => l.trim()).filter(l => l && !l.startsWith('#'));
    const delimiter = opts.delimiter || null;
    const rows = lines.map(line => splitLine(line, delimiter));
    return this.parseRows(rows, opts);
  }

  parseJson(json, opts = {}) {
    const arr = Array.isArray(json) ? json : JSON.parse(String(json || '[]'));
    const rows = arr.map(obj => {
      const v = obj || {};
      return [v.phone || v.tel || v.mobile || v.number || '', v.first || v.first_name || v.prenom || '', v.last || v.last_name || v.nom || '', v.company || '', v.city || '', v.zip || ''];
    });
    rows.unshift(['phone', 'first', 'last', 'company', 'city', 'zip']);
    return this.parseRows(rows, opts);
  }

  parseFileContent(filename, content, opts = {}) {
    const ext = String(filename || '').split('.').pop().toLowerCase();
    if (ext === 'json') return this.parseJson(content, opts);
    return this.parseText(content, opts);
  }
}

PhoneParser.COUNTRY_BY_DIAL = COUNTRY_BY_DIAL;
module.exports = PhoneParser;