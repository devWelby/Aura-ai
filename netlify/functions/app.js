require('ejs');
const serverless = require('serverless-http');
const { createApp } = require('../../src/app');

const useMysqlSessions = String(process.env.NETLIFY_USE_MYSQL_SESSIONS || '0') === '1';

const app = createApp({
  // Netlify Functions should not depend on MySQL session store during cold start.
  // Set NETLIFY_USE_MYSQL_SESSIONS=1 only after validating remote DB connectivity.
  usePersistentSessionStore: useMysqlSessions,
  enableDbHealthcheck: false,
});

module.exports.handler = serverless(app, {
  binary: [
    'multipart/form-data',
    'application/pdf',
    'application/octet-stream',
  ],
});
