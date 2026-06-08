'use strict';
const baseUrl = 'http://localhost:3000';
const fetch = global.fetch;
const cookieJar = new Map();
function saveCookies(res) {
  const set = res.headers.get('set-cookie');
  if (!set) return;
  set.split(/,\s*(?=[^;]+=[^;]+)/).forEach(pair => {
    const [cookiePart] = pair.split(';');
    const [name, value] = cookiePart.split('=');
    if (!name || value === undefined) return;
    cookieJar.set(name.trim(), value.trim());
  });
}
function cookieHeader() {
  return [...cookieJar.entries()].map(([name, value]) => `${name}=${value}`).join('; ');
}
async function req(path, opts = {}) {
  const headers = { ...(opts.headers || {}), Cookie: cookieHeader() };
  const res = await fetch(baseUrl + path, { ...opts, headers, redirect: 'manual' });
  saveCookies(res);
  const text = await res.text();
  let body = text;
  try { body = JSON.parse(text); } catch (err) {}
  return { status: res.status, headers: res.headers, body };
}
(async () => {
  console.log('1) Test /api/auth/csrf');
  const csrfRes = await req('/api/auth/csrf');
  console.log('CSRF status', csrfRes.status);
  console.log('CSRF body', csrfRes.body);
  const csrfToken = csrfRes.body?.csrfToken;
  if (!csrfToken) {
    throw new Error('CSRF token not returned');
  }

  const username = `testredir${Date.now().toString().slice(-6)}`;
  const slugSuffix = Date.now().toString().slice(-5);
  console.log('2) Registering test user', username);
  const reg = await req('/api/auth/register', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
    body: JSON.stringify({ username, password: 'TestPass123!@#', confirmPassword: 'TestPass123!@#' }),
  });
  console.log('Register status', reg.status, reg.body);
  if (reg.status !== 200) {
    throw new Error('Register failed: ' + JSON.stringify(reg.body));
  }

  console.log('3) Refresh CSRF token after registration');
  const csrfAfterReg = await req('/api/auth/csrf');
  const csrfTokenAfterReg = csrfAfterReg.body?.csrfToken;
  if (!csrfTokenAfterReg) {
    throw new Error('CSRF token not returned after register');
  }

  console.log('4) Create a redirect domain -> domain');
  const create1 = await req('/api/redirects', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfTokenAfterReg },
    body: JSON.stringify({ domainName: 'source1.dontplay.net', slug: `campaign1-${slugSuffix}`, destinationType: 'domain', destination: 'target1.com', redirectType: '302' }),
  });
  console.log('Create1 status', create1.status, create1.body);

  console.log('4) Create second redirect on same domain');
  const create2 = await req('/api/redirects', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfTokenAfterReg },
    body: JSON.stringify({ domainName: 'source1.dontplay.net', slug: `campaign2-${slugSuffix}`, destinationType: 'domain', destination: 'target2.com', redirectType: '302' }),
  });
  console.log('Create2 status', create2.status, create2.body);

  console.log('5) Create external redirect');
  const create3 = await req('/api/redirects', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfTokenAfterReg },
    body: JSON.stringify({ domainName: 'source2.dontplay.net', slug: `external-${slugSuffix}`, destinationType: 'external', destination: 'https://example.com', redirectType: '301' }),
  });
  console.log('Create3 status', create3.status, create3.body);

  console.log('6) List redirects');
  const list = await req('/api/redirects');
  console.log('List status', list.status, Array.isArray(list.body.redirects) ? `count=${list.body.redirects.length}` : list.body);

  console.log('7) Test /__dev/send-sms');
  const sms = await req('/__dev/send-sms', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'x-dev-secret': 'localdevsecret' },
    body: JSON.stringify({ to: '+33775036219', message: 'Test live SMS depuis Dontplay local via /__dev/send-sms.' }),
  });
  console.log('SMS status', sms.status, sms.body);

  console.log('DONE');
})();
