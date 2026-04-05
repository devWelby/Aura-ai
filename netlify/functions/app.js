const serverless = require('serverless-http');
const { createApp } = require('../../src/app');

const app = createApp({
  usePersistentSessionStore: true,
  enableDbHealthcheck: true,
});

module.exports.handler = serverless(app, {
  binary: [
    'multipart/form-data',
    'application/pdf',
    'application/octet-stream',
  ],
});
