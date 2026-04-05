const express = require('express');
const Stripe = require('stripe');
const pool = require('../config/db');
const { requireAuth } = require('../middleware/auth');
const { validateCsrfPost } = require('../utils/security');
const { appBaseUrl } = require('../utils/helpers');

const router = express.Router();

function getStripe() {
  return new Stripe(String(process.env.STRIPE_SECRET_KEY || ''), { apiVersion: '2024-04-10' });
}

router.get(['/planos.php', '/pagamentos/planos'], (req, res) => {
  return res.render('payments/planos', {
    pageTitle: 'Escolha seu Plano - Analista IA',
  });
});

router.post(['/checkout.php', '/pagamentos/checkout'], requireAuth, validateCsrfPost, async (req, res) => {
  const stripe = getStripe();
  const priceId = String(process.env.STRIPE_PRICE_ID || '');
  const baseUrl = appBaseUrl(req);

  try {
    const session = await stripe.checkout.sessions.create({
      payment_method_types: ['card'],
      line_items: [{ price: priceId, quantity: 1 }],
      mode: 'subscription',
      subscription_data: {
        metadata: { usuario_id: String(req.session.usuario_id) },
      },
      success_url: `${baseUrl}/sucesso.php?session_id={CHECKOUT_SESSION_ID}`,
      cancel_url: `${baseUrl}/planos.php`,
      client_reference_id: String(req.session.usuario_id),
    });

    return res.redirect(session.url);
  } catch (err) {
    return res.status(500).send('Nao foi possivel iniciar o pagamento. Tente novamente ou entre em contato com o suporte.');
  }
});

router.get(['/sucesso.php', '/pagamentos/sucesso'], requireAuth, async (req, res) => {
  const sessionId = String(req.query.session_id || '');
  if (!sessionId) {
    return res.redirect('/planos.php?msg=sessao_pagamento_invalida');
  }

  const stripe = getStripe();
  try {
    const checkoutSession = await stripe.checkout.sessions.retrieve(sessionId);
    const usuarioRef = String(checkoutSession.client_reference_id || '');
    const status = String(checkoutSession.status || '');
    const paymentStatus = String(checkoutSession.payment_status || '');

    if (usuarioRef !== String(req.session.usuario_id) || status !== 'complete' || !['paid', 'no_payment_required'].includes(paymentStatus)) {
      return res.redirect('/planos.php?msg=pagamento_nao_confirmado');
    }

    await pool.execute("UPDATE usuarios SET plano = 'pro' WHERE id = ?", [req.session.usuario_id]);
    req.session.usuario_plano = 'pro';

    return res.render('payments/sucesso', {
      pageTitle: 'Pagamento Aprovado',
    });
  } catch (err) {
    return res.redirect('/planos.php?msg=erro_validacao_pagamento');
  }
});

router.post(['/webhook.php', '/pagamentos/webhook'], async (req, res) => {
  const webhookSecret = String(process.env.STRIPE_WEBHOOK_SECRET || '');
  if (!webhookSecret) {
    return res.sendStatus(500);
  }

  const stripe = getStripe();
  const signature = req.headers['stripe-signature'];

  let event;
  try {
    const payload = req.rawBody || Buffer.from(JSON.stringify(req.body || {}));
    event = stripe.webhooks.constructEvent(payload, signature, webhookSecret);
  } catch (err) {
    return res.sendStatus(400);
  }

  try {
    if (event.type === 'checkout.session.completed') {
      const session = event.data.object;
      const usuarioId = Number.parseInt(String(session.client_reference_id || '0'), 10);
      const status = String(session.status || '');
      const paymentStatus = String(session.payment_status || '');
      const mode = String(session.mode || '');

      if (usuarioId > 0 && status === 'complete' && mode === 'subscription' && ['paid', 'no_payment_required'].includes(paymentStatus)) {
        await pool.execute("UPDATE usuarios SET plano = 'pro' WHERE id = ?", [usuarioId]);
      }
    }

    if (event.type === 'customer.subscription.updated' || event.type === 'customer.subscription.deleted') {
      const subscription = event.data.object;
      const usuarioId = Number.parseInt(String(subscription.metadata?.usuario_id || '0'), 10);
      const status = String(subscription.status || '');

      if (usuarioId > 0) {
        const novoPlano = ['active', 'trialing'].includes(status) ? 'pro' : 'gratis';
        await pool.execute('UPDATE usuarios SET plano = ? WHERE id = ?', [novoPlano, usuarioId]);
      }
    }

    return res.sendStatus(200);
  } catch (err) {
    return res.sendStatus(500);
  }
});

module.exports = router;
