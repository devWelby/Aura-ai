<?php
// Cabeçalho compartilhado da aplicação.
$pageTitle = isset($pageTitle) ? $pageTitle : "Seu Analista Financeiro IA";

$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$isPublicFrontController = strpos($scriptName, '/public/') !== false;
$baseHref = $isPublicFrontController ? '../' : '';

$isLogged = isset($_SESSION['usuario_id']);
$plan = isset($_SESSION['usuario_plano']) ? (string) $_SESSION['usuario_plano'] : 'visitante';
$activePage = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: ($scriptName ?: 'index.php'));

$menuItems = [
    ['href' => 'index.php', 'label' => 'Dashboard', 'active' => ['index.php']],
    ['href' => 'historico.php', 'label' => 'Histórico', 'active' => ['historico.php', 'ver_relatorio.php'], 'auth' => true],
    ['href' => 'planos.php', 'label' => 'Planos', 'active' => ['planos.php', 'checkout.php', 'sucesso.php']]
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if ($baseHref !== ''): ?>
        <base href="<?= htmlspecialchars($baseHref, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <header class="main-menu-wrap">
        <div class="main-menu">
            <a href="index.php" class="brand">Analista IA</a>

            <button class="menu-toggle" type="button" aria-label="Abrir menu" aria-expanded="false" onclick="document.body.classList.toggle('menu-open'); this.setAttribute('aria-expanded', document.body.classList.contains('menu-open') ? 'true' : 'false');">
                Menu
            </button>

            <nav class="menu-links" aria-label="Principal">
                <?php foreach ($menuItems as $item): ?>
                    <?php
                    $requiresAuth = isset($item['auth']) && $item['auth'] === true;
                    if ($requiresAuth && !$isLogged) {
                        continue;
                    }
                    $isActive = in_array($activePage, $item['active'], true);
                    ?>
                    <a href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>" class="<?= $isActive ? 'active' : '' ?>"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></a>
                <?php endforeach; ?>
            </nav>

            <div class="menu-user">
                <?php if ($isLogged): ?>
                    <span class="menu-pill">Plano <?= htmlspecialchars($plan, ENT_QUOTES, 'UTF-8') ?></span>
                    <form method="POST" action="logout.php" style="margin: 0;">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <button class="menu-logout" type="submit">Sair</button>
                    </form>
                <?php else: ?>
                    <a href="login.php" class="menu-login <?= $activePage === 'login.php' ? 'active' : '' ?>">Entrar</a>
                    <a href="cadastro.php" class="menu-signup <?= $activePage === 'cadastro.php' ? 'active' : '' ?>">Criar conta</a>
                <?php endif; ?>
            </div>
        </div>
    </header>