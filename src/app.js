require('./config/env');

const path = require('path');
const express = require('express');
const session = require('express-session');
const MySQLStoreFactory = require('express-mysql-session');
const rateLimit = require('express-rate-limit');
const cookieParser = require('cookie-parser');
const helmet = require('helmet');
const hpp = require('hpp');
const { appEnv } = require('./config/env');
const sessionSecurity = require('./middleware/sessionSecurity');
const { adsenseClientId, adsenseSlot, canShowAds } = require('./utils/helpers');
const { ensureCsrfToken } = require('./utils/security');
const authRouter = require('./routes/auth');
const { appRouter } = require('./routes/app');
const reportRouter = require('./routes/reports');
const paymentRouter = require('./routes/payments');
const pool = require('./config/db');
const { checkFirebaseHealth } = require('./config/firebase');

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
      console.error('Session store error:', err.message);
    });
  }

  app.disable('x-powered-by');
  app.use(helmet({ contentSecurityPolicy: false }));
  app.use(hpp());
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
    secret: process.env.SESSION_SECRET || process.env.COOKIE_SIGNING_SECRET || 'aura-ai-dev-secret',
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

  app.use(session(sessionConfig));
  app.use(sessionSecurity);

  app.set('view engine', 'ejs');
  app.set('views', path.join(__dirname, '..', 'views'));
  app.use('/assets', express.static(path.join(__dirname, '..', 'assets')));

  app.use((req, res, next) => {
    res.locals.session = req.session;
    res.locals.csrfToken = ensureCsrfToken(req);
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
    if (appEnv !== 'production') {
      console.error(err);
    }
    res.status(500).send('Erro interno no servidor.');
  });

  return app;
}

module.exports = { createApp };
