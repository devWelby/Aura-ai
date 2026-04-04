<?php
// includes/header.php
// Verifica se o título da página foi definido antes do include, senão usa um padrão
$pageTitle = isset($pageTitle) ? $pageTitle : "Seu Analista Financeiro IA";

$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$isPublicFrontController = strpos($scriptName, '/public/') !== false;
$baseHref = $isPublicFrontController ? '../' : '';
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