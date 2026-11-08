# PORTAL-PVI-G02-A — composição Blade demonstrativa

## Escopo e preflight

Atende à [Issue #70](https://github.com/rafhael100/imagine-portal/issues/70), com base no [PR #68](https://github.com/rafhael100/imagine-portal/pull/68), SHA `aab05561611f876e5f5a55c9216d2a61e62da273`. O aceite da Direção está [neste comentário](https://github.com/rafhael100/imagine-portal/pull/68#issuecomment-6727208644). Autoriza somente interface e testes fictícios. Este trabalho permanece **DRAFT / NO MERGE / NO DEPLOY / SEM CUSTOS**.

Branch filha: `dev/portal-pvi-g02-a-blade-components-2026-11-08`. Base do novo PR: `dev/portal-preview-ui-r01-r02-2026-11-08`, sem atualizar as branches anteriores. GitHub autenticado; HEAD do PR #68 reconferida antes da criação da filha e da publicação. Foram lidos `apps/portal/AGENTS.md`, README da raiz e do Laravel. Não existe `STATUS-ATUAL.md` na árvore versionada consultada; não foi presumido um status substituto.

Referências somente de leitura: `main` em `50b683a499e674641df5749051f3d05f7ac3f627`; DEV em `47514f4eac64a3d7ab0a0924cc890fb5a124d8b8`; PR #62 em `79797e9b0d850e0ed0f869d6314b01d2d4dedd16`. O PR #68 difere do #62 em sete arquivos (dois workflows, documento, shell, CSS e testes Blade/Chromium). As branches documentais/experimentais paralelas, inclusive #59/#61/#62/#68/#69/#71/#72 e os trabalhos de Segurança/PostgreSQL, não foram integradas nem modificadas. O diff desta filha começa exatamente no SHA aprovado, não na DEV atual.

## Inventário e adaptação

A base contém **30 views `x-portal.*`** e **seis views `x-portal.preview.*`**. Os componentes anteriores cobrem layout, marca, cabeçalho, sidebar, panel, indicator, service-card, badge, message, table/list, loading/empty-state/unavailable, jornada, acompanhamento/solicitações, histórico, ajuda e avisos.

| Responsabilidade | `x-portal.*` existente | Prévia aprovada/reutilizada |
| --- | --- | --- |
| Documento e conteúdo | `layout`, `header`, `brand`, slot padrão, estilos `pi-*` | `preview.shell`, slot padrão, CSP restritiva, estilos `pv-*` |
| Navegação | `sidebar`, sete valores de `ClientSection`, spans ilustrativos | `preview.nav-item`, seis âncoras internas reais, `PreviewArea` |
| Agrupamento | `panel` com título e slot | `preview.section`, id/heading/foco e slot |
| Indicadores | `indicator`, número em parágrafo | `preview.metric`, número em div, classes e defaults distintos |
| Serviços | `service-card`, badge/tone e ressalva adicional | `preview.service-card`, status textual e copy aprovada |
| Estilos | `portal.styles`, namespace `pi-*` | `preview.styles`, namespace `pv-*`, **inalterado** |

Não há equivalência automática entre os catálogos, os slots, o markup ou as classes. `ClientSection` descreve sete telas ilustrativas anteriores; `PreviewArea` descreve exatamente os seis destinos internos desta prévia. Nenhum deles concede permissões. Forçar o uso do layout anterior mudaria navegação e aparência aprovadas. Por isso os seis nomes `preview.*` são preservados: não foi criada uma nova biblioteca ou um novo conjunto de estilos.

As duplicações da allowlist no menu/seção e dos seis rótulos no shell passam para o enum `PreviewArea`. Dois cards de campanhas antes escritos manualmente passam a usar `preview.service-card`. Cinco views passam de componentes anônimos a classes Blade do namespace `App\View\Components\Portal\Preview`, mantendo seus nomes e markup; `preview.styles` continua anônimo. O shell permite título, heading e identificação fictícia de organização, com os mesmos defaults visuais.

## Contratos de props e slots

Todas as props textuais são strings de apresentação, não HTML, identidade, autorização ou estado operacional. São rejeitadas strings vazias/apenas espaços e valores acima do limite em **bytes UTF-8**; o valor válido é preservado sem normalização. Props públicas são `readonly`. Blade usa escape `{{ }}` em todos os campos dinâmicos. Não há encaminhamento de `$attributes`: handlers, destinos e atributos arbitrários recebidos não são impressos.

| Componente | Props e limites em bytes | Defaults | Slot |
| --- | --- | --- | --- |
| shell | `title`, `heading`, `organizationLabel`: 240 cada | Prévia navegável Imagine; Espaço do cliente · prévia; Empresa Aurora · fictícia | padrão: composição Blade interna |
| nav-item | `anchor: PreviewArea|string`; `label`: 160 | sem defaults de entrada | nenhum |
| section | `anchor: PreviewArea|string`; `title`: 240; `summary`: 1600; `tag`: 160 | tag Demonstração | padrão: conteúdo Blade interno |
| metric | `label`: 160; `value`: 80; `detail`: 800 | detail Valor exclusivamente fictício | nenhum |
| service-card | `name`: 240; `description`: 1600; `status`: 200 | status Somente exemplo visual | nenhum |
| styles | sem props | CSS aprovado | nenhum |

`organizationLabel` é passado como `organization-label` na tag Blade. `anchor` aceita enum ou string do catálogo; um valor desconhecido recai em `inicio`, preservando o comportamento anterior e impedindo hrefs externos/injeção em ids. Esse fallback não valida a composição inteira: o autor do documento deve declarar cada área exatamente uma vez, na ordem aprovada. O teste do showcase garante ids únicos e os seis destinos correspondentes. Não se deve usar valores desconhecidos repetidos para gerar seções.

O slot contém **templates Blade confiáveis**, não HTML recebido de usuário, CMS, banco ou rede. Dados variáveis dentro dele precisam de `{{ $texto }}`; `{!! !!}`, `HtmlString` e código arbitrário em slots não são entradas suportadas. Validação de comprimento não sanitiza HTML; é o escape Blade que protege a saída textual. A aplicação futura deverá adaptar DTOs próprios de apresentação, mantendo essa fronteira.

Exemplo isolado, sem rota:

```blade
<x-portal.preview.section :anchor="\App\Presentation\PreviewArea::Services"
    title="Serviços fictícios" summary="Somente demonstração">
    <x-portal.preview.service-card name="Site ilustrativo"
        description="Nenhum contrato ativo confirmado." />
</x-portal.preview.section>
```

## Verificações e reprodução

Dependências existentes do `composer.lock`, sem alterações em manifestos/lockfiles. PHP local 8.4.24 e PHPUnit 12.5.43: **160 testes / 2.627 assertions**; recorte da prévia: **10 testes / 192 assertions**, incluindo os seis testes anteriores intactos e quatro novos testes de composição. Node local 24.22.1: **65 testes**, com fornecedores simulados da suíte existente; offline: **cinco testes**. Nenhuma credencial de fornecedor ou custo foi utilizado.

O wrapper PHP local requer ini e bibliotecas explícitos; `artisan test` perdeu as extensões no subprocesso e não executou a suíte. O resultado local acima é da execução direta de `vendor/bin/phpunit`, que carregou corretamente dom/mbstring/tokenizer/xmlwriter. A matriz CI utiliza o runtime PHP normal. Não se confunde essa tentativa com um teste aprovado.

```sh
cd apps/portal
php vendor/bin/phpunit --colors=never
php vendor/bin/phpunit --filter PortalPreview
php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo view("portal.preview.showcase")->render();' > /tmp/pvi-g02.html
node --test prototypes/navegacao-imagine/preview.test.mjs
node tests/Visual/PreviewBrowserSmoke.mjs /tmp/pvi-g02.html /tmp/pvi-g02-capturas
# Na raiz, separadamente:
npm test
```

Novos testes cobrem ordem centralizada, renderização de cada destino com enum, composição de slots, escape de dez campos, descarte de atributos arbitrários e rejeição de props inválidas. O guard de banco usa mock PHPUnit `DatabaseManager::connection` com expectativa `never`; HTTP Laravel usa fake + `assertNothingSent`; a sessão permanece não iniciada e o snapshot de rotas é idêntico. Os testes anteriores continuam verificando CSP, ausência de scripts/forms/assets externos, limites comerciais e apenas as rotas preexistentes `up` e `storage/{path}`. Não é prova de segurança de uma aplicação operacional ou de qualquer biblioteca de rede arbitrária.

CI deverá ser conferida na HEAD exata registrada no novo PR: workflow Laravel (PHP 8.3/8.4) e testes Node (22/24). Os workflows de preview offline/Chromium existentes filtram bases que **não incluem a branch do PR #68**; portanto não são disparados por este PR empilhado. Eles não foram alterados fora de `apps/portal/`. Offline e Chromium foram executados localmente; não são declarados como jobs aprovados neste PR. Links, SHA final e resultados efetivos da CI constarão do corpo do PR para evitar autorreferência de SHA no arquivo versionado.

## Evidência visual comparativa

Fonte aprovada: [artifact 13631190532 do PR #68](https://github.com/rafhael100/imagine-portal/actions/runs/42514820974/artifacts/13631190532), com `showcase.html`, JSON e 23 PNGs. O artifact é temporário; os pares selecionados e as métricas desta revisão são persistidos neste diretório de documentação. As capturas novas são reais, obtidas em Chromium 156.0.8078.79, emulação mobile nos dois tamanhos menores. O navegador acessa somente fixture HTML em servidor efêmero loopback; Laravel HTTP não é iniciado e nenhuma rota é criada.

Foram produzidos **23 PNGs** por execução: dobra inicial e seis destinos em cada um dos três tamanhos, mais dois cenários aproximados de reflow. Base aprovada e composição nova, renderizadas no **mesmo Chromium local**, tiveram **zero pixels RGB diferentes nas 23 capturas** (sem tolerância). O HTML canônico também é idêntico: 425 tokens de elementos/atributos/textos, desconsiderando somente whitespace de indentação. O stylesheet não mudou; não houve divergência visual intencional.

| Viewport | Overflow | Teclado | Comparação com PNG original do CI #68 |
| --- | --- | --- | --- |
| 1440×900 | ausente | seis destinos, foco e título visíveis | 7,129% dos pixels diferem |
| 390×844 | ausente | seis destinos, foco/scroll horizontal | 13,613% dos pixels diferem |
| 320×740 | ausente | seis destinos, foco/scroll horizontal | 28,370% dos pixels diferem |

A comparação entre **ambientes distintos** não é pixel-idêntica: fontes de sistema, métricas tipográficas e rasterização alteram quebras de linha/alturas. Em 320 px, por exemplo, o aviso mede 87,5625 px no artifact aprovado e 70,171875 px localmente. A igualdade de 23/23 capturas ao repetir o HTML aprovado no ambiente local isola essa diferença; não se afirma que o PNG original e a captura local sejam iguais. Os pares abaixo mantêm os pixels originais sem redimensionamento, com rótulos adicionados fora das capturas: **esquerda PR #68/CI; direita composição/local**.

- [Desktop: comparação 1440×900](evidence/PVI-G02-A/comparativo-desktop-1440.png) · [captura nova](evidence/PVI-G02-A/imagine-portal-desktop-1440.png).
- [Celular: comparação 390×844](evidence/PVI-G02-A/comparativo-celular-390.png) · [captura nova](evidence/PVI-G02-A/imagine-portal-celular-390.png).
- [Celular estreito: comparação 320×740](evidence/PVI-G02-A/comparativo-celular-estreito-320.png) · [captura nova](evidence/PVI-G02-A/imagine-portal-celular-estreito-320.png).
- [Métricas e hashes das 23 comparações](evidence/PVI-G02-A/comparison-results.json) e [resultado Chromium](evidence/PVI-G02-A/browser-results.json).

O cálculo compara RGB por pixel sem limiar; o JSON identifica dimensões, contagens e SHA-256 de cada captura nova. Os seis PNGs selecionados (três pares e três capturas novas) são versionados como documentação, para manter a evidência acessível após a expiração do artifact original. Os outros 20 PNGs novos foram conferidos na execução local; seus hashes/métricas estão no JSON, mas os arquivos completos não são persistidos aqui. Nenhuma captura foi inventada ou produzida por geração de imagem.

A inspeção dos seis destinos nos três tamanhos preserva organização dos cards, copy comercial, aviso e navegação. Menu horizontal intencionalmente recortado no celular mantém a dica “6 áreas · deslize →”; cada item fica integralmente visível ao receber foco. Reflow 200%/400% é aproximação por viewport, não zoom nativo nem certificação WCAG.

## Próximas etapas e gates preservados

PVI-G03/G04 exigem autorização **separada** e revisão independente: definir primeiro contratos de DTOs de apresentação e mapeamentos explícitos entre as seis áreas da prévia e as telas anteriores; resolver diferenças de slots, títulos, badges, namespaces CSS, seleção de menu e estados vazios/carregamento antes de qualquer consolidação. A presença do item no menu ou do status fictício nunca poderá substituir contrato, elegibilidade, permissão ou política de produto.

Somente após decisões técnicas independentes poderão ser discutidas origem confiável de identidade, sessão/MFA/revogação, tenant/ownership, isolamento PG/RLS e autorização no servidor. Nenhum componente deve ler diretamente sessão, usuário, banco, HTTP ou tokens. CRM mantém login/sessão independentes, Sites e demais produtos conservam contratos próprios, Performance não publica anúncios, Universidade não confirma elegibilidade e Financeiro não gera cobrança. Não há integração funcional autorizada por este PR.

Permanecem abertos **P0/SEC-P01, autoridade de identidade e sessão, G-ID/G-BB/D02-SEC/D03/RLS, AppSec/DBA, UI-R03 e CRM #27**. Não foram alterados rotas, controllers, middleware, autenticação, permissões, SQL/migrations, APIs, pagamentos, integrações, main, DEV, branches predecessoras, VPS, DNS/Cloudflare, produção ou visibilidade. Não houve merge, deploy, plano pago ou nova dependência de projeto.

Para revisão da Direção: conferir fidelidade das capturas, contratos de props/slots e limites do recorte; solicitar ajustes ou registrar aceite somente desta composição demonstrativa. Revisão humana com leitor de tela, dispositivos físicos/iOS/Safari, zoom nativo e levantamento completo de contraste/estados permanecem pendentes. CI sintética não certifica WCAG nem segurança operacional.
