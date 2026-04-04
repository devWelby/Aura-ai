<?php
// planos.php
require_once __DIR__ . '/../../config/init.php';
$pageTitle = "Escolha seu Plano - Analista IA";
require_once __DIR__ . '/../../includes/header.php';
?>

<style>
    .pricing-grid { display: flex; gap: 30px; margin-top: 40px; flex-wrap: wrap; justify-content: center; }
    .plan-card { background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 40px 30px; flex: 1; min-width: 250px; max-width: 350px; text-align: center; position: relative; transition: 0.3s; }
    .plan-card:hover { transform: translateY(-5px); box-shadow: 0 10px 40px rgba(0,0,0,0.08); }
    .plan-card.destaque { border: 2px solid var(--primary); box-shadow: 0 10px 30px rgba(26, 115, 232, 0.15); }
    .badge-pop { position: absolute; top: -12px; left: 50%; transform: translateX(-50%); background: var(--primary); color: white; padding: 4px 15px; border-radius: 20px; font-size: 12px; font-weight: bold; letter-spacing: 1px; }
    .plan-price { font-size: 40px; font-weight: bold; color: var(--text-main); margin: 20px 0; }
    .plan-price span { font-size: 16px; color: var(--text-muted); font-weight: normal; }
    .plan-features { list-style: none; padding: 0; margin: 30px 0; text-align: left; }
    .plan-features li { padding: 10px 0; border-bottom: 1px solid #f1f1f1; font-size: 15px; color: #555; }
    .plan-features li::before { content: '✓'; color: var(--success); font-weight: bold; margin-right: 10px; }
</style>

<div class="container" style="max-width: 1000px;">
    <div class="top-bar">
        <h1 style="margin: 0;">Planos e Preços</h1>
        <a href="index.php" class="btn btn-secondary" style="width: auto;">Voltar</a>
    </div>

    <div style="text-align: center; margin-top: 20px;">
        <h2 style="font-size: 32px; color: var(--text-main);">Assuma o controle do seu dinheiro.</h2>
        <p style="color: var(--text-muted); font-size: 18px;">Escolha o plano ideal para a sua saúde financeira.</p>
    </div>

    <div class="pricing-grid">
        <div class="plan-card">
            <h3 style="color: var(--text-muted); font-size: 20px;">Gratuito</h3>
            <div class="plan-price">R$ 0<span>/mês</span></div>
            <p style="color: var(--text-muted); font-size: 14px;">Para quem quer testar a tecnologia.</p>
            
            <ul class="plan-features">
                <li>2 Análises de extrato por mês</li>
                <li>Relatório resumido</li>
                <li>Exibição de Anúncios</li>
                <li style="color: #ccc; text-decoration: line-through;">Sem Histórico Salvo</li>
            </ul>
            
            <a href="cadastro.php" class="btn btn-secondary">Criar Conta Grátis</a>
        </div>

        <div class="plan-card destaque">
            <div class="badge-pop">MAIS ESCOLHIDO</div>
            <h3 style="color: var(--primary); font-size: 20px;">Profissional</h3>
            <div class="plan-price">R$ 19<span>,90/mês</span></div>
            <p style="color: var(--text-muted); font-size: 14px;">Para quem leva as finanças a sério.</p>
            
            <ul class="plan-features">
                <li><strong>Análises Ilimitadas</strong></li>
                <li>Painel com Gráficos Avançados</li>
                <li><strong>Zero Anúncios</strong></li>
                <li>Histórico Salvo na Nuvem</li>
                <li>Dicas de Economia Premium (IA)</li>
            </ul>

            <form method="POST" action="checkout.php" style="margin: 0;">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <button type="submit" class="btn" style="border: none; cursor: pointer;">Assinar Agora</button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>