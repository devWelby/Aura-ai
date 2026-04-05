# Aura-Ai

Aplicacao Node.js para analise financeira com upload de extrato, autenticacao, relatorios e assinatura.

## Setup rapido

1. Instale dependencias.

```bash
npm install
```

2. Crie o .env a partir de .env.example e preencha as chaves.

3. Rode em desenvolvimento.

```bash
npm run dev
```

## Operacao diaria

- Rodar app local: npm run dev
- Rodar app modo normal: npm start
- Check de sintaxe: npm run check
- Testes HTTP: npm test
- Verificacao padrao (check + testes): npm run verify
- Verificar integracao Firebase: npm run firebase:check
- Verificacao completa com Firebase: npm run verify:firebase
- Suite local completa: powershell -ExecutionPolicy Bypass -File scripts/dev/run-checks.ps1
- Suite completa com smoke real: set RUN_FULL=1 ; powershell -ExecutionPolicy Bypass -File scripts/dev/run-checks.ps1

## Deploy

### PM2

```bash
npm run pm2:start
npm run pm2:restart
npm run pm2:logs
```

Configuracao em ecosystem.config.js.

### Container/entrypoint

```bash
npm run start:prod
```

## Healthcheck

- Endpoint: GET /healthz
- Verificacao local: npm run healthcheck

## Variaveis obrigatorias

Sem esses valores a aplicacao nao opera corretamente em producao:

- APP_ENV
- PORT
- APP_URL
- DB_HOST
- DB_PORT
- DB_NAME
- DB_USER
- DB_PASS
- SESSION_SECRET
- COOKIE_SIGNING_SECRET
- GEMINI_API_KEY
- STRIPE_SECRET_KEY
- STRIPE_PRICE_ID
- STRIPE_WEBHOOK_SECRET

## Firebase (preparado)

O projeto foi preparado para integrar com Firebase Admin sem quebrar o fluxo atual.

Estado atual:

- Rotas continuam operando com MySQL
- Firebase ja pode ser validado e monitorado pelo healthcheck

Configuracao minima:

- FIREBASE_PROJECT_ID
- FIREBASE_SERVICE_ACCOUNT_PATH (arquivo JSON) ou FIREBASE_SERVICE_ACCOUNT_JSON (inline)

Validacao:

```bash
npm run firebase:check
```

Para login social/e-mail, tambem preencher:

- GOOGLE_CLIENT_ID
- GOOGLE_CLIENT_SECRET
- GOOGLE_REDIRECT_URI
- RESEND_API_KEY

## Notas operacionais

- Endpoints publicos e internos permanecem em rotas com sufixo .php por compatibilidade.
- Upload de planilha aceita apenas CSV e XLSX.
