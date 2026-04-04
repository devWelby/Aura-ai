<?php
// checkout.php
require_once __DIR__ . '/../../config/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: planos.php');
    exit;
}

validar_csrf_post();

// Apenas usuários logados podem assinar
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit;
}

// Inicializa a biblioteca do Stripe
\Stripe\Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);

$priceId = $_ENV['STRIPE_PRICE_ID'] ?? 'price_1TIGCtByqYfLTObiAgctBOT2';
$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$scheme = $isHttps ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$baseUrl = $scheme . '://' . $host . $basePath;

try {
    $checkout_session = \Stripe\Checkout\Session::create([
        'payment_method_types' => ['card'],
        'line_items' => [[
            // 🚨 COLOQUE AQUI O SEU ID DE PREÇO (Gerado no painel do Stripe)
            'price' => $priceId,
            'quantity' => 1,
        ]],
        'mode' => 'subscription', // Modo assinatura recorrente
        
        // Para onde o Stripe vai devolver o usuário
        'success_url' => $baseUrl . '/sucesso.php?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url'  => $baseUrl . '/planos.php',
        
        // 🚨 O SEGREDO DA INTEGRAÇÃO: Enviamos o ID do usuário escondido.
        // Quando o Stripe aprovar, ele nos devolve esse ID para atualizarmos o banco.
        'client_reference_id' => $_SESSION['usuario_id'],
    ]);

    // Redireciona para o Stripe
    header("Location: " . $checkout_session->url);
    exit;

} catch (Exception $e) {
    // Nunca expor detalhes internos do Stripe ao usuario
    http_response_code(500);
    die('Nao foi possivel iniciar o pagamento. Tente novamente ou entre em contato com o suporte.');
}
?>