<?php
// sucesso.php
require_once __DIR__ . '/../../config/init.php';

if (!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit;
}

$sessionId = $_GET['session_id'] ?? '';
if (!is_string($sessionId) || $sessionId === '') {
    header("Location: planos.php?msg=sessao_pagamento_invalida");
    exit;
}

\Stripe\Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);

try {
    $checkoutSession = \Stripe\Checkout\Session::retrieve($sessionId);

    $usuarioRef = (string) ($checkoutSession->client_reference_id ?? '');
    $status = (string) ($checkoutSession->status ?? '');
    $paymentStatus = (string) ($checkoutSession->payment_status ?? '');

    if (
        $usuarioRef !== (string) $_SESSION['usuario_id'] ||
        $status !== 'complete' ||
        !in_array($paymentStatus, ['paid', 'no_payment_required'], true)
    ) {
        header("Location: planos.php?msg=pagamento_nao_confirmado");
        exit;
    }

    $stmt = $pdo->prepare("UPDATE usuarios SET plano = 'pro' WHERE id = ?");
    $stmt->execute([(int) $_SESSION['usuario_id']]);
    $_SESSION['usuario_plano'] = 'pro';
} catch (Exception $e) {
    header("Location: planos.php?msg=erro_validacao_pagamento");
    exit;
}

$pageTitle = "Pagamento Aprovado";
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container container-small" style="text-align: center;">
    <div style="font-size: 60px; margin-bottom: 10px;">🎉</div>
    <h2 style="color: var(--success); margin-bottom: 10px;">Pagamento Aprovado!</h2>
    <p style="color: var(--text-muted); font-size: 16px;">Bem-vindo ao <strong>Plano Profissional</strong>.</p>
    <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 30px;">Suas análises de IA ilimitadas já estão liberadas e ativas na sua conta.</p>
    
    <a href="index.php" class="btn">Ir para o Dashboard</a>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>