<?php
// index.php
require_once __DIR__ . '/../../config/init.php';

// 1. Lógica de Controle de Acesso e Limites
$isLogged = isset($_SESSION['usuario_id']);
$nomeUsuario = $isLogged ? $_SESSION['usuario_nome'] : 'Visitante';
$planoUsuario = 'visitante';

if ($isLogged) {
    $stmtPlano = $pdo->prepare("SELECT plano FROM usuarios WHERE id = ?");
    $stmtPlano->execute([$_SESSION['usuario_id']]);
    $registroPlano = $stmtPlano->fetch();
    $planoUsuario = $registroPlano['plano'] ?? 'gratis';
    $_SESSION['usuario_plano'] = $planoUsuario;
}

// Lê o cookie de uso do visitante. Se não existir, é 0.
$usoGratuito = ler_uso_gratuito_assinado();
$limiteGratuito = 2;
$analisesRestantes = max(0, $limiteGratuito - $usoGratuito);

// Se for usuário pago, não tem limite de cookie
$temAcesso = ($isLogged && $planoUsuario === 'pro') || ($analisesRestantes > 0);

$pageTitle = "Dashboard - Analista IA";
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container">
    <div class="top-bar">
        <div>
            <span style="font-size: 1.2rem; font-weight: bold;">
                Olá, <?= limpar_dado($nomeUsuario) ?> 👋
            </span>
            <?php if ($planoUsuario !== 'visitante'): ?>
                <span style="background: var(--primary); color: white; padding: 2px 8px; border-radius: 10px; font-size: 11px; margin-left: 10px; text-transform: uppercase;">
                    Plano <?= htmlspecialchars($planoUsuario) ?>
                </span>
            <?php endif; ?>
        </div>
        <div class="nav-links">
            <?php if ($isLogged): ?>
                <a href="historico.php">Meu Histórico</a>
                <a href="logout.php" style="color: var(--danger);">Sair</a>
            <?php else: ?>
                <a href="planos.php" style="color: var(--success);">Ver Planos</a>
                <a href="login.php">Fazer Login</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$isLogged || $planoUsuario === 'gratis'): ?>
        <div style="background: #e9ecef; border: 1px solid #ced4da; padding: 15px; text-align: center; border-radius: 8px; margin-bottom: 30px; font-size: 12px; color: #6c757d;">
            <span style="display: block; margin-bottom: 5px;">PUBLICIDADE</span>
            <a href="#" style="font-size: 16px; font-weight: bold; color: var(--primary); text-decoration: none;">🚀 Cartão de Crédito Black com Limite Alto! Peça o seu agora sem anuidade.</a>
        </div>
    <?php endif; ?>

    <div style="text-align: center;">
        <h2>Análise Financeira Inteligente</h2>
        <p style="color: var(--text-muted); margin-bottom: 20px;">Faça o upload do seu extrato para receber um diagnóstico guiado por IA.</p>
        
        <!-- Indicador de Créditos Visual -->
        <?php 
        $mostrarCreditos = !$isLogged || $planoUsuario === 'gratis';
        if ($mostrarCreditos): 
            $percentualUsado = ($usoGratuito / $limiteGratuito) * 100;
            $percentualRestante = 100 - $percentualUsado;
        ?>
            <div style="margin-bottom: 30px; background: #f5f7fa; border-radius: 12px; border: 1px solid #e0e3e9; padding: 16px; max-width: 400px; margin-left: auto; margin-right: auto;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <span style="font-weight: 700; color: var(--text-main); font-size: 14px;">Análises Disponíveis</span>
                    <span style="background: var(--primary); color: white; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 700;">
                        <?= (int) $analisesRestantes ?>/<?= (int) $limiteGratuito ?>
                    </span>
                </div>
                
                <div style="background: #e0e3e9; border-radius: 999px; height: 10px; overflow: hidden;">
                    <div style="background: linear-gradient(90deg, var(--primary), #0d47a1); height: 100%; width: <?= (int) $percentualUsado ?>%; transition: width 0.4s ease;"></div>
                </div>
                
                <div style="margin-top: 10px; font-size: 12px; color: var(--text-muted);">
                    <?php if ($analisesRestantes > 0): ?>
                        <strong style="color: var(--success);">✓</strong> Você ainda tem <strong><?= (int) $analisesRestantes ?> análise(s)</strong> neste mês.
                    <?php else: ?>
                        <strong style="color: var(--danger);">✗</strong> Você atingiu seu limite. Considere <strong><a href="planos.php" style="color: var(--primary);">assinar</a></strong> para acesso ilimitado.
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif ($isLogged && $planoUsuario === 'pro'): ?>
            <div style="margin-bottom: 30px; background: linear-gradient(135deg, #c8e6c9, #a5d6a7); border-radius: 12px; border: 2px solid var(--success); padding: 16px; max-width: 400px; margin-left: auto; margin-right: auto; color: #1b5e20;">
                <div style="font-weight: 700; font-size: 14px; margin-bottom: 6px;">🚀 Plano Pro</div>
                <div style="font-size: 13px;">Análises ilimitadas - aproveite ao máximo!</div>
            </div>
        <?php endif; ?>

        <div id="form-area">
            <?php if ($temAcesso): ?>
                <form id="uploadForm" action="processar_upload.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <div style="border: 2px dashed var(--primary); padding: 40px; border-radius: 12px; margin-bottom: 20px; background: #f8faff;">
                        <label for="extrato" style="font-size: 18px;">Selecione seu extrato ou planilha:</label>
                        <p style="font-size: 13px; color: var(--text-muted);">PDF, CSV, XLSX, XLS</p>
                        <input type="file" name="extrato" id="extrato" accept=".pdf, .csv, .xlsx, .xls" required style="border: none; padding: 0;">
                    </div>
                    <button type="submit" class="btn">Enviar e Analisar via IA ✨</button>
                </form>
            <?php else: ?>
                <div style="border: 2px dashed var(--danger); padding: 40px; border-radius: 12px; margin-bottom: 20px; background: #fff;">
                    <h3 style="color: var(--danger);">Seu limite gratuito acabou!</h3>
                    <p>Para continuar analisando seus gastos com Inteligência Artificial, libere o acesso ilimitado.</p>
                    <a href="planos.php" class="btn" style="background: var(--success); width: auto; margin-top: 15px;">Ver Planos e Assinar</a>
                </div>
            <?php endif; ?>
        </div>

        <div id="loading-area" style="display: none; padding: 40px 0;">
            <div style="border: 4px solid #f3f3f3; border-top: 4px solid var(--primary); border-radius: 50%; width: 50px; height: 50px; animation: spin 1s linear infinite; margin: 0 auto 20px auto;"></div>
            <h3 style="margin-bottom: 5px;">A IA está trabalhando...</h3>
            <p style="color: var(--text-muted); font-size: 14px;">Lendo transações e calculando métricas. Não feche a página.</p>
        </div>
    </div>
</div>

<style>
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
</style>
        <div style="font-size: 13px; color: var(--text-muted);">Resumo do mes em tempo real</div>