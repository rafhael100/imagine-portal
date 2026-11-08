# Imagine Portal — UI-R01 / UI-R02 (Issue #66)

**08/11/2026 · UI-only · dados 100% fictícios · DRAFT / NO MERGE / NO DEPLOY.**

Autorização: [Issue #66](https://github.com/rafhael100/imagine-portal/issues/66), com diagnóstico e autorização gerencial na [Issue #65](https://github.com/rafhael100/imagine-portal/issues/65#issuecomment-6725692315). Aceite visual final expresso da Direção continua pendente; testes não constituem homologação comercial, de segurança ou acessibilidade.

## Base e alterações

Genealogia preservada: **#59 → #61 → #62 → branch filha UI-R01/R02**. A filha `dev/portal-preview-ui-r01-r02-2026-11-08` parte exclusivamente da HEAD reconferida do PR #62, `79797e9b0d850e0ed0f869d6314b01d2d4dedd16`, e o novo PR deve ter base `dev/portal-preview-ux-responsive-2026-11-08`. Nenhuma integração acumulada desses PRs está autorizada.

- **UI-R01:** orientação visual mobile “6 áreas · deslize →”, com descrição completa para tecnologia assistiva, sem abreviar os seis links. `nav`, `aria-describedby`, destinos focáveis e hierarquia h1/h2 preservados. O teste ampliado identificou foco parcialmente cortado em Solicitações no menu estreito; scroll snap CSS durante `:focus-visible` revela integralmente o item e o contorno fica dentro da área rolável. Nenhum script foi adicionado à interface.
- **UI-R02:** aviso condensado com indicação ostensiva de “DADOS 100% FICTÍCIOS”, sem login, formulários, contratos, anúncios, cobranças, integrações ou operações reais. Em até 620px, padding de 8×10px, texto 12px e entrelinha 1,45; informação permanece visível e disponível no DOM.
- **Seis áreas intactas:** Visão geral, Meus serviços, Performance AI, Solicitações, Universidade e Financeiro. Componentes de section, nav-item, metric e service-card e conteúdo das seis seções não foram alterados; reutiliza shell/styles existentes.
- **QA:** teste Blade verifica o aviso completo e a orientação acessível. Chromium testa swipe horizontal por eventos touch, todos os seis links com Tab/Enter, skip link, foco visível, títulos abaixo do menu sticky, ausência de overflow, redução de movimento e reflow aproximado de zoom. Salva 3 capturas iniciais, 18 capturas de seções, 2 de reflow e métricas JSON.
- **CI necessária:** somente os workflows browser/offline passam a aceitar também a base documental de código do PR #62. Não muda os jobs PHP 8.3/8.4 ou Node 22/24, runners, secrets ou dependências. Artifact de navegador inclui PNGs, HTML fictício e métricas, por 7 dias.

## Comparativo visual local medido

HTML Blade da base e da filha foram renderizados separadamente, sem rede/banco no container PHP, e inspecionados em Chromium. Antes: instrução longa, texto menor e aviso mais alto. Depois: orientação em uma linha e aviso condensado, com o mesmo significado de demonstração.

| Viewport | Aviso antes (base #62) | Aviso depois | Diferença |
|---|---:|---:|---:|
| 1440×900 | 48,14px | 48,14px | sem aumento de altura |
| 390×844 | 88,42px | 70,17px | −20,6% |
| 320×740 | 108,56px | 70,17px | −35,4% |

Medições dependem de fonte/renderizador; os valores de cada execução ficam em `browser-results.json` e no log. O desktop conserva a composição em colunas; em 390px os indicadores ficam em duas colunas, em 320px em uma. Inspeção visual local incluiu as três dobras iniciais e capturas das seis áreas em cada resolução, sem sobreposição observada nos títulos e cartões visíveis.

## Testes e reprodução

Validação local dos arquivos preparados: PHP 8.3 e 8.4, **156 testes / 2.550 assertions** em cada; Node 22 e 24, **65 testes** em cada; HTML offline, **5 testes**. Banco/serviços externos não são necessários. PHP não está instalado no host; foram usados containers locais, com rede desabilitada durante os testes. A instalação Composer usou o lock existente, sem scripts/plugins, sem alterar dependências.

O navegador recebe apenas o HTML já renderizado por servidor estático efêmero em `127.0.0.1`, restrito a um documento fictício. Isso evita bloqueio de `file://` por política do Chromium do ambiente; não inicia Laravel HTTP, rota de cliente ou publicação. DevTools e servidor de fixture são locais e encerrados ao final. O HTML continua sem scripts, forms, assets externos ou integração.

Com dependências do lock instaladas e ambiente descartável preparado, os comandos são:

```sh
cd apps/portal
php artisan test --no-ansi
php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo view("portal.preview.showcase")->render();' > /tmp/imagine-showcase.html
node tests/Visual/PreviewBrowserSmoke.mjs /tmp/imagine-showcase.html /tmp/imagine-capturas
node --test prototypes/navegacao-imagine/preview.test.mjs
```

A CI deve ser conferida na **HEAD exata do novo PR**, incluindo Laravel PHP 8.3/8.4, Node 22/24, offline e Chromium. SHA final, resultados dos workflows e links dos artifacts serão registrados no PR e na Issue #66 após a execução; a existência deste documento não antecipa sucesso da CI. Os screenshots finais do artifact são produzidos do checkout testado, sem arquivos binários adicionados ao código.

## Contraste, acessibilidade e limites

Verificação local de pares sólidos sRGB: orientação `#d2e6de` em `#163c36` ≈9,29:1; aviso `#685026` em `#fff9e9` ≈7,23:1; links `#effaf6` em `#163c36` ≈11,35:1; texto secundário `#59746e` em `#f5f8f6` ≈4,73:1; foco `#c78b20` em `#163c36` ≈4,12:1. Não é inventário completo de estados, backgrounds, preferências do sistema ou uma certificação WCAG AA/AAA.

O navegador usa emulação mobile, sem dispositivo físico/iOS/Safari ou leitor de tela humano. Reflow de 200%/400% é aproximação por viewport CSS 720×450 / 360×225 a partir de 1440×900; não comprova zoom nativo, ampliação de texto ou tecnologia assistiva. O teste respeita `prefers-reduced-motion: reduce`; screenshots mostram somente cada viewport, não todo o conteúdo abaixo da dobra. Legibilidade em equipamento real e aceite final de copy/visual ficam com a Direção; UI-R03 mais ampla permanece futura.

## Restrições e pendências para a Direção

Revisar as capturas finais nas três resoluções, a indicação das seis áreas e a copy condensada do aviso, e registrar **aceite visual final expresso ou ajustes desejados**. Não há merge ou deploy autorizado por esse aceite; permanecem decisões separadas para #59/#61/#62/novo PR.

Sem alterações em main, DEV original, VPS, DNS/Cloudflare, visibilidade, planos pagos ou contratação de infraestrutura. CI usa as matrizes/runners já existentes. Nenhuma rota, controller, API, autenticação/login/MFA/SSO, sessão, autoridade Portal, tenant/ownership, SQL/PG/RLS, integração CRM/Performance/Universidade, anúncio, cobrança, envio de formulário, credencial ou dado real foi ativado. P0/SEC-P01, AppSec/DBA e [CRM #27](https://github.com/rafhael100/imagine-crm/issues/27) permanecem abertos e independentes. PVI-G02 funcional e composição Laravel continuam fora do recorte implementado.
