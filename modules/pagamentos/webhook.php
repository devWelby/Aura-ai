<?php
// webhook.php
require_once __DIR__ . '/../../config/init.php';

\Stripe\Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);

$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$endpoint_secret = $_ENV['STRIPE_WEBHOOK_SECRET']; // A chave de assinatura do .env

$event = null;

try {
    // 🚨 A MÁGICA DA SEGURANÇA: Verifica se a mensagem realmente veio do Stripe
    $event = \Stripe\Webhook::constructEvent(
        $payload, $sig_header, $endpoint_secret
    );
} catch(\UnexpectedValueException $e) {
    http_response_code(400); // Payload inválido
    exit();
} catch(\Stripe\Exception\SignatureVerificationException $e) {
    // 🚨 HACKER BLOQUEADO: Assinatura falsa!
    http_response_code(400); 
    exit();
}

// Analisa qual evento ocorreu após garantir a autenticidade
if ($event->type === 'checkout.session.completed') {
    $session = $event->data->object;
    $usuario_id = isset($session->client_reference_id) ? (int) $session->client_reference_id : 0;
    
    if ($usuario_id > 0) {
        $stmt = $pdo->prepare("UPDATE usuarios SET plano = 'pro' WHERE id = ?");
        $stmt->execute([$usuario_id]);
    }
}

// Responde com sucesso
http_response_code(200);
?>