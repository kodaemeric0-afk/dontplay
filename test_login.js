const https = require('https');

const HOST = 'dontplay-panel-production.up.railway.app';

function req(method, path, headers, body, cookies) {
  return new Promise((resolve) => {
    const h = { 'Content-Type': 'application/json', ...headers };
    if (cookies) h['Cookie'] = cookies;
    const opts = {
      hostname: HOST, path, method, port: 443,
      rejectUnauthorized: false, headers: h
    };
    const r = https.request(opts, (res) => {
      let d = '';
      res.on('data', c => d += c);
      res.on('end', () => resolve({
        status: res.statusCode,
        body: d,
        setCookie: res.headers['set-cookie'] || []
      }));
    });
    if (body) r.write(body);
    r.end();
  });
}

(async () => {
  console.log('=== TEST LOGIN ===\n');

  // 1. Get CSRF token (sets session cookie)
  let r = await req('GET', '/api/auth/csrf');
  const token = r.setCookie.join('; ');
  console.log(`1. GET /api/auth/csrf -> HTTP ${r.status}`);
  console.log(`   Set-Cookie: ${r.setCookie.length > 0 ? 'OUI' : 'NON'}`);
  console.log(`   Body: ${r.body}`);
  console.log(`   Cookies: ${token}`);

  // 2. Login WITHOUT CSRF (should work now)
  r = await req('POST', '/api/auth/login', {},
    JSON.stringify({ username: 'test', password: 'test' }),
    token
  );
  console.log(`\n2. Login SANS CSRF -> HTTP ${r.status}`);
  console.log(`   Body: ${r.body}`);

  // 3. Register test user
  const testUser = 'test_' + Date.now().toString(36);
  r = await req('POST', '/api/auth/register',
    {},
    JSON.stringify({ username: testUser, password: 'Test1234!pass', confirmPassword: 'Test1234!pass' }),
    token
  );
  console.log(`\n3. Register ${testUser} -> HTTP ${r.status}`);
  console.log(`   Body: ${r.body}`);

  console.log('\n=== FIN ===');
})();