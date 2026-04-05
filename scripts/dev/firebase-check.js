require('dotenv').config();

const { checkFirebaseHealth } = require('../../src/config/firebase');

async function run() {
  const result = await checkFirebaseHealth();

  if (!result.enabled) {
    console.error('FIREBASE_CHECK_SKIPPED: Firebase nao configurado no .env');
    console.error('Defina FIREBASE_SERVICE_ACCOUNT_PATH ou FIREBASE_SERVICE_ACCOUNT_JSON.');
    process.exit(1);
  }

  if (result.status !== 'ok') {
    console.error(`FIREBASE_CHECK_FAIL: ${result.error || 'erro desconhecido'}`);
    process.exit(1);
  }

  console.log('FIREBASE_CHECK_OK');
  process.exit(0);
}

run().catch((err) => {
  console.error(`FIREBASE_CHECK_FAIL: ${err.message}`);
  process.exit(1);
});
