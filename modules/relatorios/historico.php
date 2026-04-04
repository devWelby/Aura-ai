<?php
// historico.php
require_once __DIR__ . '/../../config/init.php';

if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, mes_referencia, entradas, saidas, saldo, criado_em FROM historico_analises WHERE usuario_id = ? ORDER BY criado_em DESC");
    $stmt->execute([$_SESSION['usuario_id']]);
    $historico = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Erro ao buscar histórico.");
}

$pageTitle = "Meu Histórico - Analista IA";
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container">
    <div class="top-bar">
        <h1 style="margin: 0;">Meu Histórico 🗓️</h1>
        <div>
            <a href="index.php" class="btn" style="width: auto; padding: 10px 20px;">Novo Upload</a>
        </div>
    </div>

    <?php if (count($historico) > 0): ?>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; margin-top: 10px;">
                <thead>
                    <tr>
                        <th style="padding: 15px; text-align: left; border-bottom: 2px solid var(--border-color); color: var(--text-muted); text-transform: uppercase; font-size: 13px;">Data da Análise</th>
                        <th style="padding: 15px; text-align: left; border-bottom: 2px solid var(--border-color); color: var(--text-muted); text-transform: uppercase; font-size: 13px;">Período</th>
                        <th style="padding: 15px; text-align: left; border-bottom: 2px solid var(--border-color); color: var(--text-muted); text-transform: uppercase; font-size: 13px;">Entradas</th>
                        <th style="padding: 15px; text-align: left; border-bottom: 2px solid var(--border-color); color: var(--text-muted); text-transform: uppercase; font-size: 13px;">Saídas</th>
                        <th style="padding: 15px; text-align: left; border-bottom: 2px solid var(--border-color); color: var(--text-muted); text-transform: uppercase; font-size: 13px;">Balanço</th>
                        <th style="padding: 15px; text-align: center; border-bottom: 2px solid var(--border-color); color: var(--text-muted); text-transform: uppercase; font-size: 13px;">Ação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($historico as $item): 
                        $dataAnalise = date('d/m/Y \à\s H:i', strtotime($item['criado_em']));
                        $corSaldo = $item['saldo'] >= 0 ? 'var(--success)' : 'var(--danger)';
                    ?>
                        <tr style="border-bottom: 1px solid var(--border-color); transition: 0.2s;">
                            <td style="padding: 15px;"><?= $dataAnalise ?></td>
                            <td style="padding: 15px; font-weight: 600;"><?= htmlspecialchars($item['mes_referencia']) ?></td>
                            <td style="padding: 15px; color: var(--success); font-weight: 600;">R$ <?= number_format($item['entradas'], 2, ',', '.') ?></td>
                            <td style="padding: 15px; color: var(--danger); font-weight: 600;">R$ <?= number_format($item['saidas'], 2, ',', '.') ?></td>
                            <td style="padding: 15px; color: <?= $corSaldo ?>; font-weight: 600;">R$ <?= number_format($item['saldo'], 2, ',', '.') ?></td>
                            <td style="padding: 15px; text-align: center;">
                                <a href="ver_relatorio.php?id=<?= $item['id'] ?>" class="btn btn-secondary" style="padding: 8px 15px; font-size: 13px; width: auto;">Abrir</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div style="text-align: center; padding: 50px 20px; color: var(--text-muted);">
            <h3>Você ainda não possui análises no histórico.</h3>
            <p>Envie seu primeiro extrato para ver os relatórios salvos aqui.</p>
        </div>
    <?php endif; ?>
</div>

<style>
    tbody tr:hover { background-color: #f8faff; }
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>