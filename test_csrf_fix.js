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
  console.log('=== TEST CSRF FIX ===\n');

  // Étape 1: Récupérer le token CSRF (obtient aussi le cookie de session)
  let r = await req('GET', '/api/auth/csrf');
  const data = JSON.parse(r.body);
  const token = data.csrfToken;
  const sessionCookie = r.setCookie.join('; ');
  console.log(`1. GET /api/auth/csrf -> HTTP ${r.status}`);
  console.log(`   Token: ${token.slice(0, 30)}...`);
  console.log(`   Cookie session: ${sessionCookie.slice(0, 60)}...`);

  // Étape 2: Login SANS token CSRF (doit échouer 403)
  r = await req('POST', '/api/auth/login', {},
    JSON.stringify({ username: 'test', password: 'test' }),
    sessionCookie
  );
  console.log(`\n2. Login SANS CSRF -> HTTP ${r.status}`);
  console.log(`   ${r.body}`);

  // Étape 3: Login AVEC token CSRF (même session)
  r = await req('POST', '/api/auth/login',
    { 'X-CSRF-Token': token },
    JSON.stringify({ username: 'test', password: 'test' }),
    sessionCookie
  );
  console.log(`\n3. Login AVEC CSRF -> HTTP ${r.status}`);
  console.log(`   ${r.body.slice(0, 120)}`);

  // Étape 4: Register AVEC CSRF
  const testUser = 'test_' + Date.now().toString(36);
  r = await req('POST', '/api/auth/register',
    { 'X-CSRF-Token': token },
    JSON.stringify({ username: testUser, password: 'Test1234!pass', confirmPassword: 'Test1234!pass' }),
    sessionCookie
  );
  console.log(`\n4. Register ${testUser} AVEC CSRF -> HTTP ${r.status}`);
  console.log(`   ${r.body.slice(0, 120)}`);

  console.log('\n=== FIN ===');
})();