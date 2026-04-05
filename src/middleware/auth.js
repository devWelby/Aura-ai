function requireAuth(req, res, next) {
  if (!req.session.usuario_id) {
    return res.redirect('/login.php');
  }
  return next();
}

module.exports = {
  requireAuth,
};
