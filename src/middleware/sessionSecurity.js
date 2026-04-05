function sessionSecurity(req, res, next) {
  if (!req.session.createdAt) {
    req.session.createdAt = Date.now();
    return next();
  }

  const age = Date.now() - Number(req.session.createdAt);
  if (age > 30 * 60 * 1000) {
    req.session.regenerate((err) => {
      if (err) {
        return next(err);
      }
      req.session.createdAt = Date.now();
      return next();
    });
    return;
  }

  return next();
}

module.exports = sessionSecurity;
