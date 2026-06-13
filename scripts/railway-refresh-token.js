const https = require('https');
const fs = require('fs');
const path = require('path');

const configPath = path.join(process.env.USERPROFILE, '.railway', 'config.json');
const config = JSON.parse(fs.readFileSync(configPath, 'utf8'));

// Get the specific workspace entry
const projects = Object.values(config.projects);
const firstProject = projects[0];
const refreshToken = config.user.refreshToken;

async function refreshTokenFn() {
  console.log('Refreshing token...');
  
  const data = JSON.stringify({
    query: `mutation { refreshTokenV2(refreshToken: "${refreshToken}") { accessToken refreshToken expiresIn } }`
  });
  
  const res = await new Promise((resolve, reject) => {
    const req = https.request({
      hostname: 'backboard.railway.app',
      path: '/graphql/v2',
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': 'Bearer ' + refreshToken
      }
    }, res => {
      let body = '';
      res.on('data', c => body += c);
      res.on('end', () => resolve({ status: res.statusCode, body: JSON.parse(body) }));
    });
    req.on('error', reject);
    req.write(data);
    req.end();
  });
  
  console.log('Response:', JSON.stringify(res, null, 2));
}

refreshTokenFn().catch(console.error);