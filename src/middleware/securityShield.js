const { isWebhookPath } = require('../utils/helpers');

function parseAllowedHosts() {
  const raw = String(process.env.ALLOWED_HOSTS || '').trim();
  if (!raw) {
    return [];
  }

  return raw
    .split(',')
    .map((item) => item.trim().toLowerCase())
    .filter(Boolean);
}

function isProduction() {
  return String(process.env.APP_ENV || '').toLowerCase() === 'production';
}

function shouldEnforceHttps() {
  return isProduction() && String(process.env.ENFORCE_HTTPS || '1') !== '0';
}

function extractHostFromUrl(value) {
  try {
    const parsed = new URL(String(value || ''));
    return String(parsed.host || '').toLowerCase();
  } catch (err) {
    return '';
  }
}

function isMutatingMethod(method) {
  return ['POST', 'PUT', 'PATCH', 'DELETE'].includes(String(method || '').toUpperCase());
}

function securityShield(req, res, next) {
  const allowedHosts = parseAllowedHosts();
  const requestHost = String(req.get('host') || '').toLowerCase();

  if (isProduction() && allowedHosts.length > 0 && !allowedHosts.includes(requestHost)) {
    return res.status(400).send('Host nao permitido.');
  }

  if (shouldEnforceHttps()) {
    const xForwardedProto = String(req.get('x-forwarded-proto') || '').toLowerCase();
    const isHttps = req.secure || xForwardedProto === 'https';
    if (!isHttps) {
      const redirectUrl = `https://${requestHost}${req.originalUrl}`;
      return res.redirect(301, redirectUrl);
    }
  }

  if (isProduction() && isMutatingMethod(req.method) && !isWebhookPath(req.path)) {
    const originHost = extractHostFromUrl(req.get('origin'));
    const refererHost = extractHostFromUrl(req.get('referer'));
    const sourceHost = originHost || refererHost;

    if (sourceHost) {
      const validHost = sourceHost === requestHost || (allowedHosts.length > 0 && allowedHosts.includes(sourceHost));
      if (!validHost) {
        return res.status(403).send('Origem da requisicao nao permitida.');
      }
    }
  }

  return next();
}

module.exports = securityShield;
