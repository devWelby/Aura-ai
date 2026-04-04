<?php
// processar_upload.php
require_once __DIR__ . '/../../config/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

validar_csrf_post();

// 🚨 TRAVA DO MODELO FREEMIUM
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
    header("Location: planos.php?msg=assinatura_necessaria");
    exit;
}

// Rate limit simples em sessao para conter abuso de API
if (!isset($_SESSION['upload_rate']) || !is_array($_SESSION['upload_rate'])) {
    $_SESSION['upload_rate'] = ['count' => 0, 'reset_at' => time() + 3600];
}
if (time() > (int) $_SESSION['upload_rate']['reset_at']) {
    $_SESSION['upload_rate'] = ['count' => 0, 'reset_at' => time() + 3600];
}
$_SESSION['upload_rate']['count']++;
if ((int) $_SESSION['upload_rate']['count'] > 10) {
    http_response_code(429);
    die('Muitas analises em pouco tempo. Tente novamente em alguns minutos.');
}

$apiKey = $_ENV['GEMINI_API_KEY']; 
if (!$apiKey) {
    http_response_code(500);
    die('Servico indisponivel no momento.');
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
    die('Arquivo invalido. Envie um arquivo de ate 5MB.');
}

if (!in_array($extensao, $tiposPermitidos, true)) {
    die("Formato invalido. Envie apenas arquivos PDF, CSV ou Excel (.xlsx, .xls).");
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
    die('Tipo de arquivo nao permitido para a extensao enviada.');
}

$prompt = "Extraia as transacoes deste extrato bancario ou planilha. Ignore linhas de saldo. " .
          "Classifique cada transacao em uma categoria curta (ex: 'Alimentacao', 'Transporte', 'Pix', 'Moradia'). " .
          "Use estritamente a seguinte estrutura JSON: " .
          "{" .
          "  \"transacoes\": [" .
          "    { \"descricao\": \"string\", \"categoria\": \"string\", \"valor\": 0.00, \"tipo\": \"entrada\" ou \"saida\" }" .
          "  ]," .
          "  \"dicas\": [ \"string\" ]" .
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
        die("Nao foi possivel ler a planilha enviada.");
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
$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-lite:generateContent?key=" . $apiKey;

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

$resposta = curl_exec($ch);
if (curl_errno($ch)) {
    curl_close($ch);
    die('Erro na comunicacao com o servico de analise. Tente novamente.');
}
curl_close($ch);

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

    // Incrementa limite gratuito para visitantes
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