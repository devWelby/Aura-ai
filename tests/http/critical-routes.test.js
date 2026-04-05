const test = require('node:test');
const assert = require('node:assert/strict');
const request = require('supertest');
const { createApp } = require('../../src/app');

const app = createApp({
  usePersistentSessionStore: false,
  enableDbHealthcheck: false,
});

test('GET /healthz retorna 200 e status ok', async () => {
  const res = await request(app).get('/healthz');
  assert.equal(res.status, 200);
  assert.equal(res.body.status, 'ok');
});

test('GET /index.php retorna 200', async () => {
  const res = await request(app).get('/index.php');
  assert.equal(res.status, 200);
  assert.match(res.text, /Analise Financeira Inteligente/i);
});

test('GET /login.php retorna 200 e inclui csrf_token', async () => {
  const res = await request(app).get('/login.php');
  assert.equal(res.status, 200);
  assert.match(res.text, /name="csrf_token"/i);
});

test('GET /cadastro.php retorna 200 e inclui csrf_token', async () => {
  const res = await request(app).get('/cadastro.php');
  assert.equal(res.status, 200);
  assert.match(res.text, /name="csrf_token"/i);
});

test('GET /planos.php retorna 200', async () => {
  const res = await request(app).get('/planos.php');
  assert.equal(res.status, 200);
  assert.match(res.text, /Planos e Precos/i);
});

test('GET /historico.php sem auth redireciona para login', async () => {
  const res = await request(app).get('/historico.php');
  assert.equal(res.status, 302);
  assert.equal(res.headers.location, '/login.php');
});

test('POST /processar_upload.php com .xls retorna 400', async () => {
  const agent = request.agent(app);

  const dashboard = await agent.get('/index.php');
  assert.equal(dashboard.status, 200);

  const csrfMatch = dashboard.text.match(/name="csrf_token"\s+value="([a-f0-9]+)"/i);
  assert.ok(csrfMatch && csrfMatch[1], 'csrf_token nao encontrado no dashboard');

  const upload = await agent
    .post('/processar_upload.php')
    .field('csrf_token', csrfMatch[1])
    .attach('extrato', Buffer.from('arquivo-legado', 'utf-8'), {
      filename: 'extrato_antigo.xls',
      contentType: 'application/vnd.ms-excel',
    });

  assert.equal(upload.status, 400);
  assert.match(upload.text, /XLS nao e mais suportado/i);
});
