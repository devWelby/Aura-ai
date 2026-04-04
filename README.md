# Analista Financeiro

Projeto PHP para analise de extratos com IA, historico e assinatura Stripe.

## Estrutura de pastas

- `assets/`: CSS e recursos visuais
- `config/`: bootstrap de ambiente, sessao e banco (`init.php`)
- `includes/`: partes compartilhadas de layout (`header.php`, `footer.php`)
- `modules/app/`: paginas principais do dashboard
- `modules/auth/`: login, cadastro e logout
- `modules/relatorios/`: upload e visualizacao de relatorios
- `modules/pagamentos/`: planos, checkout, sucesso e webhook
- `public/`: front controller novo (`public/index.php`) e roteamento de transicao
- `scripts/dev/`: scripts de manutencao e verificacao local
- `vendor/`: dependencias do Composer
- `*.php` na raiz: wrappers de compatibilidade para endpoints legados

## Scripts de verificacao

- `composer lint`: valida sintaxe PHP de todos os arquivos (exceto `vendor/`)
- `composer check`: executa o pipeline basico de checagens

## Convencoes do projeto

- Sempre usar `require_once 'config/init.php';` como bootstrap.
- Evitar criar conexoes diretas ao banco fora de `config/init.php`.
- Formularios `POST` devem usar token CSRF (`csrf_token()` + `validar_csrf_post()`).
- Preferir mensagens de erro genericas para nao expor detalhes internos.

## Compatibilidade de rotas

- As URLs antigas foram preservadas.
- Cada arquivo da raiz (ex: `login.php`, `checkout.php`) agora chama o roteador central em `config/router.php`.
- O novo front controller fica em `public/index.php` e aceita rota por query string.

Exemplos:
- `public/index.php?route=dashboard`
- `public/index.php?route=auth/login`
- `public/index.php?route=relatorios/historico`
- `public/index.php?route=pagamentos/planos`
