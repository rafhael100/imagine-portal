# PORTAL-UI-04 — Serviços, solicitações e atualizações fictícias

## Base e escopo

Refs [Issue #36](https://github.com/rafhael100/imagine-portal/issues/36).
Base conferida em 06/11/2026: `03423d866322c8b01bb856210d2da758b4a33dbf`,
branch `dev/fast-track-portal-2026-11-05`.
Branch filha: `dev/portal-ui-04-servicos-solicitacoes-demo-2026-11-06`.
PR [#37](https://github.com/rafhael100/imagine-portal/pull/37), em rascunho.
PRs #19/#24/#25/#33 conferidos abertos e preservados; sem alteração de gates.

## Implementação efetiva

Sete views internas, sem rotas: overview, services, available, contracts, modules,
journey e a nova requests. Navegação e títulos vêm de ClientSection; Requests é
acrescentada ao fim para preservar a ordem das seis seções anteriores. Não há
links de navegação funcional. Um único item fica marcado como atual.

Três serviços fictícios: Onepage, Landing Page e Performance AI. A view services
passa a usar acompanhamento tipado no lugar dos dois cards genéricos antigos.
Overview deriva as contagens e a lista desses mesmos exemplos (três serviços e
três solicitações); nenhuma fonte operacional é consultada.

Requests apresenta três resumos, com etapas diferentes e históricos de dois,
um e zero registros. O histórico é uma lista narrativa por solicitação, sem
progress bar, datas de eventos ou duplicação da timeline principal de journey.
A view journey e suas etapas permanecem intactas.

Sem filtros nesta etapa: não há volume de exemplos que justifique um controle;
não foram introduzidos formulários, botões, endpoints, uploads ou links reais.

## Contratos exclusivamente visuais

- `ServiceFollowUp`: DTO readonly de referência fictícia, produto, JourneyState,
  resumo e próxima atualização ilustrativa. Sem prazo/SLA prometido.
- `RequestSummary`: DTO readonly de referência fictícia da solicitação e do serviço,
  título, resumo, JourneyState e lista de atualizações.
- `IllustrativeUpdate`: DTO readonly com rótulo, título e resumo de uma passagem
  narrativa. Não é evento de auditoria nem timestamp de atendimento.
- `DemoServiceRequests`: fixtures determinísticas puras, sem container, gateway,
  repositório de dados, ator, organização, sessão ou decisão de acesso.

Referências limitadas a `DEMO-SVC-...` e `DEMO-REQ-...`, com sufixo ASCII de
1 a 40 caracteres. Textos não vazios até 2000 bytes; histórico é uma lista tipada
de até 20 IllustrativeUpdate. Entradas inválidas lançam InvalidArgumentException
ou TypeError; não se convertem silenciosamente em autorização. Textos aceitos
continuam não confiáveis para HTML e são escapados por Blade.

Reutiliza o enum JourneyState e o componente service-status da UI-03. Vocabulário:
example, illustrated, preparing, waiting e next; fallback neutro já existente
para códigos desconhecidos na camada visual. Os DTOs aceitam apenas o enum.
Estados nunca equivalem a contrato, cobrança, consentimento, aprovação,
permissão, elegibilidade, provisão ou conclusão operacional.

O DemoRequestCard de Performance foi inspecionado: exige OrganizationId de domínio.
Não foi acoplado a esta apresentação, pois a Issue proíbe transportar organização
ou ator. Nenhum domínio Performance foi modificado ou tratado como fonte real.

Onepage e Landing Page são independentes. Performance AI tem motor separado e
nenhuma conexão Google/Meta. CRM Imagine mantém identidade/login/sessão próprios,
sem SSO. Universidade Imagine é benefício de clientes elegíveis, não assinatura
avulsa; o exemplo não concede acesso. Empresa Aurora e referências são fictícias.

## Componentes e uso

Cinco componentes novos, todos usados por services ou requests:

- `x-portal.service-follow-up :service="$service"`: artigo de acompanhamento, dt/dd
  para referência, resumo e próxima atualização.
- `x-portal.service-tracking :services="$services"`: agrupamento e estado vazio.
- `x-portal.request-summary :request="$request"`: artigo com resumo e histórico.
- `x-portal.request-list :requests="$requests"`: lista sem operações e estado vazio.
- `x-portal.update-history :updates="$updates"`: seção com lista ordenada e estado vazio.

Props de registro recebem os DTOs acima; coleções vêm de código de apresentação
confiável. Títulos, rótulos e resumos usam exclusivamente `{{ }}`.
Não passar HtmlString ou objetos de identidade como dados de apresentação.

Estilos usam a paleta e os breakpoints existentes. Registros dt/dd empilham em
tablet/celular (até 1050px), evitando uma coluna de texto estreita em cards.

## Inventário de arquivos

Caminhos relativos a `apps/portal/`. Novos:
- `app/Presentation/ServiceFollowUp.php`
- `app/Presentation/IllustrativeUpdate.php`
- `app/Presentation/RequestSummary.php`
- `app/Presentation/DemoServiceRequests.php`
- `resources/views/components/portal/service-follow-up.blade.php`
- `resources/views/components/portal/service-tracking.blade.php`
- `resources/views/components/portal/update-history.blade.php`
- `resources/views/components/portal/request-summary.blade.php`
- `resources/views/components/portal/request-list.blade.php`
- `resources/views/portal/demo/requests.blade.php`
- `tests/Feature/PortalRequestsPresentationTest.php`
- `tests/Unit/ServiceRequestsPresentationTest.php`
- `docs/PORTAL-UI-04.md`.

Modificados:
- `resources/views/components/portal/styles.blade.php`
- `resources/views/portal/demo/services.blade.php`
- `app/Presentation/ClientSection.php`
- `tests/Feature/PortalUiComponentsTest.php`
- `tests/Feature/PortalJourneyPresentationTest.php`
- `resources/views/portal/demo/overview.blade.php`

Nenhuma alteração em routes, bootstrap, config, composer/lock, workflows,
autenticação, domínio, main, VPS ou produção.

## Testes locais executados — PHP 8.4.24

O executor não tinha PHP. Para validar sem alterar o sistema ou o repositório:
pacotes oficiais Debian 13 (php8.4-cli/common/xml/mbstring e libargon2-1) foram
baixados com metadados APT assinados e extraídos em `/workspace/work/php84/`.
Não houve instalação global, dependência nova do produto ou despesa. Composer
PHAR obtido do site oficial, SHA-256 conferido; `composer install` usou apenas
o lock, com scripts/plugins desabilitados. Sem driver PostgreSQL no runtime.

Comandos em apps/portal, usando chave descartável exclusivamente por variável
de ambiente do processo de testes, sem escrever .env ou configurar autenticação:

```sh
php vendor/bin/phpunit --colors=never --no-progress --filter 'ServiceRequestsPresentationTest|PortalRequestsPresentationTest'
php vendor/bin/phpunit --colors=never --no-progress
```

Resultados reais:
- Novas provas: **10 testes, 119 asserções — OK**.
- Suíte completa: **125 testes, 1915 asserções — OK**.
- Sintaxe de Presentation e testes Feature/Unit: aprovada.
- composer.json e composer.lock permanecem intactos.
- Suíte completa repetida após o ajuste de responsividade: mesmo resultado.

O comando `php` acima representa o wrapper temporário
`/workspace/work/php84/php` no ambiente desta execução. As dependências do lock
ficaram somente no vendor ignorado do checkout de trabalho.

Cobertura: sete views e sete itens/um ativo na navegação; tipos readonly e
referências fictícias; rejeição de texto vazio/oversized e histórico não tipado;
fallback existente; XSS em títulos/resumos/rótulos; coleções vazias; limites dos
produtos; listas e títulos semânticos; ausência de operações/links externos.
Mock PHPUnit `expects(never())->method('connection')` preservado nos testes de
renderização. Registros de rotas antes/depois iguais e sessão não iniciada.
Nenhuma migration, conexão de banco ou teste real de autenticação executado.

## GitHub Actions — bloqueio externo, não aprovado

[Laravel #42278871601](https://github.com/rafhael100/imagine-portal/actions/runs/42278871601):
jobs PHP 8.3 e PHP 8.4 falharam **antes de iniciar**, sem steps ou logs de testes.
[Workflow geral #42278871715](https://github.com/rafhael100/imagine-portal/actions/runs/42278871715):
Node 22 e 24 na mesma condição.

Anotação oficial dos checks, lida pela API GitHub:

> The job was not started because recent account payments have failed or your spending limit needs to be increased. Please check the 'Billing & plans' section in your settings

Exemplo de evidência: check/job
[128102264714](https://github.com/rafhael100/imagine-portal/actions/runs/42278871601/job/128102264714).
Leitura reproduzível: `gh api repos/rafhael100/imagine-portal/check-runs/128102264714/annotations`.

O workflow do HEAD final será observado no
[PR #37 / Checks](https://github.com/rafhael100/imagine-portal/pull/37/checks) e registrado
no comentário de encerramento. Não usar os testes locais PHP 8.4 como alegação
de CI aprovado ou prova de PHP 8.3. Sem autorização para corrigir cobrança,
aumentar limite ou iniciar despesas. O critério de CI SUCCESS permanece pendente.

## Revisão visual real e limites de evidência

Em 06/11/2026, aproximadamente 16h25–16h28 America/Sao_Paulo: Laravel local
renderizou services e requests via CLI, com DatabaseManager substituído por um
guard que lança exceção em connection(). HTML aberto em memória no Chromium
156.0.8078.79 por Playwright Python já disponível; sem servidor/rotas e com
HTTP/HTTPS bloqueados. Não foi reconstruído HTML manual nem usado gerador de imagens.

Seis capturas reais full-page, viewport CSS:
- `services-1440.png`, `requests-1440.png`: 1440×900.
- `services-768.png`, `requests-768.png`: 768×1024.
- `services-390.png`, `requests-390.png`: 390×844.

Caminho temporário: `/workspace/work/ui4-final-shots/`, com checks.json.
Não há link durável ou artefato CI de screenshots; não foram commitadas imagens.
Pranchas reais e a captura final de Services no tablet foram exibidas no chat.
Não prometer disponibilidade futura desses PNGs.

Inspeção e medições dos seis cenários: sem overflow horizontal da página, sem
recorte nos textos verificados, sete itens de navegação, um aria-current, zero
controles operacionais/requisições externas. Tab alcança o skip link; Enter move
foco para o main. Reflow em metade do viewport CSS também sem overflow, mas isso
não é teste do zoom nativo. A revisão detectou texto comprimido nos cards do tablet;
dt/dd agora empilham nesse breakpoint e as capturas foram refeitas.

A renderização inicialmente planejada pelo CI não ocorreu por bloqueio da conta.
O exportador temporário inserido no primeiro commit para isso foi removido antes
da entrega; nenhum exportador permanece no teste final.

## Pendências e decisões futuras

- Responsável regularizar o bloqueio de Actions; executar/reexecutar PHP 8.3/8.4 e
  workflow geral no HEAD final, revisar logs e corrigir eventual falha revelada.
- Revisão humana de design/leitor de tela, zoom nativo e outros dispositivos.
- Distribuição durável das screenshots se desejada.
- Autenticação, fonte confiável de dados, autorização, persistência e integrações
  dependem de escopo próprio; nenhum gate de segurança ou PostgreSQL é fechado.

PR deve permanecer aberto em rascunho, sem merge. Implementação visual e testes
locais entregues; homologação bloqueada pelo CI indisponível.
