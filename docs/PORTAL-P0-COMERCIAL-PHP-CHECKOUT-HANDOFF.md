# P0 — Comercial Astro → Portal Laravel → PostgreSQL 18 (homologação técnica CI / NO-GO)

## Resultado esperado

Esta rodada aproxima o caminho de contratação: um visitante de laboratório clica em um dos **quatro cartões comerciais reais** (Onepage, Landing Page, Google Ads ou Meta Ads) na página Astro compilada; o clique chega à página **Laravel HTTP** (não à imitação JavaScript) em um segundo servidor `127.0.0.1` com indicação da oferta; a pessoa confirma o pedido fictício no Portal; o pedido é gravado em **PostgreSQL 18 real e descartável**, com usuário SQL restrito, `FORCE ROW LEVEL SECURITY`, CSRF, idempotência e gravação/recuperação do briefing.

São dois servidores diferentes, em loopback, dentro da runner GitHub. Não existe login cliente real, token de usuário legítimo, autorização de negócio, pagamento PSP, publicação Site ou serviço de CRM conectados. O operador não pode usar esta prova para liberar checkout.

## Fontes e integrações

- Imagine Portal: PR sobre `review/portal-purchase-http-669-2026-11-13` (checkout privado P0 #672). O provider `PurchaseRehearsalServiceProvider` **continua não registrado** no bootstrap; o CI ativa explicitamente o harness PHP sob `APP_ENV=staging`, `GITHUB_ACTIONS=true` e `PORTAL_PURCHASE_HTTP_CI=1`.
- Imagine Comercial: SHA imutável `b0aefbd01f55cbcdb1653a798f05a83b866e3d33` da PR #372. `npm ci`, `npm run build`, `verify-dist.mjs`; somente uma **cópia temporária** da home recebe os quatro links `http://127.0.0.1:18776/_rehearsal/purchase?offer=<codigo>`; o diretório de build original e o hero são preservados.
- A indicação `offer` é validada no servidor **apenas para exibição**, lista fechada de três produtos. Não concede escopo, login, preço, pagamento nem cria pedido; nenhum parâmetro de tenant cliente é repassado ao banco. O formulário HTTP real exige POST com token CSRF.
- Reutiliza `tools/test_persisted_purchase.php` para instalar o banco fictício, papéis SQL restritos e RLS, sem migrations reais da VPS. O CI cria uma sessão Laravel nova ao navegar para a porta Portal e comprova persistência após reload e nova aba/contexto.

## Testes negativos obrigatórios

1. Quatro CTAs reais e suas ofertas previstas, incluindo dois canais de Performance, com exatamente um destaque no Portal após o clique.
2. `offer` inesperado, inválido, vazio ou em array: resposta `422`; sem permissões, ausência de escopo: `403`.
3. Formulário POST sem CSRF: `419`. Produto, identidade ou tenant não podem ser introduzidos como autorização pelo GET ou POST do navegador.
4. Pagamentos **fictícios** `declined` e `pending` bloqueiam o briefing; `approved` permite briefing somente após passo explícito.
5. Briefing persistido no PostgreSQL com HTML escapado, recuperado após recarga e navegador sem sessão anterior. Não existe transação financeira.
6. Todas as requisições do Playwright são limitadas a `127.0.0.1:18776` e `127.0.0.1:18777`, sem acesso à VPS, DNS, serviços externos, Cloudflare ou provedores de pagamento.

## Critérios NO-GO

Ainda faltam o principal de sessão real com MFA, vínculo de empresa autorizado, witness de revogação anti-rollback #64, mapeamento de roles SQL para tenant verdadeiro, contrato de preço/aceite, gateway de pagamento, API Sites, integração do CRM, observabilidade/backup/rollback operacionais, homologação autenticada na VPS e revisão AppSec/DBA. Nem o Portal #672 nem o Comercial #372 podem ser promovidos automaticamente por esses resultados.

**Sem nova despesa, merge, deploy ou exposição pública.** A PR é laboratório de integração testável e revisável, não checkout de produção.
