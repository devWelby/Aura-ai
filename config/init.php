<?php
// config/init.php

// Controle de exibição de erros por ambiente.
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

// Segurança de sessão.
session_set_cookie_params([
    'lifetime' => 86400,
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax'
]);
ini_set('session.use_strict_mode', '1');
session_start();

// Renova o ID da sessão periodicamente para reduzir risco de fixation.
if (!isset($_SESSION['CREATED'])) {
    $_SESSION['CREATED'] = time();
} else if (time() - $_SESSION['CREATED'] > 1800) {
    session_regenerate_id(true);
    $_SESSION['CREATED'] = time();
}

// Headers de segurança HTTP.
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header_remove('X-Powered-By');
    // HSTS: habilitar apenas em produção com HTTPS válido.
    // header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}

// Carrega bibliotecas e variáveis de ambiente.
require_once __DIR__ . '/../vendor/autoload.php';
if (class_exists('Dotenv\\Dotenv')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->safeLoad();
}

// Conexão com banco de dados.
$host = $_ENV['DB_HOST'] ?? 'localhost';
$dbname = $_ENV['DB_NAME'] ?? 'analista_financeiro_db';
$usuario = $_ENV['DB_USER'] ?? 'root';
$senha = $_ENV['DB_PASS'] ?? '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $usuario, $senha);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('Erro fatal ao conectar ao banco de dados.');
}

// Utilitário para limpeza de saída textual.
function limpar_dado($dado) {
    return htmlspecialchars(trim($dado), ENT_QUOTES, 'UTF-8');
}

// Token CSRF reutilizável para formulários POST.
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
        die('Falha de validação de segurança. Recarregue a página e tente novamente.');
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

function app_base_url() {
    $appUrl = $_ENV['APP_URL'] ?? '';
    if (is_string($appUrl) && $appUrl !== '') {
        $valid = filter_var($appUrl, FILTER_VALIDATE_URL);
        if (is_string($valid)) {
            $scheme = strtolower((string) parse_url($valid, PHP_URL_SCHEME));
            if ($scheme === 'http' || $scheme === 'https') {
                return rtrim($valid, '/');
            }
        }
    }

    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $isHttps ? 'https' : 'http';
    $serverName = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $host = is_string($serverName) && preg_match('/^[a-z0-9.-]+$/i', $serverName) ? $serverName : 'localhost';
    $port = (int) ($_SERVER['SERVER_PORT'] ?? 0);
    $isDefaultPort = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);

    $hostWithPort = $host;
    if ($port > 0 && !$isDefaultPort) {
        $hostWithPort .= ':' . $port;
    }

    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    if ($basePath === '/' || $basePath === '\\' || $basePath === '.') {
        $basePath = '';
    }

    return $scheme . '://' . $hostWithPort . $basePath;
}

function analises_gratis_restantes_usuario(PDO $pdo, $usuarioId, $limite = 2) {
    $usuarioId = (int) $usuarioId;
    $limite = max(0, (int) $limite);
    if ($usuarioId <= 0 || $limite <= 0) {
        return 0;
    }

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM historico_analises WHERE usuario_id = ? AND YEAR(criado_em) = YEAR(CURDATE()) AND MONTH(criado_em) = MONTH(CURDATE())");
        $stmt->execute([$usuarioId]);
        $total = (int) ($stmt->fetch()['total'] ?? 0);
        return max(0, $limite - $total);
    } catch (Exception $e) {
        return 0;
    }
}
?>