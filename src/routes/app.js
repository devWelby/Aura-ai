const express = require('express');
const pool = require('../config/db');
const { ensureCsrfToken, readSignedFreeUsage, validateCsrfPost } = require('../utils/security');

const router = express.Router();

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

async function ensureUserPreferencesTable() {
  await pool.execute(
    `CREATE TABLE IF NOT EXISTS usuario_preferencias (
      usuario_id INT NOT NULL PRIMARY KEY,
      meta_economia_mensal DECIMAL(12,2) NOT NULL DEFAULT 0,
      atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
  );
}

async function readSavingsGoal(usuarioId) {
  await ensureUserPreferencesTable();
  const [rows] = await pool.execute(
    'SELECT meta_economia_mensal FROM usuario_preferencias WHERE usuario_id = ? LIMIT 1',
    [usuarioId],
  );
  return Number(rows[0]?.meta_economia_mensal || 0);
}

async function writeSavingsGoal(usuarioId, goal) {
  await ensureUserPreferencesTable();
  const safeGoal = Math.max(0, Number(goal || 0));
  await pool.execute(
    `INSERT INTO usuario_preferencias (usuario_id, meta_economia_mensal)
     VALUES (?, ?)
     ON DUPLICATE KEY UPDATE meta_economia_mensal = VALUES(meta_economia_mensal)`,
    [usuarioId, safeGoal],
  );
}

async function monthSavingsProgress(usuarioId) {
  const [rows] = await pool.execute(
    `SELECT
      COALESCE(SUM(entradas), 0) AS entradas,
      COALESCE(SUM(saidas), 0) AS saidas,
      COALESCE(SUM(saldo), 0) AS saldo
     FROM historico_analises
     WHERE usuario_id = ?
       AND YEAR(criado_em) = YEAR(CURDATE())
       AND MONTH(criado_em) = MONTH(CURDATE())`,
    [usuarioId],
  );

  return {
    entradas: Number(rows[0]?.entradas || 0),
    saidas: Number(rows[0]?.saidas || 0),
    saldo: Number(rows[0]?.saldo || 0),
  };
}

router.post(['/dashboard/meta-economia', '/index/meta-economia'], validateCsrfPost, async (req, res) => {
  const isLogged = Boolean(req.session.usuario_id);
  if (!isLogged) {
    return res.redirect('/login.php');
  }

  const rawGoal = String(req.body.meta_economia_mensal || '').replace(',', '.');
  const parsed = Number.parseFloat(rawGoal);
  const goal = Number.isFinite(parsed) ? parsed : 0;

  await writeSavingsGoal(Number(req.session.usuario_id), goal);
  return res.redirect('/index.php?meta=salva');
});

router.get(['/', '/index.php', '/dashboard'], async (req, res) => {
  const isLogged = Boolean(req.session.usuario_id);
  const nomeUsuario = isLogged ? req.session.usuario_nome : 'Visitante';

  let planoUsuario = 'visitante';
  if (isLogged) {
    const [rows] = await pool.execute('SELECT plano FROM usuarios WHERE id = ?', [req.session.usuario_id]);
    planoUsuario = String(rows[0]?.plano || 'gratis');
    req.session.usuario_plano = planoUsuario;
  }

  const limiteGratuito = 2;
  let analisesUsadas = readSignedFreeUsage(req);
  let analisesRestantes = Math.max(0, limiteGratuito - analisesUsadas);

  if (isLogged && planoUsuario !== 'pro') {
    analisesRestantes = await freeAnalysesRemainingUser(Number(req.session.usuario_id), limiteGratuito);
    analisesUsadas = Math.max(0, limiteGratuito - analisesRestantes);
  }

  const temAcesso = (isLogged && planoUsuario === 'pro') || analisesRestantes > 0;

  let metaEconomiaMensal = 0;
  let progressoMeta = {
    entradas: 0,
    saidas: 0,
    saldo: 0,
    percentual: 0,
  };

  if (isLogged) {
    metaEconomiaMensal = await readSavingsGoal(Number(req.session.usuario_id));
    const monthProgress = await monthSavingsProgress(Number(req.session.usuario_id));
    const percentual = metaEconomiaMensal > 0
      ? Math.max(0, Math.min(100, (monthProgress.saldo / metaEconomiaMensal) * 100))
      : 0;

    progressoMeta = {
      ...monthProgress,
      percentual,
    };
  }

  return res.render('app/index', {
    pageTitle: 'Dashboard - Analista IA',
    isLogged,
    nomeUsuario,
    planoUsuario,
    limiteGratuito,
    analisesUsadas,
    analisesRestantes,
    temAcesso,
    metaEconomiaMensal,
    progressoMeta,
    metaSalva: String(req.query.meta || '') === 'salva',
    csrfToken: ensureCsrfToken(req),
  });
});

module.exports = {
  appRouter: router,
  freeAnalysesRemainingUser,
};
