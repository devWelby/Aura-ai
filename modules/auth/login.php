<?php
// login.php
require_once __DIR__ . '/../../config/init.php';

// Se já estiver logado, manda para o painel principal
if (isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit;
}

$erro = '';

// Protecao contra Brute-Force: max 5 tentativas falhas, bloqueio de 15 minutos
$bfKey = 'login_bf';
if (!isset($_SESSION[$bfKey]) || !is_array($_SESSION[$bfKey])) {
    $_SESSION[$bfKey] = ['count' => 0, 'locked_until' => 0];
}
$bf = &$_SESSION[$bfKey];

// Libera o bloqueio se o tempo ja passou
if ((int) $bf['locked_until'] > 0 && time() >= (int) $bf['locked_until']) {
    $bf['count'] = 0;
    $bf['locked_until'] = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Verifica bloqueio antes de qualquer processamento
    if (time() < (int) $bf['locked_until']) {
        $restante = (int) $bf['locked_until'] - time();
        $erro = "Muitas tentativas incorretas. Aguarde {$restante} segundo(s) e tente novamente.";
    } else {
        validar_csrf_post();

        $email = limpar_dado($_POST['email'] ?? '');
        $senha = trim($_POST['senha'] ?? '');

        $stmt = $pdo->prepare("SELECT id, nome, senha FROM usuarios WHERE email = ?");
        $stmt->execute([$email]);
        $usuario = $stmt->fetch();

        if ($usuario && password_verify($senha, $usuario['senha'])) {
            // Login bem-sucedido: zera o contador e regenera sessao
            $bf['count'] = 0;
            $bf['locked_until'] = 0;

            session_regenerate_id(true);
            $_SESSION['usuario_id'] = $usuario['id'];
            $_SESSION['usuario_nome'] = $usuario['nome'];

            $stmtPlano = $pdo->prepare("SELECT plano FROM usuarios WHERE id = ?");
            $stmtPlano->execute([$usuario['id']]);
            $registroPlano = $stmtPlano->fetch();
            $_SESSION['usuario_plano'] = $registroPlano['plano'] ?? 'gratis';

            header('Location: index.php');
            exit;
        } else {
            // Incrementa contador de falhas
            $bf['count']++;
            if ($bf['count'] >= 5) {
                $bf['locked_until'] = time() + 900; // 15 minutos
                $bf['count'] = 0;
                $erro = 'Muitas tentativas incorretas. Conta bloqueada por 15 minutos.';
            } else {
                $restantes = 5 - $bf['count'];
                $erro = "E-mail ou senha incorretos. {$restantes} tentativa(s) restante(s) antes do bloqueio.";
            }
        }
    }
}

$pageTitle = "Login - Analista IA";
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container container-small">
    <h2 style="text-align: center;">Entrar</h2>
    
    <?php if ($erro): ?> <div class="msg erro"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></div> <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <label>E-mail</label>
        <input type="email" name="email" required>
        
        <label>Senha</label>
        <input type="password" name="senha" required>
        
        <button type="submit" class="btn">Acessar Sistema</button>
    </form>
    
    <div style="text-align: center; margin-top: 20px;">
        <a href="cadastro.php" style="color: var(--primary); text-decoration: none; font-weight: 600;">Não tem uma conta? Cadastre-se</a>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>