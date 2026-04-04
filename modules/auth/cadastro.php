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