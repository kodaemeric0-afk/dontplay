const https = require('https');
const fs = require('fs');
const path = require('path');

const configPath = path.join(process.env.USERPROFILE, '.railway', 'config.json');
const raw = fs.readFileSync(configPath, 'utf8');

// Parse manually because of duplicate keys
const lines = raw.split('\n');
const config = JSON.parse(raw.replace(/\r?\n/g, '\\n').replace(/\\n/g, '\n'));

// Actually let's just parse it properly
const parsed = JSON.parse(raw);
const projects = Object.values(parsed.projects);
const projectInfo = projects[0];
const projectId = projectInfo.project;
const envId = projectInfo.environment;

let accessToken = parsed.user.accessToken;
let refreshToken = parsed.user.refreshToken;

function gql(token, query) {
  return new Promise((resolve, reject) => {
    const data = JSON.stringify({ query });
    const req = https.request({
      hostname: 'backboard.railway.app',
      path: '/graphql/v2',
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json',
        'Content-Length': Buffer.byteLength(data)
      }
    }, res => {
      let body = '';
      res.on('data', c => body += c);
      res.on('end', () => {
        try { resolve(JSON.parse(body)); }
        catch { resolve({ raw: body }); }
      });
    });
    req.on('error', reject);
    req.write(data);
    req.end();
  });
}

async function main() {
  // Try with current access token
  console.log('Trying with access token...');
  let result = await gql(accessToken, `{ project(id: "${projectId}") { services(first: 10) { edges { node { id name } } } } }`);
  console.log('Result:', JSON.stringify(result, null, 2));
  
  if (result.errors) {
    // Token expired, try to refresh
    console.log('Token expired, refreshing...');
    const refreshResult = await gql(refreshToken, `mutation { refreshTokenV2(refreshToken: "${refreshToken}") { accessToken refreshToken expiresIn } }`);
    console.log('Refresh result:', JSON.stringify(refreshResult, null, 2));
    
    if (refreshResult.data?.refreshTokenV2) {
      accessToken = refreshResult.data.refreshTokenV2.accessToken;
      
      // Retry with new token
      console.log('Retrying with new token...');
      result = await gql(accessToken, `{ project(id: "${projectId}") { services(first: 10) { edges { node { id name } } } } }`);
      console.log('Result with new token:', JSON.stringify(result, null, 2));
    }
  }
  
  if (result?.data?.project?.services?.edges?.length > 0) {
    const serviceId = result.data.project.services.edges[0].node.id;
    console.log(`\n=== Deploying service ${serviceId} ===`);
    
    const deploy = await gql(accessToken, `mutation { serviceInstanceDeploy(serviceId: "${serviceId}", environmentId: "${envId}") }`);
    console.log('Deploy result:', JSON.stringify(deploy, null, 2));
  }
}

main().catch(console.error);