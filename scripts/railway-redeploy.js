const https = require('https');
const token = 'IxBrl3whaZ7YMvGpLW5eyGdx-cWmXdleo8aNUMvPN4_';
const projectId = 'e6c4d768-6d4f-4e42-b37c-b9c343bd84d1';

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
  // 1. Get services
  console.log('=== Getting services ===');
  const services = await gql(`{ project(id: "${projectId}") { services(first: 10) { edges { node { id name } } } } }`);
  console.log(JSON.stringify(services, null, 2));
  
  if (services?.data?.project?.services?.edges?.length > 0) {
    const serviceId = services.data.project.services.edges[0].node.id;
    console.log(`\n=== Deploying service ${serviceId} ===`);
    
    const envId = 'eb5e476e-6773-469d-888b-79e05d8c4229';
    // 2. Trigger redeploy
    const deploy = await gql(`mutation { serviceInstanceDeploy(serviceId: "${serviceId}", environmentId: "${envId}") }`);
    console.log('serviceInstanceDeploy:', JSON.stringify(deploy, null, 2));
  }
}

main().catch(console.error);