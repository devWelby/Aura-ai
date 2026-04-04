<?php
// cadastro.php
require_once __DIR__ . '/../../config/init.php';

if (isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit;
}

$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validar_csrf_post();

    $nome = limpar_dado($_POST['nome'] ?? '');
    $email = limpar_dado($_POST['email'] ?? '');
    $senha = trim($_POST['senha'] ?? '');

    if (empty($nome) || empty($email) || empty($senha)) {
        $erro = "Por favor, preencha todos os campos.";
    } elseif (strlen($senha) < 8) {
        $erro = "A senha deve ter no minimo 8 caracteres.";
    } elseif (!preg_match('/[A-Z]/', $senha) || !preg_match('/[0-9]/', $senha)) {
        $erro = "A senha deve conter pelo menos uma letra maiuscula e um numero.";
    } else {
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
        $stmt->execute([$email]);
        
        if ($stmt->rowCount() > 0) {
            $erro = "Este e-mail já está cadastrado.";
        } else {
            $senhaHash = password_hash($senha, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO usuarios (nome, email, senha) VALUES (?, ?, ?)");
            
            if ($stmt->execute([$nome, $email, $senhaHash])) {
                $sucesso = "Cadastro realizado com sucesso! Você já pode fazer login.";
            } else {
                $erro = "Erro interno ao cadastrar. Tente novamente.";
            }
        }
    }
}

$pageTitle = "Cadastro - Analista IA";
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container container-small">
    <h2 style="text-align: center;">Criar Conta</h2>
    
    <?php if ($erro): ?> <div class="msg erro"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></div> <?php endif; ?>
    <?php if ($sucesso): ?> <div class="msg sucesso"><?= htmlspecialchars($sucesso, ENT_QUOTES, 'UTF-8') ?></div> <?php endif; ?>

    <a href="login_google.php" class="btn" style="background: #fff; color: #444; border: 1px solid #ddd; margin-bottom: 20px; display: flex; align-items: center; justify-content: center; gap: 10px;">
        <svg width="18" height="18" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
        Continuar com o Google
    </a>

    <div style="text-align: center; margin: 15px 0; color: #888; font-size: 14px;">ou use seu e-mail</div>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <label>Nome Completo</label>
        <input type="text" name="nome" required>
        
        <label>E-mail</label>
        <input type="email" name="email" required>
        
        <label>Senha</label>
        <input type="password" name="senha" required minlength="6">
        
        <button type="submit" class="btn" style="background: var(--success);">Criar Conta</button>
    </form>
    
    <div style="text-align: center; margin-top: 20px;">
        <a href="login.php" style="color: var(--primary); text-decoration: none; font-weight: 600;">Já tem uma conta? Faça Login</a>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>