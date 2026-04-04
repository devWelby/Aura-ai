<?php
// logout.php
// Usa init.php para garantir os mesmos parametros seguros de sessao
require_once __DIR__ . '/../../config/init.php';

// Limpa todos os dados da sessao atual
$_SESSION = [];

// Invalida o cookie de sessao no browser
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'] ?? '',
        $params['secure'] ?? false,
        $params['httponly'] ?? true
    );
}

// Troca o ID antes de destruir para invalidar sessao no servidor
session_regenerate_id(true);
session_destroy();

header('Location: login.php');
exit;
?>