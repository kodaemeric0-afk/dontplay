const https = require('https');

const HOST = 'dontplay-panel-production.up.railway.app';

function req(method, path) {
  return new Promise((resolve) => {
    const opts = {
      hostname: HOST, path, method, port: 443,
      rejectUnauthorized: false
    };
    const r = https.request(opts, (res) => {
      let d = '';
      res.on('data', c => d += c);
      res.on('end', () => resolve({
        status: res.statusCode,
        headers: res.headers,
        body: d
      }));
    });
    r.end();
  });
}

(async () => {
  console.log('=== DEBUG HEADERS ===\n');

  // 1. Login page - vérifier les cookies définis
  let r = await req('GET', '/login');
  console.log(`1. GET /login -> HTTP ${r.status}`);
  const setCookies = r.headers['set-cookie'] || [];
  console.log(`   Set-Cookie count: ${setCookies.length}`);
  setCookies.forEach((c, i) => console.log(`   [${i}] ${c}`));

  // 2. CSRF endpoint
  r = await req('GET', '/api/auth/csrf');
  console.log(`\n2. GET /api/auth/csrf -> HTTP ${r.status}`);
  console.log(`   Body: ${r.body}`);
  const setCookies2 = r.headers['set-cookie'] || [];
  console.log(`   Set-Cookie count: ${setCookies2.length}`);
  setCookies2.forEach((c, i) => console.log(`   [${i}] ${c}`));

  console.log('\n=== FIN ===');
})();