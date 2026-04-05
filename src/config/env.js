const dotenv = require('dotenv');

dotenv.config();

function boolEnv(key, defaultValue = false) {
  const raw = process.env[key];
  if (!raw) {
    return Boolean(defaultValue);
  }

  const normalized = String(raw).trim().toLowerCase();
  return ['1', 'true', 'yes', 'on'].includes(normalized);
}

module.exports = {
  boolEnv,
  appEnv: process.env.APP_ENV || 'development',
};
