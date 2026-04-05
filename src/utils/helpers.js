const pool = require('../config/db');

function sanitize(value) {
  if (value === null || value === undefined) {
    return '';
  }

  return String(value)
    .trim()
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/\"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function adsenseClientId() {
  const client = String(process.env.ADSENSE_CLIENT_ID || '').trim();
  return /^ca-pub-[0-9]{10,20}$/.test(client) ? client : '';
}

function adsenseSlot(slotName) {
  const map = {
    header: 'ADSENSE_SLOT_HEADER',
    incontent: 'ADSENSE_SLOT_INCONTENT',
    footer: 'ADSENSE_SLOT_FOOTER',
  };

  const key = map[slotName];
  if (!key) {
    return '';
  }

  const value = String(process.env[key] || '').trim();
  return /^[0-9]{6,20}$/.test(value) ? value : '';
}

function canShowAds(req) {
  const enabled = ['1', 'true', 'yes', 'on'].includes(String(process.env.ADSENSE_ENABLED || '').toLowerCase());
  if (!enabled || !adsenseClientId()) {
    return false;
  }

  const hideForPro = !['0', 'false', 'no', 'off'].includes(String(process.env.ADSENSE_HIDE_FOR_PRO || '1').toLowerCase());
  if (hideForPro && req.session.usuario_plano === 'pro') {
    return false;
  }

  return true;
}

function appBaseUrl(req) {
  const explicit = String(process.env.APP_URL || '').trim();
  if (explicit) {
    return explicit.replace(/\/$/, '');
  }

  const protocol = req.secure ? 'https' : 'http';
  return `${protocol}://${req.get('host')}`;
}

function formatCurrency(value) {
  return Number(value || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function isWebhookPath(path) {
  const value = String(path || '');
  return value === '/webhook.php' || value === '/pagamentos/webhook';
}

async function freeAnalysesRemainingUser(usuarioId, limit = 2) {
  if (!usuarioId || limit <= 0) {
    return 0;
  }

  const [rows] = await pool.execute(
    'SELECT COUNT(*) AS total FROM historico_analises WHERE usuario_id = ? AND YEAR(criado_em) = YEAR(CURDATE()) AND MONTH(criado_em) = MONTH(CURDATE())',
    [usuarioId],
  );

  const total = Number(rows[0]?.total || 0);
  return Math.max(0, limit - total);
}

module.exports = {
  sanitize,
  adsenseClientId,
  adsenseSlot,
  canShowAds,
  appBaseUrl,
  formatCurrency,
  isWebhookPath,
  freeAnalysesRemainingUser,
};
