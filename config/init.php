<?php
// config/init.php

// 0. Controle de exibicao de erros por ambiente
// Em producao, nunca exibir erros para o usuario final
$appEnv = getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? 'development');
if ($appEnv === 'production') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(0);
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

// 1. Segurança de Sessão Nível Sênior
session_set_cookie_params([
    'lifetime' => 86400,
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true, // Impede que hackers roubem o cookie via JavaScript (XSS)
    'samesite' => 'Lax' // Previne ataques CSRF
]);
ini_set('session.use_strict_mode', '1');
session_start();

// Previne "Session Fixation" renovando o ID da sessão a cada 30 minutos
if (!isset($_SESSION['CREATED'])) {
    $_SESSION['CREATED'] = time();
} else if (time() - $_SESSION['CREATED'] > 1800) {
    session_regenerate_id(true);
    $_SESSION['CREATED'] = time();
}

// 1b. HTTP Security Headers
// Aplicados antes de qualquer saida HTML
if (!headers_sent()) {
    // Impede que o browser adivinhe o MIME type (sniffing)
    header('X-Content-Type-Options: nosniff');
    // Impede a pagina de ser carregada em iframe (clickjacking)
    header('X-Frame-Options: SAMEORIGIN');
    // Ativa filtro XSS do browser (legado, ainda util)
    header('X-XSS-Protection: 1; mode=block');
    // Controla informacoes enviadas no Referer
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // Restringe acesso a recursos de hardware
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    // Remove X-Powered-By para nao revelar versao do PHP
    header_remove('X-Powered-By');
    // HSTS: forcxa HTTPS por 1 ano (ativar so quando em producao com HTTPS)
    // header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}

// 2. Carrega as Bibliotecas e o .env
require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// 3. Conexão com o Banco de Dados (PDO) blindada via .env
$host = $_ENV['DB_HOST'] ?? 'localhost';
$dbname = $_ENV['DB_NAME'] ?? 'analista_financeiro_db';
$usuario = $_ENV['DB_USER'] ?? 'root';
$senha = $_ENV['DB_PASS'] ?? '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $usuario, $senha);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Esconde o erro real em produção para não dar dicas a hackers
    die("Erro fatal ao conectar ao banco de dados."); 
}

// Função de utilidade para limpar dados e evitar XSS
function limpar_dado($dado) {
    return htmlspecialchars(trim($dado), ENT_QUOTES, 'UTF-8');
}

// Token CSRF reutilizavel para todos os formularios POST
function csrf_token() {
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || strlen($_SESSION['csrf_token']) < 32) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validar_csrf_post() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $tokenRecebido = $_POST['csrf_token'] ?? '';
    $tokenSessao = $_SESSION['csrf_token'] ?? '';

    if (!is_string($tokenRecebido) || !is_string($tokenSessao) || !hash_equals($tokenSessao, $tokenRecebido)) {
        http_response_code(403);
        die('Falha de validacao de seguranca. Recarregue a pagina e tente novamente.');
    }
}

function ler_uso_gratuito_assinado() {
    $raw = $_COOKIE['uso_gratuito'] ?? '';
    if (!is_string($raw) || strpos($raw, '.') === false) {
        return 0;
    }

    [$valor, $assinatura] = explode('.', $raw, 2);
    if ($valor === '' || $assinatura === '') {
        return 0;
    }

    $secret = $_ENV['COOKIE_SIGNING_SECRET'] ?? '';
    if (!is_string($secret) || $secret === '') {
        return 0;
    }

    $esperada = hash_hmac('sha256', $valor, $secret);
    if (!hash_equals($esperada, $assinatura)) {
        return 0;
    }

    $uso = (int) $valor;
    return max(0, $uso);
}

function salvar_uso_gratuito_assinado($uso) {
    $secret = $_ENV['COOKIE_SIGNING_SECRET'] ?? '';
    if (!is_string($secret) || $secret === '') {
        return;
    }

    $uso = max(0, (int) $uso);
    $assinatura = hash_hmac('sha256', (string) $uso, $secret);
    $valorCookie = $uso . '.' . $assinatura;

    setcookie('uso_gratuito', $valorCookie, [
        'expires' => time() + (86400 * 30),
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}
?>