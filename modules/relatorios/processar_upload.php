<?php
require_once __DIR__ . '/../../config/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

validar_csrf_post();

// Controle de acesso ao plano e aos créditos gratuitos.
$isLogged = isset($_SESSION['usuario_id']);
$usoGratuito = ler_uso_gratuito_assinado();

$planoUsuario = 'visitante';
if ($isLogged) {
    $stmtPlano = $pdo->prepare("SELECT plano FROM usuarios WHERE id = ?");
    $stmtPlano->execute([$_SESSION['usuario_id']]);
    $registroPlano = $stmtPlano->fetch();
    $planoUsuario = $registroPlano['plano'] ?? 'gratis';
    $_SESSION['usuario_plano'] = $planoUsuario;
}

$limiteGratuito = 2;

if (!$isLogged && $usoGratuito >= $limiteGratuito) {
    header("Location: planos.php?msg=limite_excedido");
    exit;
}

if ($isLogged && $planoUsuario !== 'pro') {
    $restantesLogado = analises_gratis_restantes_usuario($pdo, (int) $_SESSION['usuario_id'], $limiteGratuito);
    if ($restantesLogado <= 0) {
        header("Location: planos.php?msg=limite_excedido");
        exit;
    }
}

// Rate limit simples em sessão para conter abuso de API.
if (!isset($_SESSION['upload_rate']) || !is_array($_SESSION['upload_rate'])) {
    $_SESSION['upload_rate'] = ['count' => 0, 'reset_at' => time() + 3600];
}
if (time() > (int) $_SESSION['upload_rate']['reset_at']) {
    $_SESSION['upload_rate'] = ['count' => 0, 'reset_at' => time() + 3600];
}
$_SESSION['upload_rate']['count']++;
if ((int) $_SESSION['upload_rate']['count'] > 10) {
    http_response_code(429);
    die('Muitas análises em pouco tempo. Tente novamente em alguns minutos.');
}

$apiKey = (string) ($_ENV['GEMINI_API_KEY'] ?? '');
if ($apiKey === '') {
    http_response_code(500);
    die('Serviço indisponível no momento.');
}

if (!isset($_FILES['extrato']) || $_FILES['extrato']['error'] !== UPLOAD_ERR_OK) {
    die("Nenhum arquivo enviado ou erro no upload.");
}

$arquivoTmp = $_FILES['extrato']['tmp_name'];
$nomeArquivo = basename($_FILES['extrato']['name']);
$extensao = strtolower(pathinfo($nomeArquivo, PATHINFO_EXTENSION));
$arquivoSize = (int) ($_FILES['extrato']['size'] ?? 0);

$tiposPermitidos = ['pdf', 'csv', 'xlsx', 'xls'];
$maxBytes = 5 * 1024 * 1024; // 5MB

if ($arquivoSize <= 0 || $arquivoSize > $maxBytes) {
    die('Arquivo inválido. Envie um arquivo de até 5MB.');
}

if (!in_array($extensao, $tiposPermitidos, true)) {
    die("Formato inválido. Envie apenas arquivos PDF, CSV ou Excel (.xlsx, .xls).");
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = $finfo ? finfo_file($finfo, $arquivoTmp) : '';
if ($finfo) {
    finfo_close($finfo);
}

$mimesPermitidos = [
    'pdf' => ['application/pdf'],
    'csv' => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
    'xls' => ['application/vnd.ms-excel', 'application/octet-stream']
];

if (!in_array($mimeType, $mimesPermitidos[$extensao] ?? [], true)) {
    die('Tipo de arquivo não permitido para a extensão enviada.');
}

$prompt = "Você é um analista financeiro humanoide, empático e direto. Seu papel é:\n" .
          "1. Extrair TODAS as transações do extrato/planilha.\n" .
          "2. Classificar em categorias simples (ex: 'Alimentação', 'Transporte', 'Pix', 'Salário', 'Moradia', 'Extras').\n" .
          "3. Gerar dicas PERSONALIZADAS e humanizadas:\n" .
          "   - Se a renda for consistente e boa: elogie com tom natural ('Boa fonte de renda!', 'Você tem um fluxo sólido').\n" .
          "   - Se não houver renda fixa: avise sobre a volatilidade.\n" .
          "   - Identifique o MAIOR GASTO e dê UM CONSELHO AMIGÁVEL (nunca severo):\n" .
          "     Ex: 'Seu maior gasto foi em Alimentação (R\$ 2.500). Nada errado em investir em comida boa, mas considere meal prep 1x por semana para economizar sem sacrificar qualidade.'\n" .
          "   - Se o usuário poupou bem: reconheça.\n" .
          "   - Se ficou no vermelho: seja solidário, não culpabilize.\n" .
          "4. Retorne JSON com transações + dicas (3-5 dicas no máximo, cada uma com 50-100 palavras).\n" .
          "RETORNE ESTE JSON EXATO:\n" .
          "{\n" .
          "  \"transacoes\": [\n" .
          "    { \"descricao\": \"string\", \"categoria\": \"string\", \"valor\": 0.00, \"tipo\": \"entrada\" ou \"saida\" }\n" .
          "  ],\n" .
          "  \"dicas\": [ \"string com conselho amigável e acionável\" ]\n" .
          "}";

$partes = [["text" => $prompt]];

if ($extensao === 'pdf') {
    $conteudoArquivo = file_get_contents($arquivoTmp);
    $partes[] = [
        "inline_data" => [
            "mime_type" => "application/pdf",
            "data" => base64_encode($conteudoArquivo)
        ]
    ];
} elseif ($extensao === 'csv') {
    $csvTexto = file_get_contents($arquivoTmp);
    $partes[0]["text"] .= "\n\n--- DADOS DA PLANILHA ---\n" . $csvTexto;
} else {
    try {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($arquivoTmp);
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Csv');
        ob_start();
        $writer->save('php://output');
        $dadosPlanilha = ob_get_clean();
        $partes[0]["text"] .= "\n\n--- DADOS DA PLANILHA ---\n" . $dadosPlanilha;
    } catch (Exception $e) {
        die("Não foi possível ler a planilha enviada.");
    }
}

$dadosRequisicao = [
    "contents" => [["parts" => $partes]],
    "generationConfig" => [
        "response_mime_type" => "application/json",
        "temperature" => 0.0
    ]
];

$jsonPayload = json_encode($dadosRequisicao);
if ($jsonPayload === false) {
    http_response_code(500);
    die('Falha ao preparar a analise. Tente novamente.');
}

$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-lite:generateContent?key=" . $apiKey;

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
curl_setopt($ch, CURLOPT_TIMEOUT, 90);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);

$resposta = curl_exec($ch);
$httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
if (curl_errno($ch)) {
    curl_close($ch);
    die('Erro na comunicação com o serviço de análise. Tente novamente.');
}
curl_close($ch);

if ($httpStatus >= 500) {
    http_response_code(502);
    die('Serviço de analise indisponivel no momento. Tente novamente.');
}

$respostaDecodificada = json_decode($resposta, true);
if (isset($respostaDecodificada['candidates'][0]['content']['parts'][0]['text'])) {
    $textoIA = $respostaDecodificada['candidates'][0]['content']['parts'][0]['text'];

    $textoLimpo = preg_replace('/```json\s*/i', '', $textoIA);
    $textoLimpo = preg_replace('/```\s*/', '', $textoLimpo);
    $textoLimpo = trim($textoLimpo);

    $inicio = strpos($textoLimpo, '{');
    $fim = strrpos($textoLimpo, '}');
    if ($inicio !== false && $fim !== false) {
        $textoLimpo = substr($textoLimpo, $inicio, $fim - $inicio + 1);
    }

    $dados = json_decode($textoLimpo, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($dados) || !isset($dados['transacoes']) || !is_array($dados['transacoes'])) {
        die("Erro ao interpretar o JSON retornado pela IA.");
    }

    if (!isset($dados['dicas']) || !is_array($dados['dicas'])) {
        $dados['dicas'] = [];
    }

    // Incrementa o uso gratuito para visitantes.
    if (!$isLogged) {
        salvar_uso_gratuito_assinado($usoGratuito + 1);
    }

    $totalEntradas = 0.0;
    $totalSaidas = 0.0;

    foreach ($dados['transacoes'] as $t) {
        if (!is_array($t)) {
            continue;
        }
        $tipo = (string) ($t['tipo'] ?? '');
        $valor = (float) ($t['valor'] ?? 0);
        if ($valor < 0) {
            $valor = 0;
        }

        if ($tipo === 'entrada') {
            $totalEntradas += $valor;
        } elseif ($tipo === 'saida') {
            $totalSaidas += $valor;
        }
    }

    $saldoFinal = $totalEntradas - $totalSaidas;
    $mesReferencia = date('m/Y');
    $jsonParaSalvar = json_encode($dados, JSON_UNESCAPED_UNICODE);

    if ($isLogged) {
        try {
            $stmt = $pdo->prepare("INSERT INTO historico_analises (usuario_id, mes_referencia, entradas, saidas, saldo, json_completo) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $_SESSION['usuario_id'],
                $mesReferencia,
                $totalEntradas,
                $totalSaidas,
                $saldoFinal,
                $jsonParaSalvar
            ]);

            $id_inserido = $pdo->lastInsertId();
            header("Location: ver_relatorio.php?id=" . $id_inserido);
            exit;
        } catch (PDOException $e) {
            die("Erro interno ao salvar no banco. Tente novamente.");
        }
    }

    $_SESSION['relatorio_visitante'] = [
        'mes_referencia' => $mesReferencia,
        'entradas' => $totalEntradas,
        'saidas' => $totalSaidas,
        'saldo' => $saldoFinal,
        'json_completo' => $jsonParaSalvar,
        'criado_em' => date('Y-m-d H:i:s')
    ];

    header("Location: ver_relatorio.php?visitante=1");
    exit;
}

if (isset($respostaDecodificada['error']) && (int) ($respostaDecodificada['error']['code'] ?? 0) === 429) {
    http_response_code(429);
    die("Servidor ocupado. Volte e tente em 1 minuto.");
}

die("Erro desconhecido com a API do Google.");
?>