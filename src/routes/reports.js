const express = require('express');
const axios = require('axios');
const multer = require('multer');
const rateLimit = require('express-rate-limit');
const ExcelJS = require('exceljs');
const pool = require('../config/db');
const { requireAuth } = require('../middleware/auth');
const { validateCsrfPost, readSignedFreeUsage, saveSignedFreeUsage } = require('../utils/security');
const { freeAnalysesRemainingUser } = require('./app');

const router = express.Router();

const upload = multer({
  limits: { fileSize: 5 * 1024 * 1024, files: 1 },
  storage: multer.memoryStorage(),
});

const uploadLimiter = rateLimit({
  windowMs: 60 * 60 * 1000,
  max: 30,
  standardHeaders: true,
  legacyHeaders: false,
  message: 'Muitas analises enviadas neste IP. Tente novamente mais tarde.',
});

function monthKeyFromDate(value) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return '';
  }
  const month = String(date.getMonth() + 1).padStart(2, '0');
  return `${date.getFullYear()}-${month}`;
}

function monthLabelFromKey(key) {
  if (!/^\d{4}-\d{2}$/.test(String(key || ''))) {
    return 'N/D';
  }
  const [year, month] = key.split('-');
  return `${month}/${year}`;
}

function computeMonthWindow(referenceDate = new Date()) {
  const currentStart = new Date(referenceDate.getFullYear(), referenceDate.getMonth(), 1, 0, 0, 0);
  const nextStart = new Date(referenceDate.getFullYear(), referenceDate.getMonth() + 1, 1, 0, 0, 0);
  const previousStart = new Date(referenceDate.getFullYear(), referenceDate.getMonth() - 1, 1, 0, 0, 0);

  return {
    currentStart,
    nextStart,
    previousStart,
    currentKey: monthKeyFromDate(currentStart),
    previousKey: monthKeyFromDate(previousStart),
  };
}

async function getMonthlyComparison(usuarioId) {
  const window = computeMonthWindow(new Date());

  const [rows] = await pool.execute(
    `SELECT
      DATE_FORMAT(criado_em, '%Y-%m') AS mes,
      COALESCE(SUM(entradas), 0) AS entradas,
      COALESCE(SUM(saidas), 0) AS saidas,
      COALESCE(SUM(saldo), 0) AS saldo
     FROM historico_analises
     WHERE usuario_id = ?
       AND criado_em >= ?
       AND criado_em < ?
     GROUP BY DATE_FORMAT(criado_em, '%Y-%m')`,
    [usuarioId, window.previousStart, window.nextStart],
  );

  const byMonth = new Map(rows.map((item) => [
    String(item.mes),
    {
      entradas: Number(item.entradas || 0),
      saidas: Number(item.saidas || 0),
      saldo: Number(item.saldo || 0),
    },
  ]));

  const atual = byMonth.get(window.currentKey) || { entradas: 0, saidas: 0, saldo: 0 };
  const anterior = byMonth.get(window.previousKey) || { entradas: 0, saidas: 0, saldo: 0 };
  const deltaSaldo = atual.saldo - anterior.saldo;
  const variacaoSaldoPercentual = anterior.saldo !== 0 ? (deltaSaldo / Math.abs(anterior.saldo)) * 100 : null;

  return {
    periodoAtual: monthLabelFromKey(window.currentKey),
    periodoAnterior: monthLabelFromKey(window.previousKey),
    atual,
    anterior,
    deltaSaldo,
    variacaoSaldoPercentual,
  };
}

async function buildCategoryTrend(usuarioId) {
  const window = computeMonthWindow(new Date());
  const [rows] = await pool.execute(
    `SELECT criado_em, json_completo
     FROM historico_analises
     WHERE usuario_id = ?
       AND criado_em >= ?
       AND criado_em < ?
     ORDER BY criado_em DESC`,
    [usuarioId, window.previousStart, window.nextStart],
  );

  const despesasAtual = {};
  const despesasAnterior = {};

  for (const row of rows) {
    const key = monthKeyFromDate(row.criado_em);
    const target = key === window.currentKey ? despesasAtual : key === window.previousKey ? despesasAnterior : null;
    if (!target) {
      continue;
    }

    let parsed;
    try {
      parsed = JSON.parse(String(row.json_completo || '{}'));
    } catch (err) {
      parsed = {};
    }

    const transacoes = Array.isArray(parsed.transacoes) ? parsed.transacoes : [];
    for (const item of transacoes) {
      if (!item || typeof item !== 'object') {
        continue;
      }
      if (String(item.tipo || '') !== 'saida') {
        continue;
      }

      const categoria = String(item.categoria || 'Outros').trim() || 'Outros';
      const valor = Math.max(0, Number(item.valor || 0));
      target[categoria] = Number(target[categoria] || 0) + valor;
    }
  }

  const categorias = new Set([
    ...Object.keys(despesasAtual),
    ...Object.keys(despesasAnterior),
  ]);

  const tendenciaCategorias = Array.from(categorias).map((categoria) => {
    const atual = Number(despesasAtual[categoria] || 0);
    const anterior = Number(despesasAnterior[categoria] || 0);
    const delta = atual - anterior;
    let tendencia = 'estavel';
    if (delta > 0.01) {
      tendencia = 'subiu';
    } else if (delta < -0.01) {
      tendencia = 'caiu';
    }

    const variacaoPercentual = anterior > 0 ? (delta / anterior) * 100 : null;

    return {
      categoria,
      atual,
      anterior,
      delta,
      tendencia,
      variacaoPercentual,
    };
  }).sort((a, b) => Math.abs(b.delta) - Math.abs(a.delta));

  return {
    periodoAtual: monthLabelFromKey(window.currentKey),
    periodoAnterior: monthLabelFromKey(window.previousKey),
    tendenciaCategorias,
  };
}

function parseGeminiJson(text) {
  let cleaned = String(text || '')
    .replace(/```json\s*/gi, '')
    .replace(/```\s*/g, '')
    .trim();

  const start = cleaned.indexOf('{');
  const end = cleaned.lastIndexOf('}');
  if (start >= 0 && end >= start) {
    cleaned = cleaned.slice(start, end + 1);
  }

  return JSON.parse(cleaned);
}

function buildPrompt() {
  return "Voce e um analista financeiro humanoide, empatico e direto. Seu papel e:\n"
    + "1. Extrair TODAS as transacoes do extrato/planilha.\n"
    + "2. Classificar em categorias simples (ex: Alimentacao, Transporte, Pix, Salario, Moradia, Extras).\n"
    + "3. Gerar dicas PERSONALIZADAS e humanizadas com tom amigavel.\n"
    + "4. Retorne JSON com transacoes + dicas (3-5 dicas no maximo, cada uma com 50-100 palavras).\n"
    + "RETORNE ESTE JSON EXATO:\n"
    + "{\n"
    + "  \"transacoes\": [\n"
    + "    { \"descricao\": \"string\", \"categoria\": \"string\", \"valor\": 0.00, \"tipo\": \"entrada\" ou \"saida\" }\n"
    + "  ],\n"
    + "  \"dicas\": [ \"string com conselho amigavel e acionavel\" ]\n"
    + "}";
}

function escapeCsvValue(value) {
  const str = value === null || value === undefined ? '' : String(value);
  if (str.includes(',') || str.includes('"') || str.includes('\n') || str.includes('\r')) {
    return `"${str.replace(/"/g, '""')}"`;
  }
  return str;
}

async function worksheetBufferToCsv(buffer) {
  const workbook = new ExcelJS.Workbook();
  await workbook.xlsx.load(buffer);

  const worksheet = workbook.worksheets[0];
  if (!worksheet) {
    throw new Error('Planilha sem abas.');
  }

  const lines = [];
  worksheet.eachRow({ includeEmpty: true }, (row) => {
    const maxCols = row.cellCount || 0;
    const values = [];
    for (let col = 1; col <= maxCols; col += 1) {
      const raw = row.getCell(col).text;
      values.push(escapeCsvValue(raw));
    }
    lines.push(values.join(','));
  });

  return lines.join('\n');
}

async function getPlan(req) {
  if (!req.session.usuario_id) {
    return 'visitante';
  }

  const [rows] = await pool.execute('SELECT plano FROM usuarios WHERE id = ?', [req.session.usuario_id]);
  const plano = String(rows[0]?.plano || 'gratis');
  req.session.usuario_plano = plano;
  return plano;
}

router.post(['/processar_upload.php', '/relatorios/processar-upload'], uploadLimiter, upload.single('extrato'), validateCsrfPost, async (req, res) => {
  if (!req.file || !req.file.buffer) {
    return res.status(400).send('Nenhum arquivo enviado ou erro no upload.');
  }

  const isLogged = Boolean(req.session.usuario_id);
  const limiteGratuito = 2;
  const usoGratuito = readSignedFreeUsage(req);
  const planoUsuario = await getPlan(req);

  if (!isLogged && usoGratuito >= limiteGratuito) {
    return res.redirect('/planos.php?msg=limite_excedido');
  }

  if (isLogged && planoUsuario !== 'pro') {
    const restantes = await freeAnalysesRemainingUser(Number(req.session.usuario_id), limiteGratuito);
    if (restantes <= 0) {
      return res.redirect('/planos.php?msg=limite_excedido');
    }
  }

  if (!req.session.upload_rate) {
    req.session.upload_rate = { count: 0, reset_at: Date.now() + 60 * 60 * 1000 };
  }

  if (Date.now() > Number(req.session.upload_rate.reset_at || 0)) {
    req.session.upload_rate = { count: 0, reset_at: Date.now() + 60 * 60 * 1000 };
  }

  req.session.upload_rate.count += 1;
  if (req.session.upload_rate.count > 10) {
    return res.status(429).send('Muitas analises em pouco tempo. Tente novamente em alguns minutos.');
  }

  const apiKey = String(process.env.GEMINI_API_KEY || '');
  if (!apiKey) {
    return res.status(500).send('Servico indisponivel no momento.');
  }

  const name = String(req.file.originalname || '');
  const ext = name.split('.').pop().toLowerCase();
  if (ext === 'xls') {
    return res.status(400).send('Formato XLS nao e mais suportado por seguranca. Converta para XLSX e tente novamente.');
  }

  if (!['pdf', 'csv', 'xlsx'].includes(ext)) {
    return res.status(400).send('Formato invalido. Envie apenas PDF, CSV ou XLSX.');
  }

  const mime = String(req.file.mimetype || '').toLowerCase();
  const allowedMimeByExt = {
    pdf: ['application/pdf'],
    csv: ['text/csv', 'application/csv', 'text/plain', 'application/vnd.ms-excel'],
    xlsx: ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
  };
  const allowedMimes = allowedMimeByExt[ext] || [];
  if (mime && !allowedMimes.includes(mime) && mime !== 'application/octet-stream') {
    return res.status(400).send('Tipo de arquivo invalido para a extensao enviada.');
  }

  const prompt = buildPrompt();
  const parts = [{ text: prompt }];

  if (ext === 'pdf') {
    parts.push({
      inline_data: {
        mime_type: 'application/pdf',
        data: req.file.buffer.toString('base64'),
      },
    });
  } else if (ext === 'csv') {
    parts[0].text += `\n\n--- DADOS DA PLANILHA ---\n${req.file.buffer.toString('utf-8')}`;
  } else {
    try {
      const csv = await worksheetBufferToCsv(req.file.buffer);
      parts[0].text += `\n\n--- DADOS DA PLANILHA ---\n${csv}`;
    } catch (err) {
      return res.status(400).send('Nao foi possivel ler a planilha enviada.');
    }
  }

  const payload = {
    contents: [{ parts }],
    generationConfig: {
      response_mime_type: 'application/json',
      temperature: 0.0,
    },
  };

  let response;
  try {
    response = await axios.post(
      `https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-lite:generateContent?key=${apiKey}`,
      payload,
      {
        timeout: 90000,
        headers: { 'Content-Type': 'application/json' },
      },
    );
  } catch (err) {
    if (err.response && Number(err.response.status) === 429) {
      return res.status(429).send('Servidor ocupado. Volte e tente em 1 minuto.');
    }
    return res.status(502).send('Erro na comunicacao com o servico de analise. Tente novamente.');
  }

  const text = response?.data?.candidates?.[0]?.content?.parts?.[0]?.text;
  if (!text) {
    return res.status(500).send('Erro desconhecido com a API do Google.');
  }

  let dados;
  try {
    dados = parseGeminiJson(text);
  } catch (err) {
    return res.status(500).send('Erro ao interpretar o JSON retornado pela IA.');
  }

  if (!Array.isArray(dados.transacoes)) {
    return res.status(500).send('Erro ao interpretar o JSON retornado pela IA.');
  }

  if (!Array.isArray(dados.dicas)) {
    dados.dicas = [];
  }

  let totalEntradas = 0;
  let totalSaidas = 0;
  for (const transacao of dados.transacoes) {
    if (!transacao || typeof transacao !== 'object') {
      continue;
    }

    const tipo = String(transacao.tipo || '');
    const valor = Math.max(0, Number(transacao.valor || 0));
    if (tipo === 'entrada') {
      totalEntradas += valor;
    } else if (tipo === 'saida') {
      totalSaidas += valor;
    }
  }

  if (!isLogged) {
    saveSignedFreeUsage(req, res, usoGratuito + 1);
  }

  const saldoFinal = totalEntradas - totalSaidas;
  const now = new Date();
  const mesReferencia = `${String(now.getMonth() + 1).padStart(2, '0')}/${now.getFullYear()}`;
  const jsonCompleto = JSON.stringify(dados);

  if (isLogged) {
    const [result] = await pool.execute(
      'INSERT INTO historico_analises (usuario_id, mes_referencia, entradas, saidas, saldo, json_completo) VALUES (?, ?, ?, ?, ?, ?)',
      [req.session.usuario_id, mesReferencia, totalEntradas, totalSaidas, saldoFinal, jsonCompleto],
    );

    return res.redirect(`/ver_relatorio.php?id=${result.insertId}`);
  }

  req.session.relatorio_visitante = {
    mes_referencia: mesReferencia,
    entradas: totalEntradas,
    saidas: totalSaidas,
    saldo: saldoFinal,
    json_completo: jsonCompleto,
    criado_em: new Date().toISOString().slice(0, 19).replace('T', ' '),
  };

  return res.redirect('/ver_relatorio.php?visitante=1');
});

router.get(['/ver_relatorio.php', '/relatorios/ver'], async (req, res) => {
  const isLogged = Boolean(req.session.usuario_id);
  const isVisitante = String(req.query.visitante || '') === '1';
  const idRelatorio = Number.parseInt(String(req.query.id || ''), 10);

  if (!isLogged && !isVisitante) {
    return res.redirect('/login.php');
  }

  let relatorio;
  if (isVisitante) {
    relatorio = req.session.relatorio_visitante;
    if (!relatorio) {
      return res.status(400).send('Seu relatorio de teste expirou. Por favor, envie o arquivo novamente.');
    }
  } else {
    if (!Number.isInteger(idRelatorio) || idRelatorio <= 0) {
      return res.status(400).send('ID do relatorio invalido.');
    }

    const [rows] = await pool.execute(
      'SELECT * FROM historico_analises WHERE id = ? AND usuario_id = ?',
      [idRelatorio, req.session.usuario_id],
    );

    relatorio = rows[0];
    if (!relatorio) {
      return res.status(404).send('Relatorio nao encontrado ou acesso negado.');
    }
  }

  const totalEntradas = Number(relatorio.entradas || 0);
  const totalSaidas = Number(relatorio.saidas || 0);
  const saldoFinal = Number(relatorio.saldo || 0);
  const mesReferencia = String(relatorio.mes_referencia || '');

  let dados;
  try {
    dados = JSON.parse(String(relatorio.json_completo || '{}'));
  } catch (err) {
    dados = { transacoes: [], dicas: [] };
  }

  if (!Array.isArray(dados.transacoes)) {
    dados.transacoes = [];
  }
  if (!Array.isArray(dados.dicas)) {
    dados.dicas = [];
  }

  const gastosPorCategoria = {};
  const entradasPorCategoria = {};
  let maiorTransacaoSaida = null;
  let maiorTransacaoEntrada = null;
  let qtdEntradas = 0;
  let qtdSaidas = 0;
  let somaSaidas = 0;
  let somaEntradas = 0;

  for (const t of dados.transacoes) {
    if (!t || typeof t !== 'object') {
      continue;
    }

    const tipo = String(t.tipo || '');
    const valor = Math.max(0, Number(t.valor || 0));
    const categoria = String(t.categoria || 'Outros').trim() || 'Outros';

    if (tipo === 'saida') {
      qtdSaidas += 1;
      somaSaidas += valor;
      gastosPorCategoria[categoria] = Number(gastosPorCategoria[categoria] || 0) + valor;

      if (!maiorTransacaoSaida || valor > Number(maiorTransacaoSaida.valor || 0)) {
        maiorTransacaoSaida = {
          categoria,
          valor,
          descricao: String(t.descricao || 'Sem descricao'),
        };
      }
    } else if (tipo === 'entrada') {
      qtdEntradas += 1;
      somaEntradas += valor;
      entradasPorCategoria[categoria] = Number(entradasPorCategoria[categoria] || 0) + valor;

      if (!maiorTransacaoEntrada || valor > Number(maiorTransacaoEntrada.valor || 0)) {
        maiorTransacaoEntrada = {
          categoria,
          valor,
          descricao: String(t.descricao || 'Sem descricao'),
        };
      }
    }
  }

  const gastosOrdenados = Object.entries(gastosPorCategoria).sort((a, b) => b[1] - a[1]);
  const categoriasSagas = gastosOrdenados.map((item) => item[0]);
  const valoresSagos = gastosOrdenados.map((item) => item[1]);

  const totalTransacoes = qtdEntradas + qtdSaidas;
  const ticketMedioSaida = qtdSaidas > 0 ? somaSaidas / qtdSaidas : 0;
  const ticketMedioEntrada = qtdEntradas > 0 ? somaEntradas / qtdEntradas : 0;
  const taxaPoupanca = totalEntradas > 0 ? (saldoFinal / totalEntradas) * 100 : 0;
  const comprometimentoRenda = totalEntradas > 0 ? (totalSaidas / totalEntradas) * 100 : 0;
  const runwayMeses = totalSaidas > 0 ? totalEntradas / totalSaidas : 0;

  const topCategoriaNome = categoriasSagas[0] || 'Sem dados';
  const topCategoriaValor = Number(valoresSagos[0] || 0);
  const dependenciaTopCategoria = totalSaidas > 0 ? (topCategoriaValor / totalSaidas) * 100 : 0;

  let scoreSaude = 100;
  if (saldoFinal < 0) {
    scoreSaude -= 35;
  }
  if (comprometimentoRenda > 90) {
    scoreSaude -= 25;
  } else if (comprometimentoRenda > 75) {
    scoreSaude -= 12;
  }
  if (dependenciaTopCategoria > 45) {
    scoreSaude -= 15;
  }
  if (qtdSaidas > qtdEntradas * 3 && qtdEntradas > 0) {
    scoreSaude -= 8;
  }
  scoreSaude = Math.max(0, Math.min(100, scoreSaude));

  const projecaoAnualEntradas = totalEntradas * 12;
  const projecaoAnualSaidas = totalSaidas * 12;
  const projecaoAnualSaldo = saldoFinal * 12;

  const topSaidasDetalhes = dados.transacoes
    .filter((t) => t && String(t.tipo || '') === 'saida')
    .map((t) => ({
      descricao: String(t.descricao || 'Sem descricao'),
      categoria: String(t.categoria || 'Outros'),
      valor: Math.max(0, Number(t.valor || 0)),
    }))
    .sort((a, b) => b.valor - a.valor)
    .slice(0, 8);

  const entradasOrdenadas = Object.entries(entradasPorCategoria).sort((a, b) => b[1] - a[1]);

  const topEntradasDetalhes = dados.transacoes
    .filter((t) => t && String(t.tipo || '') === 'entrada')
    .map((t) => ({
      descricao: String(t.descricao || 'Sem descricao'),
      categoria: String(t.categoria || 'Outros'),
      valor: Math.max(0, Number(t.valor || 0)),
    }))
    .sort((a, b) => b.valor - a.valor)
    .slice(0, 8);

  let faixaSaude = 'Excelente';
  if (scoreSaude < 80) {
    faixaSaude = 'Estavel';
  }
  if (scoreSaude < 60) {
    faixaSaude = 'Atencao';
  }
  if (scoreSaude < 40) {
    faixaSaude = 'Critico';
  }

  const economiaPotencial = topCategoriaValor * 0.1;
  const comparativoMensal = isVisitante
    ? null
    : await getMonthlyComparison(Number(req.session.usuario_id));

  const diagnosticoRapido = [];
  if (saldoFinal < 0) {
    diagnosticoRapido.push('Seu mes fechou no negativo. Corte despesas variaveis e renegocie contas fixas.');
  }
  if (dependenciaTopCategoria >= 40) {
    diagnosticoRapido.push(`Concentracao alta em ${topCategoriaNome} (${dependenciaTopCategoria.toFixed(1)}% das saidas).`);
  }
  if (taxaPoupanca >= 20) {
    diagnosticoRapido.push('Boa disciplina: taxa de poupanca acima de 20%.');
  } else if (taxaPoupanca > 0) {
    diagnosticoRapido.push('Voce poupou, mas pode evoluir para uma meta de 20% da renda.');
  }
  if (diagnosticoRapido.length === 0) {
    diagnosticoRapido.push('Fluxo financeiro estavel. Continue acompanhando para evitar picos de gasto.');
  }

  return res.render('reports/ver_relatorio', {
    pageTitle: `Relatorio - ${mesReferencia}`,
    isVisitante,
    relatorio,
    totalEntradas,
    totalSaidas,
    saldoFinal,
    mesReferencia,
    dados,
    gastosPorCategoria: gastosOrdenados,
    labelsChart: JSON.stringify(categoriasSagas),
    valoresChart: JSON.stringify(valoresSagos),
    totalTransacoes,
    ticketMedioSaida,
    ticketMedioEntrada,
    taxaPoupanca,
    comprometimentoRenda,
    runwayMeses,
    topCategoriaNome,
    dependenciaTopCategoria,
    scoreSaude,
    projecaoAnualEntradas,
    projecaoAnualSaidas,
    projecaoAnualSaldo,
    maiorTransacaoSaida,
    maiorTransacaoEntrada,
    topSaidasDetalhes,
    entradasOrdenadas,
    topEntradasDetalhes,
    faixaSaude,
    economiaPotencial,
    comparativoMensal,
    diagnosticoRapido,
  });
});

router.get(['/historico.php', '/relatorios/historico'], requireAuth, async (req, res) => {
  const [historico] = await pool.execute(
    'SELECT id, mes_referencia, entradas, saidas, saldo, criado_em FROM historico_analises WHERE usuario_id = ? ORDER BY criado_em DESC',
    [req.session.usuario_id],
  );

  const tendenciaCategoria = await buildCategoryTrend(Number(req.session.usuario_id));

  return res.render('reports/historico', {
    pageTitle: 'Meu Historico - Analista IA',
    historico,
    tendenciaCategoria,
  });
});

module.exports = router;
