const https = require('https');
const token = 'IxBrl3whaZ7YMvGpLW5eyGdx-cWmXdleo8aNUMvPN4_';
const serviceId = '36083f24-fef8-4f75-ae18-a4ecf8cd7237';
const envId = 'eb5e476e-6773-469d-888b-79e05d8c4229';

function gql(query) {
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
        catch { resolve(body); }
      });
    });
    req.on('error', reject);
    req.write(data);
    req.end();
  });
}

async function main() {
  // Get latest deployments
  const result = await gql(`{ deployments(serviceId: "${serviceId}", first: 3) { edges { node { id status createdAt } } } }`);
  console.log(JSON.stringify(result, null, 2));
}

main().catch(console.error);