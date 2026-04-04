<?php

function app_route_map() {
    return [
        'dashboard' => 'modules/app/index.php',
        'auth/login' => 'modules/auth/login.php',
        'auth/cadastro' => 'modules/auth/cadastro.php',
        'auth/logout' => 'modules/auth/logout.php',
        'auth/login-google' => 'modules/auth/login_google.php',
        'auth/callback-google' => 'modules/auth/callback_google.php',
        'auth/verificar-email' => 'modules/auth/verificar_email.php',
        'relatorios/historico' => 'modules/relatorios/historico.php',
        'relatorios/ver' => 'modules/relatorios/ver_relatorio.php',
        'relatorios/processar-upload' => 'modules/relatorios/processar_upload.php',
        'pagamentos/planos' => 'modules/pagamentos/planos.php',
        'pagamentos/checkout' => 'modules/pagamentos/checkout.php',
        'pagamentos/sucesso' => 'modules/pagamentos/sucesso.php',
        'pagamentos/webhook' => 'modules/pagamentos/webhook.php'
    ];
}

function legacy_endpoint_map() {
    return [
        'index.php' => 'dashboard',
        'login.php' => 'auth/login',
        'cadastro.php' => 'auth/cadastro',
        'logout.php' => 'auth/logout',
        'login_google.php' => 'auth/login-google',
        'callback_google.php' => 'auth/callback-google',
        'verificar_email.php' => 'auth/verificar-email',
        'historico.php' => 'relatorios/historico',
        'ver_relatorio.php' => 'relatorios/ver',
        'processar_upload.php' => 'relatorios/processar-upload',
        'planos.php' => 'pagamentos/planos',
        'checkout.php' => 'pagamentos/checkout',
        'sucesso.php' => 'pagamentos/sucesso',
        'webhook.php' => 'pagamentos/webhook'
    ];
}

function dispatch_route($route) {
    $route = trim((string) $route);
    if ($route === '') {
        $route = 'dashboard';
    }

    $map = app_route_map();
    if (!isset($map[$route])) {
        http_response_code(404);
        echo 'Rota nao encontrada.';
        exit;
    }

    require_once __DIR__ . '/../' . $map[$route];
    exit;
}

function dispatch_legacy_endpoint($legacyFilename) {
    $legacyFilename = trim((string) $legacyFilename);
    $legacy = legacy_endpoint_map();

    if (!isset($legacy[$legacyFilename])) {
        http_response_code(404);
        echo 'Endpoint legado nao encontrado.';
        exit;
    }

    dispatch_route($legacy[$legacyFilename]);
}
