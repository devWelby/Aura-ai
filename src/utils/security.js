const crypto = require('crypto');

function ensureCsrfToken(req) {
  if (!req.session.csrfToken || req.session.csrfToken.length < 32) {
    req.session.csrfToken = crypto.randomBytes(32).toString('hex');
  }
  return req.session.csrfToken;
}

function validateCsrfPost(req, res, next) {
  if (req.method !== 'POST') {
    return next();
  }

  const received = typeof req.body.csrf_token === 'string' ? req.body.csrf_token : '';
  const sessionToken = typeof req.session.csrfToken === 'string' ? req.session.csrfToken : '';

  if (!received || !sessionToken) {
    return res.status(403).send('Falha de validacao de seguranca. Recarregue a pagina e tente novamente.');
  }

  if (received.length !== sessionToken.length) {
    return res.status(403).send('Falha de validacao de seguranca. Recarregue a pagina e tente novamente.');
  }

  const valid = crypto.timingSafeEqual(Buffer.from(sessionToken), Buffer.from(received));
  if (!valid) {
    return res.status(403).send('Falha de validacao de seguranca. Recarregue a pagina e tente novamente.');
  }

  return next();
}

function readSignedFreeUsage(req) {
  const raw = req.cookies.uso_gratuito;
  if (!raw || typeof raw !== 'string' || !raw.includes('.')) {
    return 0;
  }

  const [value, signature] = raw.split('.', 2);
  if (!value || !signature) {
    return 0;
  }

  const secret = process.env.COOKIE_SIGNING_SECRET || '';
  if (!secret) {
    return 0;
  }

  const expected = crypto.createHmac('sha256', secret).update(value).digest('hex');
  if (expected.length !== signature.length) {
    return 0;
  }

  const valid = crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(signature));
  if (!valid) {
    return 0;
  }

  const parsed = Number.parseInt(value, 10);
  return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
}

function saveSignedFreeUsage(req, res, usage) {
  const secret = process.env.COOKIE_SIGNING_SECRET || '';
  if (!secret) {
    return;
  }

  const safeUsage = Math.max(0, Number.parseInt(usage, 10) || 0);
  const value = String(safeUsage);
  const signature = crypto.createHmac('sha256', secret).update(value).digest('hex');

  res.cookie('uso_gratuito', `${value}.${signature}`, {
    maxAge: 30 * 24 * 60 * 60 * 1000,
    httpOnly: true,
    secure: process.env.APP_ENV === 'production',
    sameSite: 'lax',
    path: '/',
  });
}

module.exports = {
  ensureCsrfToken,
  validateCsrfPost,
  readSignedFreeUsage,
  saveSignedFreeUsage,
};
