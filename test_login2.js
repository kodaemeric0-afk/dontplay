const https = require('https');

const HOST = 'dontplay-panel-production.up.railway.app';

(async () => {
  console.log('=== TEST LOGIN (sans CSRF) ===\n');

  // Login sans CSRF - sans session cookie
  const body = JSON.stringify({ username: 'test', password: 'test' });
  
  const opts = {
    hostname: HOST,
    path: '/api/auth/login',
    method: 'POST',
    port: 443,
    rejectUnauthorized: false,
    headers: {
      'Content-Type': 'application/json',
      'Content-Length': body.length,
    }
  };
  
  const r = https.request(opts, (res) => {
    let d = '';
    res.on('data', c => d += c);
    res.on('end', () => {
      console.log(`HTTP ${res.statusCode}`);
      console.log(`Body: ${d}`);
      console.log(`\n=== FIN ===`);
    });
  });
  r.write(body);
  r.end();
})();