const express = require('express');
const pool = require('../config/db');
const { ensureCsrfToken, readSignedFreeUsage } = require('../utils/security');

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

  return res.render('app/index', {
    pageTitle: 'Dashboard - Analista IA',
    isLogged,
    nomeUsuario,
    planoUsuario,
    limiteGratuito,
    analisesUsadas,
    analisesRestantes,
    temAcesso,
    csrfToken: ensureCsrfToken(req),
  });
});

module.exports = {
  appRouter: router,
  freeAnalysesRemainingUser,
};
