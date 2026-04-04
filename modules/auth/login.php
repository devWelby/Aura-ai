<?php
// login.php
require_once __DIR__ . '/../../config/init.php';

// Se já estiver logado, redireciona para o painel principal
if (isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit;
}

$erro = '';
$sucesso = '';

if (($_GET['msg'] ?? '') === 'conta_ativada') {
    $sucesso = 'Conta ativada com sucesso! Agora você pode fazer login.';
}

// Proteção contra brute-force: máximo de 5 tentativas, com bloqueio de 15 minutos.
$bfKey = 'login_bf';
if (!isset($_SESSION[$bfKey]) || !is_array($_SESSION[$bfKey])) {
    $_SESSION[$bfKey] = ['count' => 0, 'locked_until' => 0];
}
$bf = &$_SESSION[$bfKey];

// Libera o bloqueio se o tempo já passou.
if ((int) $bf['locked_until'] > 0 && time() >= (int) $bf['locked_until']) {
    $bf['count'] = 0;
    $bf['locked_until'] = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Verifica bloqueio antes de qualquer processamento.
    if (time() < (int) $bf['locked_until']) {
        $restante = (int) $bf['locked_until'] - time();
        $erro = "Muitas tentativas incorretas. Aguarde {$restante} segundo(s) e tente novamente.";
    } else {
        validar_csrf_post();

        $email = limpar_dado($_POST['email'] ?? '');
        $senha = trim($_POST['senha'] ?? '');

        $stmt = $pdo->prepare("SELECT id, nome, senha, plano, email_verificado FROM usuarios WHERE email = ?");
        $stmt->execute([$email]);
        $usuario = $stmt->fetch();

        if ($usuario && password_verify($senha, $usuario['senha'])) {
            // Exige e-mail verificado antes de liberar login.
            if ((int) ($usuario['email_verificado'] ?? 0) === 0) {
                $erro = 'Sua conta ainda não foi ativada. Verifique sua caixa de entrada ou spam.';
            } else {
                // Login bem-sucedido: zera contador e regenera sessão.
                $bf['count'] = 0;
                $bf['locked_until'] = 0;

                session_regenerate_id(true);
                $_SESSION['usuario_id'] = $usuario['id'];
                $_SESSION['usuario_nome'] = $usuario['nome'];
                $_SESSION['usuario_plano'] = $usuario['plano'] ?? 'gratis';

                header('Location: index.php');
                exit;
            }
        } else {
            // Incrementa contador de falhas.
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
    
    <?php if ($sucesso): ?> <div class="msg sucesso"><?= htmlspecialchars($sucesso, ENT_QUOTES, 'UTF-8') ?></div> <?php endif; ?>
    <?php if ($erro): ?> <div class="msg erro"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></div> <?php endif; ?>

    <a href="login_google.php" class="btn" style="background: #fff; color: #444; border: 1px solid #ddd; margin-bottom: 20px; display: flex; align-items: center; justify-content: center; gap: 10px;">
        <svg width="18" height="18" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
        Continuar com o Google
    </a>

    <div style="text-align: center; margin: 15px 0; color: #888; font-size: 14px;">ou use seu e-mail</div>

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