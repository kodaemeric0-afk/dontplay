'use strict';
const fs = require('fs');
const path = require('path');
const smsLib = require('./lib/sms');

function normalizeSlug(slug) {
  return String(slug || '').trim().toLowerCase().replace(/[^a-z0-9-_.]/g, '-').replace(/^-+|-+$/g, '');
}
function normalizeDomain(domain) {
  if (!domain) return '';
  let cleaned = String(domain).trim().toLowerCase();
  cleaned = cleaned.replace(/^https?:\/\//, '');
  cleaned = cleaned.replace(/^www\./, '');
  cleaned = cleaned.replace(/\/+$/, '');
  return cleaned;
}
async function main() {
  const tmpDir = path.resolve('./.tmp_test');
  if (!fs.existsSync(tmpDir)) fs.mkdirSync(tmpDir, { recursive: true });
  const smsPath = path.join(tmpDir, 'sms.json');
  const redirectsPath = path.join(tmpDir, 'redirects.json');
  fs.copyFileSync(path.resolve('./data/sms.json'), smsPath);
  fs.copyFileSync(path.resolve('./data/redirects.json'), redirectsPath);

  const runtime = smsLib.createRuntime(smsPath);
  const providers = runtime.providerManager.list();
  console.log('providers count:', providers.length);
  const phone = '+33612345678';
  const avail = runtime.providerManager.providersForPhone(phone);
  console.log('providers for', phone, ':', avail.map(p => ({ id: p.id, type: p.type, name: p.name })));
  if (avail.length) {
    const ProviderClass = require('./lib/sms/providers/ApiHttpProvider');
    const api = new ProviderClass(avail[0]);
    console.log('payload sample:', api.buildRequestPayload(phone, 'Test message'));
    console.log('provider test result:', await api.test());
  }

  const campaign = await runtime.sender.createCampaign({
    userId: 'test-user',
    name: 'Simulation SMS',
    message: 'Bonjour {{first}}',
    recipients: [{ phone: phone, first: 'Jean', last: 'Dupont' }],
    speed: 1,
  });
  console.log('created campaign:', { id: campaign.id, name: campaign.name, total: campaign.totalNumbers, status: campaign.status });

  function readRedirects() { return JSON.parse(fs.readFileSync(redirectsPath, 'utf8')); }
  function writeRedirects(data) { fs.writeFileSync(redirectsPath + '.tmp', JSON.stringify(data, null, 2), 'utf8'); fs.renameSync(redirectsPath + '.tmp', redirectsPath); }
  function saveRedirect(redirect) {
    const data = readRedirects();
    const now = new Date().toISOString();
    const normalized = {
      id: redirect.id || `testredir_${Math.random().toString(36).slice(2)}`,
      userId: redirect.userId,
      domain: normalizeDomain(redirect.domain),
      type: ['png','html','php','text'].includes(redirect.type) ? redirect.type : (redirect.type === 'meta' ? 'php' : 'text'),
      slug: normalizeSlug(redirect.slug),
      targetType: ['page','external','domain'].includes(redirect.targetType) ? redirect.targetType : 'external',
      targetValue: String(redirect.targetValue || '').trim(),
      targetUrl: String(redirect.targetUrl || '').trim(),
      redirectType: ['301','302'].includes(String(redirect.redirectType)) ? String(redirect.redirectType) : '302',
      notes: String(redirect.notes || '').trim(),
      clicks: Number.isFinite(Number(redirect.clicks)) ? redirect.clicks : 0,
      stats: Array.isArray(redirect.stats) ? redirect.stats : [],
      active: redirect.active !== false,
      createdAt: redirect.createdAt || now,
      updatedAt: now,
    };
    const existing = data.redirects.find(r => r.slug === normalized.slug && r.type === normalized.type && r.domain === normalized.domain);
    if (existing) throw new Error('Duplicate slug/domain/type');
    data.redirects.push(normalized);
    writeRedirects(data);
    return normalized;
  }

  const sampleRedirects = [
    { userId: 'test-user', domain: 'example.com', slug: 'redir-a', targetType: 'domain', targetValue: 'https://destination-a.com', targetUrl: 'https://destination-a.com', type: 'text', redirectType: '302', notes: 'test 1' },
    { userId: 'test-user', domain: 'example.com', slug: 'redir-b', targetType: 'domain', targetValue: 'https://destination-b.net', targetUrl: 'https://destination-b.net', type: 'text', redirectType: '301', notes: 'test 2' },
    { userId: 'test-user', domain: 'otherdomain.fr', slug: 'redir-c', targetType: 'domain', targetValue: 'https://destination-c.org', targetUrl: 'https://destination-c.org', type: 'text', redirectType: '302', notes: 'test 3' },
  ];
  const created = sampleRedirects.map(saveRedirect);
  console.log('created redirects:', created.map(r => ({ domain: r.domain, slug: r.slug, target: r.targetUrl, redirectType: r.redirectType })));
}

main().catch(err => { console.error(err); process.exit(1); });
