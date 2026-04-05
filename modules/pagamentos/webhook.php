<?php
require_once __DIR__ . '/../../config/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit();
}

\Stripe\Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);

$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$endpoint_secret = (string) ($_ENV['STRIPE_WEBHOOK_SECRET'] ?? '');

if ($endpoint_secret === '') {
    http_response_code(500);
    exit();
}

$event = null;

try {
    // Valida assinatura para garantir que o evento veio do Stripe.
    $event = \Stripe\Webhook::constructEvent(
        $payload, $sig_header, $endpoint_secret
    );
} catch(\UnexpectedValueException $e) {
    http_response_code(400);
    exit();
} catch(\Stripe\Exception\SignatureVerificationException $e) {
    http_response_code(400); 
    exit();
}

// Processa eventos validos do Stripe.
if ($event->type === 'checkout.session.completed') {
    $session = $event->data->object;
    $usuario_id = isset($session->client_reference_id) ? (int) $session->client_reference_id : 0;
    $status = (string) ($session->status ?? '');
    $paymentStatus = (string) ($session->payment_status ?? '');
    $mode = (string) ($session->mode ?? '');

    if (
        $usuario_id > 0 &&
        $status === 'complete' &&
        $mode === 'subscription' &&
        in_array($paymentStatus, ['paid', 'no_payment_required'], true)
    ) {
        $stmt = $pdo->prepare("UPDATE usuarios SET plano = 'pro' WHERE id = ?");
        $stmt->execute([$usuario_id]);
    }
}

if ($event->type === 'customer.subscription.updated' || $event->type === 'customer.subscription.deleted') {
    $subscription = $event->data->object;
    $usuario_id = isset($subscription->metadata->usuario_id) ? (int) $subscription->metadata->usuario_id : 0;
    $status = (string) ($subscription->status ?? '');

    if ($usuario_id > 0) {
        $novoPlano = in_array($status, ['active', 'trialing'], true) ? 'pro' : 'gratis';
        $stmt = $pdo->prepare('UPDATE usuarios SET plano = ? WHERE id = ?');
        $stmt->execute([$novoPlano, $usuario_id]);
    }
}

http_response_code(200);
?>