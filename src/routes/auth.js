const express = require('express');
const crypto = require('crypto');
const bcrypt = require('bcryptjs');
const rateLimit = require('express-rate-limit');
const { google } = require('googleapis');
const { Resend } = require('resend');
const pool = require('../config/db');
const { ensureCsrfToken, validateCsrfPost } = require('../utils/security');
const { sanitize, appBaseUrl } = require('../utils/helpers');
const { asyncHandler } = require('../utils/asyncHandler');

const router = express.Router();

function getGoogleRedirectUri(req) {
  const configured = String(process.env.GOOGLE_REDIRECT_URI || '').trim();
  if (configured) {
    return configured.replace(/\/$/, '');
  }

  return `${appBaseUrl(req)}/callback_google.php`;
}

const authLimiter = rateLimit({
  windowMs: 15 * 60 * 1000,
  max: 40,
  standardHeaders: true,
  legacyHeaders: false,
  message: 'Muitas requisicoes de autenticacao. Tente novamente em alguns minutos.',
});

router.get(['/login.php', '/auth/login'], (req, res) => {
  if (req.session.usuario_id) {
    return res.redirect('/index.php');
  }

  const googleErrors = {
    google_cancelado: 'Login com Google cancelado.',
    google_state_invalido: 'Sessao do Google expirou. Tente novamente.',
    google_dados_invalidos: 'Nao foi possivel obter seus dados do Google.',
    google_conta_conflito: 'Este e-mail ja esta vinculado a outra conta Google.',
    google_falha_autenticacao: 'Falha ao autenticar com Google. Tente novamente.',
    google_config_invalida: 'Login Google indisponivel: verifique as configuracoes OAuth.',
  };

  const success = req.query.msg === 'conta_ativada'
    ? 'Conta ativada com sucesso! Agora voce pode fazer login.'
    : '';

  const erro = googleErrors[String(req.query.erro || '')] || '';

  return res.render('auth/login', {
    pageTitle: 'Login - Analista IA',
    erro,
    sucesso: success,
    csrfToken: ensureCsrfToken(req),
  });
});

router.post(['/login.php', '/auth/login'], authLimiter, validateCsrfPost, asyncHandler(async (req, res) => {
  if (req.session.usuario_id) {
    return res.redirect('/index.php');
  }

  if (!req.session.login_bf) {
    req.session.login_bf = { count: 0, locked_until: 0 };
  }

  const bf = req.session.login_bf;
  if (bf.locked_until > 0 && Date.now() >= bf.locked_until) {
    bf.count = 0;
    bf.locked_until = 0;
  }

  if (Date.now() < bf.locked_until) {
    const remaining = Math.ceil((bf.locked_until - Date.now()) / 1000);
    return res.render('auth/login', {
      pageTitle: 'Login - Analista IA',
      erro: `Muitas tentativas incorretas. Aguarde ${remaining} segundo(s) e tente novamente.`,
      sucesso: '',
      csrfToken: ensureCsrfToken(req),
    });
  }

  const email = sanitize(req.body.email || '').toLowerCase();
  const senha = String(req.body.senha || '').trim();

  const [rows] = await pool.execute(
    'SELECT id, nome, senha, plano, email_verificado FROM usuarios WHERE email = ?',
    [email],
  );

  const usuario = rows[0];
  if (usuario && await bcrypt.compare(senha, String(usuario.senha || ''))) {
    if (Number(usuario.email_verificado || 0) === 0) {
      return res.render('auth/login', {
        pageTitle: 'Login - Analista IA',
        erro: 'Sua conta ainda nao foi ativada. Verifique sua caixa de entrada ou spam.',
        sucesso: '',
        csrfToken: ensureCsrfToken(req),
      });
    }

    req.session.regenerate((err) => {
      if (err) {
        return res.status(500).send('Erro interno ao autenticar.');
      }

      req.session.usuario_id = Number(usuario.id);
      req.session.usuario_nome = usuario.nome;
      req.session.usuario_plano = usuario.plano || 'gratis';
      req.session.createdAt = Date.now();
      return res.redirect('/index.php');
    });

    return;
  }

  bf.count += 1;
  let erro = '';
  if (bf.count >= 5) {
    bf.locked_until = Date.now() + 15 * 60 * 1000;
    bf.count = 0;
    erro = 'Muitas tentativas incorretas. Conta bloqueada por 15 minutos.';
  } else {
    const remaining = 5 - bf.count;
    erro = `E-mail ou senha incorretos. ${remaining} tentativa(s) restante(s) antes do bloqueio.`;
  }

  return res.render('auth/login', {
    pageTitle: 'Login - Analista IA',
    erro,
    sucesso: '',
    csrfToken: ensureCsrfToken(req),
  });
}));

router.get(['/cadastro.php', '/auth/cadastro'], (req, res) => {
  if (req.session.usuario_id) {
    return res.redirect('/index.php');
  }

  return res.render('auth/cadastro', {
    pageTitle: 'Cadastro - Analista IA',
    erro: '',
    sucesso: '',
    csrfToken: ensureCsrfToken(req),
  });
});

router.post(['/cadastro.php', '/auth/cadastro'], authLimiter, validateCsrfPost, asyncHandler(async (req, res) => {
  if (req.session.usuario_id) {
    return res.redirect('/index.php');
  }

  const nome = sanitize(req.body.nome || '');
  const email = sanitize(req.body.email || '').toLowerCase();
  const senha = String(req.body.senha || '').trim();

  let erro = '';
  let sucesso = '';

  if (!nome || !email || !senha) {
    erro = 'Preencha todos os campos.';
  } else if (!/^\S+@\S+\.\S+$/.test(email)) {
    erro = 'Informe um e-mail valido.';
  } else if (senha.length < 8) {
    erro = 'A senha deve ter no minimo 8 caracteres.';
  } else if (!/[A-Z]/.test(senha) || !/[0-9]/.test(senha)) {
    erro = 'A senha deve conter pelo menos uma letra maiuscula e um numero.';
  }

  if (!erro) {
    const [existing] = await pool.execute('SELECT id FROM usuarios WHERE email = ?', [email]);
    if (existing.length > 0) {
      erro = 'Este e-mail ja esta cadastrado.';
    } else {
      const senhaHash = await bcrypt.hash(senha, 10);
      const token = crypto.randomBytes(32).toString('hex');

      await pool.execute(
        "INSERT INTO usuarios (nome, email, senha, token_verificacao, plano) VALUES (?, ?, ?, ?, 'gratis')",
        [nome, email, senhaHash, token],
      );

      try {
        const resendApi = String(process.env.RESEND_API_KEY || '');
        if (resendApi) {
          const resend = new Resend(resendApi);
          const urlBase = String(process.env.URL_BASE || appBaseUrl(req)).replace(/\/$/, '');
          const link = `${urlBase}/verificar_email.php?token=${encodeURIComponent(token)}`;
          await resend.emails.send({
            from: 'Analista IA <onboarding@resend.dev>',
            to: [email],
            subject: 'Ative sua conta - Analista IA',
            html: `<h2>Ola, ${nome}!</h2><p>Falta pouco para voce analisar suas financas com IA.</p><p><a href="${link}">Clique aqui para ativar sua conta</a></p>`,
          });
          sucesso = 'Cadastro realizado! Verifique seu e-mail para ativar a conta antes de fazer login.';
        } else {
          sucesso = 'Cadastro realizado! Defina RESEND_API_KEY para enviar e-mail de ativacao automaticamente.';
        }
      } catch (sendError) {
        sucesso = 'Cadastro realizado! Nao foi possivel enviar o e-mail agora. Contate o suporte para concluir a ativacao.';
      }
    }
  }

  return res.render('auth/cadastro', {
    pageTitle: 'Cadastro - Analista IA',
    erro,
    sucesso,
    csrfToken: ensureCsrfToken(req),
  });
}));

router.post(['/logout.php', '/auth/logout'], validateCsrfPost, (req, res) => {
  req.session.regenerate(() => {
    res.clearCookie('connect.sid');
    return res.redirect('/login.php');
  });
});

router.get(['/login_google.php', '/auth/login-google'], (req, res) => {
  if (req.session.usuario_id) {
    return res.redirect('/index.php');
  }

  const clientId = String(process.env.GOOGLE_CLIENT_ID || '').trim();
  const clientSecret = String(process.env.GOOGLE_CLIENT_SECRET || '').trim();
  if (!clientId || !clientSecret) {
    return res.redirect('/login.php?erro=google_config_invalida');
  }

  const state = crypto.randomBytes(32).toString('hex');
  req.session.google_oauth_state = state;
  req.session.google_oauth_state_created_at = Date.now();
  req.session.google_oauth_redirect_uri = getGoogleRedirectUri(req);

  const oauth2Client = new google.auth.OAuth2(
    clientId,
    clientSecret,
    req.session.google_oauth_redirect_uri,
  );

  const url = oauth2Client.generateAuthUrl({
    access_type: 'offline',
    scope: ['email', 'profile'],
    prompt: 'select_account',
    state,
  });

  return res.redirect(url);
});

router.get(['/callback_google.php', '/auth/callback-google'], asyncHandler(async (req, res) => {
  if (!req.query.code) {
    return res.redirect('/login.php?erro=google_cancelado');
  }

  const incomingState = String(req.query.state || '');
  const sessionState = String(req.session.google_oauth_state || '');
  const stateCreatedAt = Number(req.session.google_oauth_state_created_at || 0);
  const redirectUri = String(req.session.google_oauth_redirect_uri || getGoogleRedirectUri(req));

  delete req.session.google_oauth_state;
  delete req.session.google_oauth_state_created_at;
  delete req.session.google_oauth_redirect_uri;

  const expired = !stateCreatedAt || Date.now() - stateCreatedAt > 10 * 60 * 1000;
  if (!incomingState || !sessionState || expired || incomingState.length !== sessionState.length) {
    return res.redirect('/login.php?erro=google_state_invalido');
  }

  const valid = crypto.timingSafeEqual(Buffer.from(incomingState), Buffer.from(sessionState));
  if (!valid) {
    return res.redirect('/login.php?erro=google_state_invalido');
  }

  try {
    const clientId = String(process.env.GOOGLE_CLIENT_ID || '').trim();
    const clientSecret = String(process.env.GOOGLE_CLIENT_SECRET || '').trim();
    if (!clientId || !clientSecret) {
      return res.redirect('/login.php?erro=google_config_invalida');
    }

    const oauth2Client = new google.auth.OAuth2(
      clientId,
      clientSecret,
      redirectUri,
    );

    const { tokens } = await oauth2Client.getToken(String(req.query.code));
    oauth2Client.setCredentials(tokens);

    const oauth2 = google.oauth2({ version: 'v2', auth: oauth2Client });
    const profile = await oauth2.userinfo.get();

    const email = String(profile.data.email || '');
    const nome = String(profile.data.name || 'Usuario Google');
    const googleId = String(profile.data.id || '');

    if (!email || !googleId) {
      return res.redirect('/login.php?erro=google_dados_invalidos');
    }

    const [rows] = await pool.execute('SELECT id, nome, plano, google_id FROM usuarios WHERE email = ?', [email]);
    const usuario = rows[0];

    let userId;
    let userName;
    let userPlan;

    if (usuario) {
      if (usuario.google_id && usuario.google_id !== googleId) {
        return res.redirect('/login.php?erro=google_conta_conflito');
      }

      if (!usuario.google_id) {
        await pool.execute('UPDATE usuarios SET google_id = ?, email_verificado = 1 WHERE id = ?', [googleId, usuario.id]);
      }

      userId = Number(usuario.id);
      userName = String(usuario.nome || nome);
      userPlan = String(usuario.plano || 'gratis');
    } else {
      const [result] = await pool.execute(
        "INSERT INTO usuarios (nome, email, google_id, plano, email_verificado) VALUES (?, ?, ?, 'gratis', 1)",
        [nome, email, googleId],
      );

      userId = Number(result.insertId);
      userName = nome;
      userPlan = 'gratis';
    }

    req.session.regenerate((sessionError) => {
      if (sessionError) {
        return res.status(500).send('Erro interno ao autenticar com Google.');
      }

      req.session.usuario_id = userId;
      req.session.usuario_nome = userName;
      req.session.usuario_plano = userPlan;
      req.session.createdAt = Date.now();
      return res.redirect('/index.php');
    });

    return;
  } catch (err) {
    return res.redirect('/login.php?erro=google_falha_autenticacao');
  }
}));

router.get(['/verificar_email.php', '/auth/verificar-email'], asyncHandler(async (req, res) => {
  const token = String(req.query.token || '').trim();
  if (!/^[a-f0-9]{64}$/i.test(token)) {
    return res.status(400).send('Link invalido ou expirado.');
  }

  const [rows] = await pool.execute(
    'SELECT id FROM usuarios WHERE token_verificacao = ? AND email_verificado = 0',
    [token],
  );

  if (rows.length > 0) {
    await pool.execute('UPDATE usuarios SET email_verificado = 1, token_verificacao = NULL WHERE id = ?', [rows[0].id]);
    return res.redirect('/login.php?msg=conta_ativada');
  }

  return res.status(400).send('<div style="text-align:center; margin-top:50px; font-family:sans-serif;"><h2>Link invalido</h2><p>Este link ja foi usado ou nao existe mais. Tente fazer login.</p><a href="/login.php">Ir para Login</a></div>');
}));

module.exports = router;
