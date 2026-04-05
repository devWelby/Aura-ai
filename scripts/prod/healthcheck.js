require('dotenv').config();

const axios = require('axios');

async function run() {
  const baseUrl = String(process.env.APP_URL || 'http://localhost:3000').replace(/\/$/, '');
  const url = `${baseUrl}/healthz`;

  try {
    const response = await axios.get(url, { timeout: 10000 });
    if (response.status !== 200 || response.data.status !== 'ok') {
      console.error(`HEALTHCHECK_FAIL: status=${response.status} bodyStatus=${response.data.status}`);
      process.exit(1);
    }

    console.log('HEALTHCHECK_OK');
    process.exit(0);
  } catch (err) {
    console.error('HEALTHCHECK_FAIL:', err.message);
    process.exit(1);
  }
}

run();
