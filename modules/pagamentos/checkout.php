<?php
require_once __DIR__ . '/../../config/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: planos.php');
    exit;
}

validar_csrf_post();

// Apenas usuários autenticados podem iniciar assinatura.
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit;
}

\Stripe\Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);

$priceId = $_ENV['STRIPE_PRICE_ID'] ?? 'price_1TIGCtByqYfLTObiAgctBOT2';
$baseUrl = app_base_url();

try {
    $checkout_session = \Stripe\Checkout\Session::create([
        'payment_method_types' => ['card'],
        'line_items' => [[
            'price' => $priceId,
            'quantity' => 1,
        ]],
        'mode' => 'subscription',
        'subscription_data' => [
            'metadata' => [
                'usuario_id' => (string) $_SESSION['usuario_id']
            ]
        ],
        
        'success_url' => $baseUrl . '/sucesso.php?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url'  => $baseUrl . '/planos.php',
        
        'client_reference_id' => $_SESSION['usuario_id'],
    ]);

    header("Location: " . $checkout_session->url);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    die('Não foi possível iniciar o pagamento. Tente novamente ou entre em contato com o suporte.');
}
?>