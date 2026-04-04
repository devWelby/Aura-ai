<?php
// ver_relatorio.php
require_once __DIR__ . '/../../config/init.php';

$isLogged = isset($_SESSION['usuario_id']);
$isVisitante = isset($_GET['visitante']) && $_GET['visitante'] === '1';
$idRelatorio = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$isLogged && !$isVisitante) {
    header("Location: login.php");
    exit;
}

if ($isVisitante) {
    if (!isset($_SESSION['relatorio_visitante'])) {
        die("Seu relatório de teste expirou. Por favor, envie o arquivo novamente.");
    }
    $relatorio = $_SESSION['relatorio_visitante'];
} else {
    if ($idRelatorio === false || $idRelatorio === null || $idRelatorio <= 0) {
        die("ID do relatório inválido.");
    }
    try {
        $stmt = $pdo->prepare("SELECT * FROM historico_analises WHERE id = ? AND usuario_id = ?");
        $stmt->execute([$idRelatorio, $_SESSION['usuario_id']]);
        $relatorio = $stmt->fetch();

        if (!$relatorio) {
            die("Relatório não encontrado ou acesso negado.");
        }
    } catch (PDOException $e) {
        die("Erro ao acessar o banco de dados.");
    }
}

$totalEntradas = (float) $relatorio['entradas'];
$totalSaidas = (float) $relatorio['saidas'];
$saldoFinal = (float) $relatorio['saldo'];
$mesReferencia = $relatorio['mes_referencia'];
$dados = json_decode($relatorio['json_completo'], true);
if (!is_array($dados)) {
    $dados = ['transacoes' => [], 'dicas' => []];
}

$gastosPorCategoria = [];
if (isset($dados['transacoes'])) {
    foreach ($dados['transacoes'] as $t) {
        if (is_array($t) && (($t['tipo'] ?? '') === 'saida')) {
            $valor = (float) ($t['valor'] ?? 0);
            $cat = (string) ($t['categoria'] ?? 'Outros');
            if (!isset($gastosPorCategoria[$cat])) {
                $gastosPorCategoria[$cat] = 0.0;
            }
            $gastosPorCategoria[$cat] += $valor;
        }
    }
    arsort($gastosPorCategoria);
}

$categoriasSagas = array_keys($gastosPorCategoria);
$valoresSagos = array_values($gastosPorCategoria);
$labelsChart = json_encode($categoriasSagas, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]';
$valoresChart = json_encode($valoresSagos) ?: '[]';

$pageTitle = "Relatório - " . htmlspecialchars($mesReferencia);
require_once __DIR__ . '/../../includes/header.php';
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<style>
    .badge-sucesso { background: #d4edda; color: #155724; padding: 8px 15px; border-radius: 20px; font-size: 14px; font-weight: bold; margin-right: 10px; display: inline-block;}
    .badge-aviso { background: #fff3cd; color: #856404; padding: 8px 15px; border-radius: 20px; font-size: 14px; font-weight: bold; margin-right: 10px; display: inline-block;}
    .cards { display: flex; gap: 20px; margin-bottom: 40px; flex-wrap: wrap; }
    .card { flex: 1; background: #f8f9fa; padding: 20px; border-radius: 8px; text-align: center; border: 1px solid var(--border-color); min-width: 200px; }
    .card.entradas h3 { color: var(--success); }
    .card.saidas h3 { color: var(--danger); }
    .card h3 { margin: 0 0 10px 0; font-size: 24px; }
    .card p { margin: 0; font-size: 14px; text-transform: uppercase; font-weight: bold; color: var(--text-muted); }
    .grid-despesas { display: flex; gap: 40px; align-items: center; justify-content: center; margin-bottom: 30px; flex-wrap: wrap; }
    .coluna-grafico { flex: 1; min-width: 300px; max-width: 400px; min-height: 350px; position: relative; }
    .coluna-lista { flex: 1; min-width: 300px; }
    .list-group { list-style: none; padding: 0; margin: 0; }
    .list-group li { padding: 12px 15px; border-bottom: 1px solid #f1f1f1; display: flex; justify-content: space-between; align-items: center; }
    .progress-bar { background: #e9ecef; border-radius: 4px; height: 8px; width: 100px; overflow: hidden; margin-left: 15px; display: inline-block; }
    .progress { background: var(--danger); height: 100%; }
    .dicas { background: #e8f0fe; padding: 20px; border-radius: 8px; border-left: 5px solid var(--primary); margin-top: 30px; }
    .dicas ul { margin: 0; padding-left: 20px; }
</style>

<div class="container" id="conteudo-pdf">
    
    <div class="top-bar">
        <h1 style="margin: 0;">Análise de <?= htmlspecialchars($mesReferencia) ?></h1>
        
        <div id="botoes-acao" style="display: flex; gap: 10px; align-items: center;">
            <?php if ($isVisitante): ?>
                <span class="badge-aviso">⚠️ Não Salvo</span>
                <a href="planos.php" class="btn" style="width: auto; background: var(--success); padding: 8px 15px;">Criar Conta</a>
            <?php else: ?>
                <span class="badge-sucesso">✓ Nuvem</span>
                <a href="historico.php" class="btn btn-secondary" style="width: auto; padding: 8px 15px;">Histórico</a>
            <?php endif; ?>
            
            <button onclick="gerarPDF()" class="btn" style="width: auto; background: #333; padding: 8px 15px;">📄 Baixar PDF</button>
        </div>
    </div>

    <div class="cards">
        <div class="card entradas">
            <p>Total de Entradas</p>
            <h3>R$ <?= number_format($totalEntradas, 2, ',', '.') ?></h3>
        </div>
        <div class="card saidas">
            <p>Total de Saídas</p>
            <h3>R$ <?= number_format($totalSaidas, 2, ',', '.') ?></h3>
        </div>
        <div class="card">
            <p>Balanço do Mês</p>
            <h3 style="color: <?= $saldoFinal >= 0 ? 'var(--success)' : 'var(--danger)' ?>;">
                R$ <?= number_format($saldoFinal, 2, ',', '.') ?>
            </h3>
        </div>
    </div>

    <h2 style="border-bottom: 2px solid var(--border-color); padding-bottom: 10px;">Distribuição de Despesas</h2>
    
    <div class="grid-despesas">
        <div class="coluna-grafico">
            <canvas id="graficoDespesas"></canvas>
        </div>
        
        <div class="coluna-lista">
            <ul class="list-group">
                <?php foreach($gastosPorCategoria as $categoria => $valor): 
                    $percentual = $totalSaidas > 0 ? ($valor / $totalSaidas) * 100 : 0;
                ?>
                    <li>
                        <span style="flex-grow: 1;"><strong><?= htmlspecialchars($categoria) ?></strong></span>
                        <span>R$ <?= number_format($valor, 2, ',', '.') ?> (<?= number_format($percentual, 1, ',', '.') ?>%)</span>
                        <div class="progress-bar"><div class="progress" style="width: <?= $percentual ?>%;"></div></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <div class="dicas">
        <h2 style="border-bottom: 2px solid #aecbfa; padding-bottom: 10px;">Plano de Ação (Gerado pela IA)</h2>
        <ul>
            <?php 
            if (isset($dados['dicas'])) {
                foreach($dados['dicas'] as $dica) {
                    echo "<li>" . htmlspecialchars($dica) . "</li>";
                }
            } else {
                echo "<li>Nenhuma dica disponível neste relatório.</li>";
            }
            ?>
        </ul>
    </div>
    
    <div style="font-size: 13px; color: var(--text-muted); margin-top: 30px; text-align: center;">
        Relatório processado em: <?= date('d/m/Y \à\s H:i', strtotime($relatorio['criado_em'] ?? date('Y-m-d H:i:s'))) ?>
    </div>
    
    <?php if ($isVisitante): ?>
    <div id="btn-novo-teste" style="text-align: center; margin-top: 30px;">
        <a href="index.php" class="btn" style="width: auto;">Analisar Novo Arquivo</a>
    </div>
    <?php endif; ?>
</div>

<script>
    // Configuração do Gráfico Chart.js
    const rawLabels = <?= $labelsChart ?>;
    const rawData = <?= $valoresChart ?>;

    if(rawLabels.length > 0) {
        const ctx = document.getElementById('graficoDespesas').getContext('2d');
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: rawLabels,
                datasets: [{
                    data: rawData,
                    backgroundColor: ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40', '#EA6A47', '#A5D8DD', '#5E92F3'],
                    borderWidth: 2, borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                animation: { duration: 0 }, // 🚨 Removemos a animação para o PDF não bater a foto com o gráfico pela metade
                plugins: { legend: { position: 'bottom' } },
                cutout: '65%'
            }
        });
    } else {
        document.querySelector('.coluna-grafico').innerHTML = '<p style="text-align:center; color:#888; padding-top:40px;">Sem dados para o gráfico.</p>';
    }

    // 🚨 4. A FUNÇÃO DE EXPORTAÇÃO EM PDF
    function gerarPDF() {
        // Altera o texto do botão para dar feedback
        const btnBotoes = document.getElementById('botoes-acao');
        const btnOriginalHTML = btnBotoes.innerHTML;
        btnBotoes.innerHTML = '<span style="color:var(--primary); font-weight:bold;">⏳ Gerando PDF...</span>';

        // Esconde o botão de "Novo Teste" se for visitante
        const btnNovoTeste = document.getElementById('btn-novo-teste');
        if (btnNovoTeste) btnNovoTeste.style.display = 'none';

        // Seleciona a div que contém todo o conteúdo
        const elemento = document.getElementById('conteudo-pdf');

        // Configurações de alta qualidade
        const opcoes = {
            margin:       10,
            filename:     'Diagnostico_Financeiro_<?= str_replace('/', '_', $mesReferencia) ?>.pdf',
            image:        { type: 'jpeg', quality: 0.98 },
            html2canvas:  { scale: 2, useCORS: true }, // O scale 2 deixa o texto e gráfico super nítidos
            jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
        };

        // Roda a biblioteca, baixa o arquivo e restaura os botões
        html2pdf().set(opcoes).from(elemento).save().then(() => {
            btnBotoes.innerHTML = btnOriginalHTML;
            if (btnNovoTeste) btnNovoTeste.style.display = 'block';
        });
    }
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>