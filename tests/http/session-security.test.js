const test = require('node:test');
const assert = require('node:assert/strict');

const sessionSecurity = require('../../src/middleware/sessionSecurity');

function createRequestWithSession(partialSession = {}) {
  const session = {
    regenerate(callback) {
      Object.keys(this).forEach((key) => {
        delete this[key];
      });
      this.regenerate = session.regenerate;
      callback(null);
    },
    ...partialSession,
  };

  return { session };
}

test('sessionSecurity define createdAt para sessao nova', async () => {
  const req = createRequestWithSession();

  await new Promise((resolve, reject) => {
    sessionSecurity(req, {}, (err) => {
      if (err) {
        reject(err);
        return;
      }
      resolve();
    });
  });

  assert.equal(typeof req.session.createdAt, 'number');
  assert.ok(req.session.createdAt > 0);
});

test('sessionSecurity preserva dados de autenticacao ao rotacionar sessao antiga', async () => {
  const now = Date.now();
  const req = createRequestWithSession({
    createdAt: now - (31 * 60 * 1000),
    usuario_id: 42,
    usuario_nome: 'Wellington',
    usuario_plano: 'pro',
    csrfToken: 'token-csrf',
  });

  await new Promise((resolve, reject) => {
    sessionSecurity(req, {}, (err) => {
      if (err) {
        reject(err);
        return;
      }
      resolve();
    });
  });

  assert.equal(req.session.usuario_id, 42);
  assert.equal(req.session.usuario_nome, 'Wellington');
  assert.equal(req.session.usuario_plano, 'pro');
  assert.equal(req.session.csrfToken, 'token-csrf');
  assert.equal(typeof req.session.createdAt, 'number');
  assert.ok(req.session.createdAt >= now);
});
