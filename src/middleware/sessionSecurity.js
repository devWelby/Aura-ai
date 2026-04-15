function sessionSecurity(req, res, next) {
  if (!req.session.createdAt) {
    req.session.createdAt = Date.now();
    return next();
  }

  const age = Date.now() - Number(req.session.createdAt);
  if (age > 30 * 60 * 1000) {
    const preserved = {
      usuario_id: req.session.usuario_id,
      usuario_nome: req.session.usuario_nome,
      usuario_plano: req.session.usuario_plano,
      login_bf: req.session.login_bf,
      relatorio_visitante: req.session.relatorio_visitante,
      upload_rate: req.session.upload_rate,
      google_oauth_state: req.session.google_oauth_state,
      google_oauth_state_created_at: req.session.google_oauth_state_created_at,
      google_oauth_redirect_uri: req.session.google_oauth_redirect_uri,
      csrfToken: req.session.csrfToken,
    };

    req.session.regenerate((err) => {
      if (err) {
        return next(err);
      }

      Object.entries(preserved).forEach(([key, value]) => {
        if (value !== undefined) {
          req.session[key] = value;
        }
      });
      req.session.createdAt = Date.now();
      return next();
    });
    return;
  }

  return next();
}

module.exports = sessionSecurity;
