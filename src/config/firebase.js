const fs = require('fs');
const path = require('path');
const admin = require('firebase-admin');

let firebaseApp;

function resolveCredentials() {
  const rawJson = String(process.env.FIREBASE_SERVICE_ACCOUNT_JSON || '').trim();
  if (rawJson) {
    return JSON.parse(rawJson);
  }

  const inputPath = String(
    process.env.FIREBASE_SERVICE_ACCOUNT_PATH || process.env.GOOGLE_APPLICATION_CREDENTIALS || '',
  ).trim();

  if (!inputPath) {
    return null;
  }

  const absolutePath = path.isAbsolute(inputPath)
    ? inputPath
    : path.resolve(process.cwd(), inputPath);

  const content = fs.readFileSync(absolutePath, 'utf-8');
  return JSON.parse(content);
}

function isFirebaseConfigured() {
  return Boolean(
    String(process.env.FIREBASE_SERVICE_ACCOUNT_JSON || '').trim()
    || String(process.env.FIREBASE_SERVICE_ACCOUNT_PATH || '').trim()
    || String(process.env.GOOGLE_APPLICATION_CREDENTIALS || '').trim(),
  );
}

function getFirebaseApp() {
  if (firebaseApp) {
    return firebaseApp;
  }

  if (!isFirebaseConfigured()) {
    return null;
  }

  const credentials = resolveCredentials();
  if (!credentials) {
    return null;
  }

  firebaseApp = admin.initializeApp({
    credential: admin.credential.cert(credentials),
    projectId: process.env.FIREBASE_PROJECT_ID || credentials.project_id,
  });

  return firebaseApp;
}

function getFirestore() {
  const app = getFirebaseApp();
  if (!app) {
    return null;
  }
  return admin.firestore(app);
}

async function checkFirebaseHealth() {
  if (!isFirebaseConfigured()) {
    return { enabled: false, status: 'skipped' };
  }

  try {
    const db = getFirestore();
    if (!db) {
      return { enabled: false, status: 'skipped' };
    }

    await db.collection('_healthcheck').limit(1).get();
    return { enabled: true, status: 'ok' };
  } catch (err) {
    return { enabled: true, status: 'fail', error: err.message };
  }
}

module.exports = {
  isFirebaseConfigured,
  getFirebaseApp,
  getFirestore,
  checkFirebaseHealth,
};
