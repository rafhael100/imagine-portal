# PORTAL-UI-06 — Central de Avisos demonstrativa

## Objetivo e isolamento

Refs [Issue #42](https://github.com/rafhael100/imagine-portal/issues/42).
Base reconfirmada em 08/11/2026:
`03423d866322c8b01bb856210d2da758b4a33dbf`,
branch `dev/fast-track-portal-2026-11-05`.
Branch filha solicitada: `dev/portal-ui-06-avisos-demo-2026-11-07`.
[HEAD da entrega](https://github.com/rafhael100/imagine-portal/commit/dev/portal-ui-06-avisos-demo-2026-11-07).

Somente arquivos novos e exclusivos da UI-06. Listas de arquivos dos PRs #37,
#40 e #41 consultadas antes de editar; interseção com o diff desta entrega é zero.
Nenhum arquivo dessas branches importado para o produto ou alterado. A base
continua UI-01/02/03, sem incorporar UI-04/05 ou a fronteira de autoridade.

## Inventário

Caminhos relativos a apps/portal:

- `app/Presentation/NoticeCategory.php`
- `app/Presentation/DemoNotice.php`
- `app/Presentation/DemoNoticeCatalog.php`
- `resources/views/components/portal/notice-card.blade.php`
- `resources/views/components/portal/notice-list.blade.php`
- `resources/views/components/portal/notice-context.blade.php`
- `resources/views/portal/demo/notices.blade.php`
- `tests/Unit/NoticeCatalogPresentationTest.php`
- `tests/Feature/PortalNoticesPresentationTest.php`
- `docs/PORTAL-UI-06.md`.

Nenhum arquivo existente alterado: ClientSection, menu/sidebar/layout/styles,
views e testes anteriores, domínio, config, routes, bootstrap, Composer/lock,
workflows, main/dev, VPS e produção permanecem intactos.

## Contrato visual não operacional

- NoticeCategory: sete grupos fechados de conteúdo (geral, Sites, solicitações,
  Performance AI, conceito futuro de mídia, Universidade e CRM). fromCode retorna
  General para código desconhecido, com badge neutro; não é severidade real,
  canal de envio, autorização ou estado de serviço.
- DemoNotice: DTO readonly de referência demo_notice_, categoria tipada, título
  e body em texto simples. Sufixo ASCII de 1–48 caracteres; título não vazio de
  até 180 bytes; body não vazio de até 1600 bytes. Texto continua não confiável
  para HTML e é escapado por Blade.
- DemoNoticeCatalog: lista readonly de até 32 DemoNotice, sem referências
  duplicadas; catálogo vazio permitido. Oito exemplos determinísticos.
  Sem destinatário, ator, organização, evento, repositório ou serviço externo.
- notice-card: article com h3, badge e texto fictício.
- notice-list: section h2, lista semântica e estado vazio reutilizado. Grade
  compartilhada existente e ajustes somente locais ao componente novo.
- notice-context: panel/message existentes com aviso de demonstração.
- notices: view interna sem rota, title Central de Avisos e contexto explícito
  Central de Avisos — DEMONSTRAÇÃO. current=notices é neutro, sem item ativo.
  Pode receber catálogo vazio por variável de view em CLI/teste; nunca por endpoint.

Estados: lista fictícia preenchida e catálogo vazio. **Não há campo de lido,
não lido, concluído, contador de não lidas, datas ou estado persistente.**
Nenhum botão de marcar leitura, formulário, link operacional ou menu funcional.
O único href é o skip link interno do layout existente.

## Conteúdo e independência dos produtos

Empresa Aurora e todas as referências são fictícias. Onepage e Landing Page
permanecem independentes; não se confirma site contratado, domínio, publicação
ou infraestrutura. Performance AI é serviço próprio com motor de execução
separado; campanhas são apenas ilustrativas, sem métricas reais/GA4 ou Google/Meta.

Um aviso separado de categoria MediaCredit apresenta **somente o conceito futuro**
de recarga de mídia em Performance AI. Não há valores, saldo, pagamento, recarga
agendada, calendário, regra 7/3/0, disparos ou SMS/WhatsApp/e-mail. As regras de
qualquer produto futuro dependem de decisões próprias de Performance AI.

Solicitações são orientação narrativa, sem abrir/atribuir/enviar/aprovar chamados
ou prometer atendimento/SLA. CRM mantém identidade/login/sessão próprios, sem
SSO e sem replicação de notificações. Universidade é benefício de clientes
elegíveis, não assinatura avulsa; nenhuma elegibilidade ou acesso é concedido.
Exibir conteúdo não é comprovante de envio, execução, contrato ou permissão.

## Resultados locais efetivos

Ambiente já disponível: PHP **8.4.24**, PHPUnit **12.5.43**, Laravel **13.40.0**.
Runtime temporário em `/workspace/work/php84/php`; vendor somente com dependências
do lock da base, reaproveitado em checkout isolado. Nenhum pacote novo instalado
nesta atividade; Composer/lock não modificados. Sem driver PostgreSQL no runtime.

Em apps/portal, com chave descartável apenas no processo de testes:

```sh
php vendor/bin/phpunit --colors=never --no-progress --filter 'NoticeCatalogPresentationTest|PortalNoticesPresentationTest'
php vendor/bin/phpunit --colors=never --no-progress
```

php representa o wrapper temporário acima.

- Testes novos: **OK (9 tests, 223 assertions)**.
- Suíte completa UI-03 + UI-06: **OK (124 tests, 1988 assertions)**.
- PHP 8.3 não está disponível localmente e não foi executado.

Cobertura: referências/textos/quantidade/duplicatas/tipagem, categorias e fallback,
determinismo, limites exatos e catálogo vazio; escape de title/body/contexto;
sem forms/scripts/controles/links externos; navegação derivada de ClientSection
quanto a quantidade, ordem, rótulos e item ativo. Nenhuma contagem fixa de seções.
As views do catálogo atual e Notices renderizam sem alteração de rotas ou sessão.
DatabaseManager usa mock PHPUnit expects(never())->method('connection').
Não utiliza Mockery, migrations ou dados reais.

## Navegador: checks reais, sem screenshot persistente

Laravel local gerou HTML real via CLI para lista preenchida e catálogo vazio.
Um guard de DatabaseManager lança exceção em connection() nessa exportação.
Chromium **156.0.8078.79** e Playwright Python já disponíveis; HTML em memória,
HTTP/HTTPS bloqueados; sem servidor, rota ou acesso a produção.

Seis cenários (preenchido/vazio × **1440×900**, **768×1024**, **390×844**)
passaram em checks DOM/geometria/teclado: sem overflow horizontal da página ou
recorte nos textos medidos; zero controles operacionais/requisições externas;
menu com os rótulos da base e zero itens ativos; somente skip link interno.
Tab alcança o skip link e Enter move foco ao main.
Reflow adicional em metade do viewport CSS passou (720/384/195px);
não equivale ao controle nativo de zoom 200%.

**Não foram entregues screenshots ou artefatos de imagem persistentes.**
Não há destino de anexos persistente disponível; não inventar links. A revisão
foi automatizada de HTML/geometria/teclado, não inspeção visual humana ou auditoria
de leitor de tela. Medições locais em `/workspace/work/ui6-browser-checks.json`
são temporárias e não um artifact durável.

## GitHub Actions e validação oficial

Os últimos jobs conhecidos não iniciaram por bloqueio de pagamento/limite da conta.
O push/PR desta entrega pode disparar workflows existentes; não há rerun repetitivo,
alteração de workflow, limite, faturamento, orçamento ou despesa.

O [CI da branch](https://github.com/rafhael100/imagine-portal/actions?query=branch%3Adev%2Fportal-ui-06-avisos-demo-2026-11-07)
e o SHA final explícito são conferidos e registrados no comentário de encerramento
do PR. Registrar falhas de pré-inicialização como **testes não executados**.
Não há alegação de CI aprovado: PHP 8.4 local não substitui matriz oficial
PHP 8.3/8.4 nem testes gerais. Links exatos e anotações do HEAD ficam no PR.

## Reprodução e pendências

Após bootstrap Laravel em CLI: `view('portal.demo.notices')->render()`.
Para vazio: `view('portal.demo.notices', ['catalog' => new DemoNoticeCatalog()])->render()`,
importando a classe de Presentation. Não criar rota para preview.
Os componentes recebem apenas DTO/catálogo de apresentação, sem HtmlString
ou objetos de identidade. Nenhum serviço de notificação é necessário.

Pendente validação oficial quando Actions puder executar, PHP 8.3, revisão humana
de design/leitor de tela, zoom nativo e imagens persistentes se desejadas.
Navegação futura e integração são independentes e dependem de resolver UI-04/05,
reconciliar ClientSection e obter autorização separada. Não concedem permissões
nem fecham gates de Segurança/PostgreSQL. Regras futuras de recarga pertencem
a decisões próprias de Performance AI.

PR somente em rascunho, sem merge ou deploy. Nenhum login/MFA/SSO, fila/cache,
banco/SQL/migration/RLS, API, token, envio, pagamento ou integração implementado.
