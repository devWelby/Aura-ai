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
$entradasPorCategoria = [];
$maiorTransacaoSaida = null;
$maiorTransacaoEntrada = null;
$qtdEntradas = 0;
$qtdSaidas = 0;
$somaSaidas = 0.0;
$somaEntradas = 0.0;

if (isset($dados['transacoes'])) {
    foreach ($dados['transacoes'] as $t) {
        if (!is_array($t)) {
            continue;
        }

        $tipo = (string) ($t['tipo'] ?? '');
        $valor = max(0.0, (float) ($t['valor'] ?? 0));
        $cat = trim((string) ($t['categoria'] ?? 'Outros'));
        if ($cat === '') {
            $cat = 'Outros';
        }

        if ($tipo === 'saida') {
            $qtdSaidas++;
            $somaSaidas += $valor;
            if (!isset($gastosPorCategoria[$cat])) {
                $gastosPorCategoria[$cat] = 0.0;
            }
            $gastosPorCategoria[$cat] += $valor;

            if ($maiorTransacaoSaida === null || $valor > (float) $maiorTransacaoSaida['valor']) {
                $maiorTransacaoSaida = [
                    'categoria' => $cat,
                    'valor' => $valor,
                    'descricao' => (string) ($t['descricao'] ?? 'Sem descricao')
                ];
            }
        } elseif ($tipo === 'entrada') {
            $qtdEntradas++;
            $somaEntradas += $valor;
            if (!isset($entradasPorCategoria[$cat])) {
                $entradasPorCategoria[$cat] = 0.0;
            }
            $entradasPorCategoria[$cat] += $valor;

            if ($maiorTransacaoEntrada === null || $valor > (float) $maiorTransacaoEntrada['valor']) {
                $maiorTransacaoEntrada = [
                    'categoria' => $cat,
                    'valor' => $valor,
                    'descricao' => (string) ($t['descricao'] ?? 'Sem descricao')
                ];
            }
        }
    }
    arsort($gastosPorCategoria);
    arsort($entradasPorCategoria);
}

$categoriasSagas = array_keys($gastosPorCategoria);
$valoresSagos = array_values($gastosPorCategoria);
$labelsChart = json_encode($categoriasSagas, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]';
$valoresChart = json_encode($valoresSagos) ?: '[]';

$totalTransacoes = $qtdEntradas + $qtdSaidas;
$ticketMedioSaida = $qtdSaidas > 0 ? ($somaSaidas / $qtdSaidas) : 0.0;
$ticketMedioEntrada = $qtdEntradas > 0 ? ($somaEntradas / $qtdEntradas) : 0.0;
$taxaPoupanca = $totalEntradas > 0 ? (($saldoFinal / $totalEntradas) * 100) : 0.0;
$comprometimentoRenda = $totalEntradas > 0 ? (($totalSaidas / $totalEntradas) * 100) : 0.0;
$runwayMeses = $totalSaidas > 0 ? ($totalEntradas / $totalSaidas) : 0.0;

$topCategoriaValor = !empty($gastosPorCategoria) ? (float) reset($gastosPorCategoria) : 0.0;
$topCategoriaNome = !empty($gastosPorCategoria) ? (string) key($gastosPorCategoria) : 'Sem dados';
$dependenciaTopCategoria = $totalSaidas > 0 ? (($topCategoriaValor / $totalSaidas) * 100) : 0.0;

$scoreSaude = 100;
if ($saldoFinal < 0) {
    $scoreSaude -= 35;
}
if ($comprometimentoRenda > 90) {
    $scoreSaude -= 25;
} elseif ($comprometimentoRenda > 75) {
    $scoreSaude -= 12;
}
if ($dependenciaTopCategoria > 45) {
    $scoreSaude -= 15;
}
if ($qtdSaidas > ($qtdEntradas * 3) && $qtdEntradas > 0) {
    $scoreSaude -= 8;
}
$scoreSaude = max(0, min(100, $scoreSaude));

$projecaoAnualEntradas = $totalEntradas * 12;
$projecaoAnualSaidas = $totalSaidas * 12;
$projecaoAnualSaldo = $saldoFinal * 12;

$topSaidasDetalhes = [];
if (isset($dados['transacoes']) && is_array($dados['transacoes'])) {
    foreach ($dados['transacoes'] as $t) {
        if (!is_array($t) || (string) ($t['tipo'] ?? '') !== 'saida') {
            continue;
        }
        $topSaidasDetalhes[] = [
            'descricao' => (string) ($t['descricao'] ?? 'Sem descricao'),
            'categoria' => (string) ($t['categoria'] ?? 'Outros'),
            'valor' => max(0.0, (float) ($t['valor'] ?? 0))
        ];
    }
    usort($topSaidasDetalhes, function ($a, $b) {
        return $b['valor'] <=> $a['valor'];
    });
    $topSaidasDetalhes = array_slice($topSaidasDetalhes, 0, 8);
}

$diagnosticoRapido = [];
if ($saldoFinal < 0) {
    $diagnosticoRapido[] = 'Seu mes fechou no negativo. Corte despesas variaveis e renegocie contas fixas.';
}
if ($dependenciaTopCategoria >= 40) {
    $diagnosticoRapido[] = 'Concentracao alta em ' . $topCategoriaNome . ' (' . number_format($dependenciaTopCategoria, 1, ',', '.') . '% das saidas).';
}
if ($taxaPoupanca >= 20) {
    $diagnosticoRapido[] = 'Boa disciplina: taxa de poupanca acima de 20%.';
} elseif ($taxaPoupanca > 0) {
    $diagnosticoRapido[] = 'Voce poupou, mas pode evoluir para uma meta de 20% da renda.';
}
if (empty($diagnosticoRapido)) {
    $diagnosticoRapido[] = 'Fluxo financeiro estavel. Continue acompanhando para evitar picos de gasto.';
}

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
    .section-title { border-bottom: 2px solid var(--border-color); padding-bottom: 10px; margin-top: 30px; }
    .mini-cards { display: grid; grid-template-columns: repeat(4, minmax(160px, 1fr)); gap: 12px; margin-bottom: 25px; }
    .mini-card { border: 1px solid var(--border-color); border-radius: 10px; padding: 14px; background: #fff; }
    .mini-card .label { font-size: 12px; color: var(--text-muted); text-transform: uppercase; font-weight: 700; margin-bottom: 6px; }
    .mini-card .value { font-size: 20px; font-weight: 800; color: var(--text-main); }
    .painel-saude { display: flex; gap: 16px; align-items: stretch; flex-wrap: wrap; margin-bottom: 20px; }
    .score-box { flex: 1; min-width: 240px; border: 1px solid var(--border-color); border-radius: 10px; padding: 16px; background: #fff; }
    .score-track { background: #edf2f7; border-radius: 999px; height: 12px; overflow: hidden; }
    .score-fill { background: linear-gradient(90deg, #f44336, #ff9800, #4caf50); height: 100%; }
    .diagnostico-lista { flex: 2; min-width: 260px; border: 1px solid var(--border-color); border-radius: 10px; padding: 16px; background: #fff; }
    .diagnostico-lista ul { margin: 0; padding-left: 18px; }
    .projecoes { display: grid; grid-template-columns: repeat(3, minmax(180px, 1fr)); gap: 12px; margin-bottom: 20px; }
    .projecao { border: 1px dashed #cfd8dc; border-radius: 10px; padding: 14px; background: #fbfcff; }
    .tabela-top { width: 100%; border-collapse: collapse; margin-top: 10px; }
    .tabela-top th, .tabela-top td { border-bottom: 1px solid #edf0f2; padding: 10px; text-align: left; font-size: 14px; }
    .tabela-top th { color: var(--text-muted); text-transform: uppercase; font-size: 12px; }

    @media (max-width: 840px) {
        .mini-cards { grid-template-columns: repeat(2, minmax(140px, 1fr)); }
        .projecoes { grid-template-columns: 1fr; }
    }
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

    <div class="mini-cards">
        <div class="mini-card">
            <div class="label">Taxa de poupanca</div>
            <div class="value" style="color: <?= $taxaPoupanca >= 20 ? 'var(--success)' : 'var(--text-main)' ?>;"><?= number_format($taxaPoupanca, 1, ',', '.') ?>%</div>
        </div>
        <div class="mini-card">
            <div class="label">Comprometimento da renda</div>
            <div class="value" style="color: <?= $comprometimentoRenda > 90 ? 'var(--danger)' : 'var(--text-main)' ?>;"><?= number_format($comprometimentoRenda, 1, ',', '.') ?>%</div>
        </div>
        <div class="mini-card">
            <div class="label">Ticket medio de gasto</div>
            <div class="value">R$ <?= number_format($ticketMedioSaida, 2, ',', '.') ?></div>
        </div>
        <div class="mini-card">
            <div class="label">Transacoes no periodo</div>
            <div class="value"><?= (int) $totalTransacoes ?></div>
        </div>
    </div>

    <div class="painel-saude">
        <div class="score-box">
            <p style="margin: 0 0 8px 0; color: var(--text-muted); text-transform: uppercase; font-size: 12px; font-weight: 700;">Score de saude financeira</p>
            <h3 style="margin: 0 0 12px 0;"><?= (int) $scoreSaude ?>/100</h3>
            <div class="score-track">
                <div class="score-fill" style="width: <?= (int) $scoreSaude ?>%;"></div>
            </div>
            <p style="margin: 12px 0 0 0; color: var(--text-muted); font-size: 13px;">Baseado em saldo, concentracao de gastos e relacao entradas/saidas.</p>
        </div>

        <div class="diagnostico-lista">
            <p style="margin: 0 0 8px 0; color: var(--text-muted); text-transform: uppercase; font-size: 12px; font-weight: 700;">Diagnostico rapido</p>
            <ul>
                <?php foreach ($diagnosticoRapido as $item): ?>
                    <li><?= htmlspecialchars($item, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <h2 class="section-title">Projecao Anual (mantendo o padrao atual)</h2>
    <div class="projecoes">
        <div class="projecao">
            <div style="font-size: 12px; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Entradas projetadas</div>
            <div style="font-size: 24px; font-weight: 800; color: var(--success);">R$ <?= number_format($projecaoAnualEntradas, 2, ',', '.') ?></div>
        </div>
        <div class="projecao">
            <div style="font-size: 12px; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Saidas projetadas</div>
            <div style="font-size: 24px; font-weight: 800; color: var(--danger);">R$ <?= number_format($projecaoAnualSaidas, 2, ',', '.') ?></div>
        </div>
        <div class="projecao">
            <div style="font-size: 12px; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Saldo anual estimado</div>
            <div style="font-size: 24px; font-weight: 800; color: <?= $projecaoAnualSaldo >= 0 ? 'var(--success)' : 'var(--danger)' ?>;">R$ <?= number_format($projecaoAnualSaldo, 2, ',', '.') ?></div>
        </div>
    </div>

    <h2 class="section-title">Indicadores de Comportamento</h2>
    <div class="mini-cards" style="margin-top: 10px;">
        <div class="mini-card">
            <div class="label">Maior saida</div>
            <div class="value" style="font-size: 17px;"><?= $maiorTransacaoSaida ? 'R$ ' . number_format((float) $maiorTransacaoSaida['valor'], 2, ',', '.') : 'N/D' ?></div>
            <div style="font-size: 13px; color: var(--text-muted);"><?= $maiorTransacaoSaida ? htmlspecialchars((string) $maiorTransacaoSaida['categoria'], ENT_QUOTES, 'UTF-8') : '' ?></div>
        </div>
        <div class="mini-card">
            <div class="label">Maior entrada</div>
            <div class="value" style="font-size: 17px;"><?= $maiorTransacaoEntrada ? 'R$ ' . number_format((float) $maiorTransacaoEntrada['valor'], 2, ',', '.') : 'N/D' ?></div>
            <div style="font-size: 13px; color: var(--text-muted);"><?= $maiorTransacaoEntrada ? htmlspecialchars((string) $maiorTransacaoEntrada['categoria'], ENT_QUOTES, 'UTF-8') : '' ?></div>
        </div>
        <div class="mini-card">
            <div class="label">Dependencia da principal categoria</div>
            <div class="value" style="font-size: 17px;"><?= number_format($dependenciaTopCategoria, 1, ',', '.') ?>%</div>
            <div style="font-size: 13px; color: var(--text-muted);"><?= htmlspecialchars($topCategoriaNome, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <div class="mini-card">
            <div class="label">Relacao entradas/saidas</div>
            <div class="value" style="font-size: 17px;"><?= number_format($runwayMeses, 2, ',', '.') ?>x</div>
            <div style="font-size: 13px; color: var(--text-muted);">Quanto sua renda cobre dos gastos</div>
        </div>
    </div>

    <h2 class="section-title">Distribuicao de Despesas</h2>
    
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

    <h2 class="section-title">Top Gastos do Periodo</h2>
    <?php if (!empty($topSaidasDetalhes)): ?>
        <div style="overflow-x: auto;">
            <table class="tabela-top">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Descricao</th>
                        <th>Categoria</th>
                        <th>Valor</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($topSaidasDetalhes as $idx => $gasto): ?>
                        <tr>
                            <td><?= (int) ($idx + 1) ?></td>
                            <td><?= htmlspecialchars((string) $gasto['descricao'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $gasto['categoria'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="color: var(--danger); font-weight: 700;">R$ <?= number_format((float) $gasto['valor'], 2, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p style="color: var(--text-muted);">Nao ha gastos suficientes para montar o ranking.</p>
    <?php endif; ?>

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
    // Configuração do gráfico Chart.js.
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
                animation: { duration: 0 },
                plugins: { legend: { position: 'bottom' } },
                cutout: '65%'
            }
        });
    } else {
        document.querySelector('.coluna-grafico').innerHTML = '<p style="text-align:center; color:#888; padding-top:40px;">Sem dados para o gráfico.</p>';
    }

    // Exportação em PDF.
    function gerarPDF() {
        // Feedback visual durante a geração.
        const btnBotoes = document.getElementById('botoes-acao');
        const btnOriginalHTML = btnBotoes.innerHTML;
        btnBotoes.innerHTML = '<span style="color:var(--primary); font-weight:bold;">⏳ Gerando PDF...</span>';

        // Esconde botão secundário para não aparecer no PDF.
        const btnNovoTeste = document.getElementById('btn-novo-teste');
        if (btnNovoTeste) btnNovoTeste.style.display = 'none';

        const elemento = document.getElementById('conteudo-pdf');

        const opcoes = {
            margin:       10,
            filename:     'Diagnostico_Financeiro_<?= str_replace('/', '_', $mesReferencia) ?>.pdf',
            image:        { type: 'jpeg', quality: 0.98 },
            html2canvas:  { scale: 2, useCORS: true },
            jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
        };

        html2pdf().set(opcoes).from(elemento).save().then(() => {
            btnBotoes.innerHTML = btnOriginalHTML;
            if (btnNovoTeste) btnNovoTeste.style.display = 'block';
        });
    }
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>