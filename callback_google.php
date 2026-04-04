<?php
// callback_google.php
require_once 'config/init.php';

if (!isset($_GET['code'])) {
    header("Location: login.php?erro=google_cancelado");
    exit;
}

$client = new Google\Client();
$client->setClientId($_ENV['GOOGLE_CLIENT_ID']);
$client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET']);
$client->setRedirectUri($_ENV['GOOGLE_REDIRECT_URI']);

try {
    // Troca o código de autorização pelo Token de Acesso real
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
    $client->setAccessToken($token['access_token']);
    
    // Pega os dados do perfil do usuário no Google
    $google_oauth = new Google\Service\Oauth2($client);
    $google_account_info = $google_oauth->userinfo->get();
    
    $email = $google_account_info->email;
    $nome = $google_account_info->name;
    $google_id = $google_account_info->id;

    // Verifica se o e-mail já existe no nosso banco de dados
    $stmt = $pdo->prepare("SELECT id, nome, plano, google_id FROM usuarios WHERE email = ?");
    $stmt->execute([$email]);
    $usuario = $stmt->fetch();

    if ($usuario) {
        // Se existe, mas não tem google_id (criou via senha antes), a gente vincula a conta
        if (empty($usuario['google_id'])) {
            $update = $pdo->prepare("UPDATE usuarios SET google_id = ? WHERE id = ?");
            $update->execute([$google_id, $usuario['id']]);
        }
        
        // Faz o Login
        $_SESSION['usuario_id'] = $usuario['id'];
        $_SESSION['usuario_nome'] = $usuario['nome'];
        $_SESSION['usuario_plano'] = $usuario['plano'];
        
    } else {
        // Novo Usuário! Cria a conta direto no plano grátis
        $insert = $pdo->prepare("INSERT INTO usuarios (nome, email, google_id, plano) VALUES (?, ?, ?, 'gratis')");
        $insert->execute([$nome, $email, $google_id]);
        
        // Faz o Login com o ID recém criado
        $_SESSION['usuario_id'] = $pdo->lastInsertId();
        $_SESSION['usuario_nome'] = $nome;
        $_SESSION['usuario_plano'] = 'gratis';
    }

    // Redireciona para o Dashboard com sucesso
    header("Location: index.php");
    exit;

} catch (Exception $e) {
    die("Erro ao autenticar com o Google: " . $e->getMessage());
}