<?php
require_once __DIR__ . '/../../config/init.php';

if (!isset($_GET['code'])) {
    header('Location: login.php?erro=google_cancelado');
    exit;
}

$client = new Google\Client();
$client->setClientId($_ENV['GOOGLE_CLIENT_ID'] ?? '');
$client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET'] ?? '');
$client->setRedirectUri($_ENV['GOOGLE_REDIRECT_URI'] ?? '');

try {
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
    $accessToken = (string) ($token['access_token'] ?? '');
    if ($accessToken === '') {
        header('Location: login.php?erro=google_token_invalido');
        exit;
    }

    $client->setAccessToken($accessToken);

    $googleOauth = new Google\Service\Oauth2($client);
    $googleAccountInfo = $googleOauth->userinfo->get();

    $email = (string) ($googleAccountInfo->email ?? '');
    $nome = (string) ($googleAccountInfo->name ?? 'Usuario Google');
    $googleId = (string) ($googleAccountInfo->id ?? '');

    if ($email === '' || $googleId === '') {
        header('Location: login.php?erro=google_dados_invalidos');
        exit;
    }

    $stmt = $pdo->prepare('SELECT id, nome, plano, google_id FROM usuarios WHERE email = ?');
    $stmt->execute([$email]);
    $usuario = $stmt->fetch();

    if ($usuario) {
        if (empty($usuario['google_id'])) {
            $update = $pdo->prepare('UPDATE usuarios SET google_id = ?, email_verificado = 1 WHERE id = ?');
            $update->execute([$googleId, $usuario['id']]);
        }

        $_SESSION['usuario_id'] = $usuario['id'];
        $_SESSION['usuario_nome'] = $usuario['nome'];
        $_SESSION['usuario_plano'] = $usuario['plano'] ?? 'gratis';
    } else {
        $insert = $pdo->prepare("INSERT INTO usuarios (nome, email, google_id, plano, email_verificado) VALUES (?, ?, ?, 'gratis', 1)");
        $insert->execute([$nome, $email, $googleId]);

        $_SESSION['usuario_id'] = (int) $pdo->lastInsertId();
        $_SESSION['usuario_nome'] = $nome;
        $_SESSION['usuario_plano'] = 'gratis';
    }

    header('Location: index.php');
    exit;
} catch (Exception $e) {
    header('Location: login.php?erro=google_falha_autenticacao');
    exit;
}
