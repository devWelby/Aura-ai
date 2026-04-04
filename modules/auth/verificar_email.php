<?php
require_once __DIR__ . '/../../config/init.php';

$token = (string) filter_input(INPUT_GET, 'token', FILTER_UNSAFE_RAW);
$token = trim($token);

if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
    die('Link invalido ou expirado.');
}

$stmt = $pdo->prepare('SELECT id FROM usuarios WHERE token_verificacao = ? AND email_verificado = 0');
$stmt->execute([$token]);
$usuario = $stmt->fetch();

if ($usuario) {
    $update = $pdo->prepare('UPDATE usuarios SET email_verificado = 1, token_verificacao = NULL WHERE id = ?');
    $update->execute([$usuario['id']]);

    header('Location: login.php?msg=conta_ativada');
    exit;
}

echo "<div style='text-align:center; margin-top: 50px; font-family: sans-serif;'>";
echo '<h2>Link invalido</h2>';
echo '<p>Este link ja foi usado ou nao existe mais. Tente fazer login.</p>';
echo "<a href='login.php'>Ir para Login</a>";
echo '</div>';
