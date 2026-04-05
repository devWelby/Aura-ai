require('dotenv').config();

const crypto = require('crypto');
const axios = require('axios');
const FormData = require('form-data');
const mysql = require('mysql2/promise');

const BASE_URL = process.env.APP_URL || 'http://localhost:3000';

function extractCsrf(html) {
  const match = String(html).match(/name="csrf_token"\s+value="([a-f0-9]+)"/i);
  return match ? match[1] : '';
}

function createClient() {
  const jar = new Map();

  function updateCookies(headers) {
    const setCookie = headers['set-cookie'] || [];
    for (const raw of setCookie) {
      const pair = String(raw).split(';')[0];
      const [k, v] = pair.split('=');
      if (k && v) {
        jar.set(k.trim(), v.trim());
      }
    }
  }

  function cookieHeader() {
    return Array.from(jar.entries()).map(([k, v]) => `${k}=${v}`).join('; ');
  }

  async function request(config) {
    const headers = { ...(config.headers || {}) };
    const cookie = cookieHeader();
    if (cookie) {
      headers.Cookie = cookie;
    }

    const response = await axios({
      maxRedirects: 0,
      validateStatus: () => true,
      ...config,
      headers,
    });

    updateCookies(response.headers || {});
    return response;
  }

  return { request };
}

async function run() {
  const db = await mysql.createConnection({
    host: process.env.DB_HOST || 'localhost',
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASS || '',
    database: process.env.DB_NAME || 'analista_financeiro_db',
  });

  const visitor = createClient();
  const auth = createClient();

  const stamp = Date.now();
  const email = `qa+aura-${stamp}@example.com`;
  const senha = 'SenhaTeste9';
  const nome = `QA Aura ${stamp}`;

  try {
    console.log('1) Health de rotas publicas');
    for (const path of ['/index.php', '/login.php', '/cadastro.php', '/planos.php']) {
      const r = await visitor.request({ method: 'GET', url: `${BASE_URL}${path}` });
      if (r.status !== 200) {
        throw new Error(`Falha em ${path}: HTTP ${r.status}`);
      }
    }

    console.log('2) Cadastro de usuario real');
    const cadastroGet = await auth.request({ method: 'GET', url: `${BASE_URL}/cadastro.php` });
    const csrfCadastro = extractCsrf(cadastroGet.data);
    if (!csrfCadastro) {
      throw new Error('CSRF de cadastro nao encontrado');
    }

    const cadastroBody = new URLSearchParams({
      csrf_token: csrfCadastro,
      nome,
      email,
      senha,
    }).toString();

    const cadastroPost = await auth.request({
      method: 'POST',
      url: `${BASE_URL}/cadastro.php`,
      data: cadastroBody,
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    });

    if (cadastroPost.status !== 200) {
      throw new Error(`Cadastro retornou HTTP ${cadastroPost.status}`);
    }

    console.log('3) Ativacao de e-mail');
    const [tokenRows] = await db.execute('SELECT token_verificacao FROM usuarios WHERE email = ? LIMIT 1', [email]);
    const token = tokenRows[0] && tokenRows[0].token_verificacao ? String(tokenRows[0].token_verificacao) : '';
    if (!token) {
      throw new Error('Token de verificacao nao encontrado no banco');
    }

    const verify = await auth.request({ method: 'GET', url: `${BASE_URL}/verificar_email.php?token=${encodeURIComponent(token)}` });
    if (verify.status !== 302) {
      throw new Error(`Verificacao de e-mail retornou HTTP ${verify.status}`);
    }

    console.log('4) Login com usuario ativado');
    const loginGet = await auth.request({ method: 'GET', url: `${BASE_URL}/login.php` });
    const csrfLogin = extractCsrf(loginGet.data);
    if (!csrfLogin) {
      throw new Error('CSRF de login nao encontrado');
    }

    const loginBody = new URLSearchParams({
      csrf_token: csrfLogin,
      email,
      senha,
    }).toString();

    const loginPost = await auth.request({
      method: 'POST',
      url: `${BASE_URL}/login.php`,
      data: loginBody,
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    });

    if (loginPost.status !== 302 || !String(loginPost.headers.location || '').includes('/index.php')) {
      throw new Error(`Login nao redirecionou corretamente. HTTP ${loginPost.status}`);
    }

    const historico = await auth.request({ method: 'GET', url: `${BASE_URL}/historico.php` });
    if (historico.status !== 200) {
      throw new Error(`Historico nao acessivel apos login: HTTP ${historico.status}`);
    }

    console.log('5) Upload visitante com Gemini real');
    const indexGet = await visitor.request({ method: 'GET', url: `${BASE_URL}/index.php` });
    const csrfUpload = extractCsrf(indexGet.data);
    if (!csrfUpload) {
      throw new Error('CSRF de upload nao encontrado no dashboard');
    }

    const csv = 'descricao,categoria,valor,tipo\nSalario,Salario,5000,entrada\nAluguel,Moradia,1500,saida\nMercado,Alimentacao,800,saida\n';
    const form = new FormData();
    form.append('csrf_token', csrfUpload);
    form.append('extrato', Buffer.from(csv, 'utf-8'), {
      filename: 'teste.csv',
      contentType: 'text/csv',
    });

    const upload = await visitor.request({
      method: 'POST',
      url: `${BASE_URL}/processar_upload.php`,
      data: form,
      headers: form.getHeaders(),
      maxBodyLength: Infinity,
      maxContentLength: Infinity,
      timeout: 120000,
    });

    if (upload.status !== 302 || !String(upload.headers.location || '').includes('/ver_relatorio.php')) {
      throw new Error(`Upload/Gemini nao concluiu como esperado: HTTP ${upload.status}`);
    }

    const reportPath = String(upload.headers.location || '');
    const relatorio = await visitor.request({ method: 'GET', url: `${BASE_URL}${reportPath}` });
    if (relatorio.status !== 200) {
      throw new Error(`Relatorio visitante nao abriu: HTTP ${relatorio.status}`);
    }

    console.log('6) Checkout Stripe (criacao de sessao)');
    const planos = await auth.request({ method: 'GET', url: `${BASE_URL}/planos.php` });
    const csrfCheckout = extractCsrf(planos.data);
    if (!csrfCheckout) {
      throw new Error('CSRF de checkout nao encontrado');
    }

    const checkoutBody = new URLSearchParams({ csrf_token: csrfCheckout }).toString();
    const checkout = await auth.request({
      method: 'POST',
      url: `${BASE_URL}/checkout.php`,
      data: checkoutBody,
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    });

    const checkoutLocation = String(checkout.headers.location || '');
    if (checkout.status !== 303 && checkout.status !== 302) {
      throw new Error(`Checkout retornou status inesperado: HTTP ${checkout.status}`);
    }
    if (!checkoutLocation.includes('checkout.stripe.com')) {
      throw new Error('Checkout nao redirecionou para Stripe');
    }

    console.log('7) Webhook Stripe com assinatura valida (teste tecnico)');
    const [userRows] = await db.execute('SELECT id FROM usuarios WHERE email = ? LIMIT 1', [email]);
    const usuarioId = Number(userRows[0].id);

    const payloadObj = {
      id: `evt_test_${stamp}`,
      object: 'event',
      type: 'checkout.session.completed',
      data: {
        object: {
          client_reference_id: String(usuarioId),
          status: 'complete',
          payment_status: 'paid',
          mode: 'subscription',
        },
      },
    };

    const payload = JSON.stringify(payloadObj);
    const ts = Math.floor(Date.now() / 1000);
    const signed = `${ts}.${payload}`;
    const secret = String(process.env.STRIPE_WEBHOOK_SECRET || '');
    const sig = crypto.createHmac('sha256', secret).update(signed).digest('hex');
    const signatureHeader = `t=${ts},v1=${sig}`;

    const webhook = await visitor.request({
      method: 'POST',
      url: `${BASE_URL}/webhook.php`,
      data: payload,
      headers: {
        'Content-Type': 'application/json',
        'Stripe-Signature': signatureHeader,
      },
    });

    if (webhook.status !== 200) {
      throw new Error(`Webhook assinando payload de teste falhou: HTTP ${webhook.status}`);
    }

    console.log('OK: teste funcional completo executado com sucesso.');
  } finally {
    await db.end();
  }
}

run().catch((err) => {
  console.error('FALHA:', err.message);
  process.exit(1);
});
