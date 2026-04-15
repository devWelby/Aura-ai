require('./config/env');

const path = require('path');
const crypto = require('crypto');
const express = require('express');
const session = require('express-session');
const MySQLStoreFactory = require('express-mysql-session');
const rateLimit = require('express-rate-limit');
const cookieParser = require('cookie-parser');
const helmet = require('helmet');
const hpp = require('hpp');
const { appEnv } = require('./config/env');
const sessionSecurity = require('./middleware/sessionSecurity');
const securityShield = require('./middleware/securityShield');
const { adsenseClientId, adsenseSlot, canShowAds, appBaseUrl } = require('./utils/helpers');
const { ensureCsrfToken } = require('./utils/security');
const authRouter = require('./routes/auth');
const { appRouter } = require('./routes/app');
const reportRouter = require('./routes/reports');
const paymentRouter = require('./routes/payments');
const pool = require('./config/db');
const { checkFirebaseHealth } = require('./config/firebase');

function shouldSendUpgradeInsecureRequests() {
  if (appEnv !== 'production') {
    return false;
  }

  if (String(process.env.ENFORCE_HTTPS || '1') === '0') {
    return false;
  }

  const explicitAppUrl = String(process.env.APP_URL || '').trim();
  if (explicitAppUrl) {
    try {
      const host = new URL(explicitAppUrl).hostname.toLowerCase();
      if (host === 'localhost' || host === '127.0.0.1' || host === '::1') {
        return false;
      }
    } catch (err) {
      // Ignore invalid APP_URL and keep secure defaults in production.
    }
  }

  return true;
}

const cspDirectives = {
  defaultSrc: ["'self'"],
  baseUri: ["'self'"],
  frameAncestors: ["'none'"],
  objectSrc: ["'none'"],
  scriptSrc: [
    "'self'",
    (req, res) => `'nonce-${res.locals.cspNonce}'`,
    'https://pagead2.googlesyndication.com',
  ],
  styleSrc: ["'self'", "'unsafe-inline'"],
  imgSrc: ["'self'", 'data:', 'https:'],
  fontSrc: ["'self'", 'data:', 'https:'],
  connectSrc: ["'self'", 'https://pagead2.googlesyndication.com'],
  frameSrc: ["'self'", 'https://googleads.g.doubleclick.net'],
  formAction: ["'self'"],
  ...(shouldSendUpgradeInsecureRequests() ? { upgradeInsecureRequests: [] } : {}),
};

function createApp(options = {}) {
  const {
    usePersistentSessionStore = true,
    enableDbHealthcheck = true,
  } = options;

  if (appEnv === 'production' && !process.env.SESSION_SECRET) {
    throw new Error('SESSION_SECRET obrigatorio em producao.');
  }

  const app = express();

  if (appEnv === 'production') {
    app.set('trust proxy', 1);
  }

  const globalLimiter = rateLimit({
    windowMs: 15 * 60 * 1000,
    max: 300,
    standardHeaders: true,
    legacyHeaders: false,
    message: 'Muitas requisicoes. Tente novamente em alguns minutos.',
  });

  let sessionStore;
  if (usePersistentSessionStore) {
    const MySQLStore = MySQLStoreFactory(session);
    sessionStore = new MySQLStore({
      host: process.env.DB_HOST || 'localhost',
      port: Number(process.env.DB_PORT || 3306),
      user: process.env.DB_USER || 'root',
      password: process.env.DB_PASS || '',
      database: process.env.DB_NAME || 'analista_financeiro_db',
      clearExpired: true,
      checkExpirationInterval: 15 * 60 * 1000,
      expiration: 24 * 60 * 60 * 1000,
      createDatabaseTable: true,
      schema: {
        tableName: 'sessions',
        columnNames: {
          session_id: 'session_id',
          expires: 'expires',
          data: 'data',
        },
      },
    });

    sessionStore.on('error', (err) => {
      if (appEnv !== 'production') {
        console.warn('[session-store] erro na store MySQL:', err.message);
      } else {
        console.error('Session store error:', err.message);
      }
    });
  }

  app.disable('x-powered-by');
  app.use((req, res, next) => {
    res.locals.cspNonce = crypto.randomBytes(16).toString('base64');
    next();
  });

  app.use(helmet({
    contentSecurityPolicy: {
      useDefaults: true,
      directives: cspDirectives,
    },
    crossOriginEmbedderPolicy: false,
    crossOriginOpenerPolicy: { policy: 'same-origin' },
    crossOriginResourcePolicy: { policy: 'same-site' },
    hsts: appEnv === 'production'
      ? {
        maxAge: 31536000,
        includeSubDomains: true,
        preload: true,
      }
      : false,
    noSniff: true,
    referrerPolicy: { policy: 'strict-origin-when-cross-origin' },
    permittedCrossDomainPolicies: { permittedPolicies: 'none' },
    frameguard: { action: 'deny' },
  }));
  app.use(hpp());
  app.use(securityShield);
  app.use(globalLimiter);
  app.use(express.urlencoded({ extended: true, limit: '6mb' }));
  app.use(express.json({
    limit: '6mb',
    verify: (req, res, buf) => {
      if (req.path === '/webhook.php' || req.path === '/pagamentos/webhook') {
        req.rawBody = buf;
      }
    },
  }));
  app.use(cookieParser());

  const sessionConfig = {
    secret: (() => {
      const sessionSecret = String(process.env.SESSION_SECRET || '').trim();
      const cookieSecret = String(process.env.COOKIE_SIGNING_SECRET || '').trim();
      if (sessionSecret) {
        return sessionSecret;
      }
      if (cookieSecret) {
        return cookieSecret;
      }

      // Avoid fixed fallback secrets in local/dev environments.
      return crypto
        .createHash('sha256')
        .update(`${Date.now()}:${process.pid}:${Math.random()}`)
        .digest('hex');
    })(),
    resave: false,
    saveUninitialized: false,
    cookie: {
      maxAge: 24 * 60 * 60 * 1000,
      secure: appEnv === 'production',
      httpOnly: true,
      sameSite: 'lax',
    },
  };

  if (sessionStore) {
    sessionConfig.store = sessionStore;
  }

  const fallbackSessionConfig = {
    ...sessionConfig,
  };
  delete fallbackSessionConfig.store;

  app.use(session(sessionConfig));
  const fallbackSessionMiddleware = session(fallbackSessionConfig);

  // Se a store MySQL não conseguir conectar, o express-session chama next(err).
  // Recuperamos criando uma sessão efêmera em memória para não derrubar o servidor.
  const DB_SESSION_ERR_CODES = new Set([
    'ECONNREFUSED', 'ETIMEDOUT', 'ENOTFOUND',
    'PROTOCOL_CONNECTION_LOST', 'ER_ACCESS_DENIED_ERROR',
  ]);
  app.use((err, req, res, next) => {
    if (!err || !DB_SESSION_ERR_CODES.has(err.code)) {
      return next(err);
    }
    if (appEnv === 'production') {
      return next(err);
    }
    console.warn('[session-store] MySQL indisponivel — fallback para sessao em memoria:', err.message);

    return fallbackSessionMiddleware(req, res, next);
  });

  app.use((err, req, res, next) => {
    if (!err || appEnv === 'production') {
      return next(err);
    }
    if (DB_SESSION_ERR_CODES.has(err.code)) {
      console.warn('[session-store] erro de persistencia de sessao ignorado em dev:', err.message);
      return next();
    }
    return next(err);
  });

  app.use(sessionSecurity);

  app.set('view engine', 'ejs');
  app.set('views', path.join(__dirname, '..', 'views'));
  app.get('/favicon.ico', (req, res) => {
    res.status(204).end();
  });
  app.use('/assets', express.static(path.join(__dirname, '..', 'assets')));
  app.use('/vendor/chart.js', express.static(path.join(__dirname, '..', 'node_modules', 'chart.js', 'dist')));
  app.use('/vendor/html2pdf.js', express.static(path.join(__dirname, '..', 'node_modules', 'html2pdf.js', 'dist')));

  app.use((req, res, next) => {
    res.locals.session = req.session;
    res.locals.csrfToken = ensureCsrfToken(req);
    res.locals.appUrl = appBaseUrl(req);
    res.locals.showAds = canShowAds(req);
    res.locals.adsenseClientId = adsenseClientId();
    res.locals.adsHeader = adsenseSlot('header');
    res.locals.adsFooter = adsenseSlot('footer');
    res.locals.activePath = req.path;
    next();
  });

  app.get('/healthz', async (req, res) => {
    const health = {
      status: 'ok',
      app: 'aura-ai',
      uptime: process.uptime(),
      timestamp: new Date().toISOString(),
      db: 'skipped',
      firebase: 'skipped',
    };

    if (enableDbHealthcheck) {
      try {
        await pool.query('SELECT 1');
        health.db = 'ok';
      } catch (err) {
        health.status = 'degraded';
        health.db = 'fail';
        return res.status(503).json(health);
      }
    }

    const firebaseHealth = await checkFirebaseHealth();
    health.firebase = firebaseHealth.status;
    if (firebaseHealth.enabled && firebaseHealth.status !== 'ok') {
      health.status = 'degraded';
      health.firebase_error = firebaseHealth.error || 'firebase indisponivel';
      return res.status(503).json(health);
    }

    return res.status(200).json(health);
  });

  app.use(authRouter);
  app.use(appRouter);
  app.use(reportRouter);
  app.use(paymentRouter);

  app.use((req, res) => {
    res.status(404).send('Rota nao encontrada.');
  });

  app.use((err, req, res, next) => {
    const isDbConnErr = err && DB_SESSION_ERR_CODES && DB_SESSION_ERR_CODES.has(err.code);
    if (appEnv !== 'production') {
      if (isDbConnErr) {
        console.warn('[db] conexao recusada (MySQL indisponivel):', err.message);
      } else {
        console.error(err);
      }
    } else if (!isDbConnErr) {
      console.error(err.message || err);
    }
    if (res.headersSent) {
      return next(err);
    }
    res.status(500).send('Erro interno no servidor.');
  });

  return app;
}

module.exports = { createApp };
