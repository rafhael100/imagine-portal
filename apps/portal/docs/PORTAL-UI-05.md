# PORTAL-UI-05 — Central de Ajuda demonstrativa isolada

## Escopo e concorrência

Issue [#38](https://github.com/rafhael100/imagine-portal/issues/38).
Base reconfirmada em 07/11/2026: `03423d866322c8b01bb856210d2da758b4a33dbf`,
`dev/fast-track-portal-2026-11-05`.
Branch: `dev/portal-ui-05-central-ajuda-demo-2026-11-07`.
[HEAD da entrega](https://github.com/rafhael100/imagine-portal/commit/dev/portal-ui-05-central-ajuda-demo-2026-11-07).

Somente arquivos novos. A lista dos 19 arquivos do PR #37 foi obtida antes de
editar e comparada com o diff final: nenhuma interseção. Não incorpora UI-04.
ClientSection, sidebar/layout/styles, views e testes antigos permanecem intactos.
PRs #19/#24/#25/#33/#37 e gates não são modificados nem homologados.

## Código entregue e contratos visuais

- `app/Presentation/HelpCategory.php`: sete categorias fechadas de conteúdo;
  código desconhecido retorna General. Categoria não é permissão ou estado real.
- `app/Presentation/HelpTopic.php`: DTO readonly de referência fictícia, categoria,
  título e orientação em texto simples. Referência demo_help_ com sufixo ASCII
  de 1–40 caracteres; título não vazio de até 240 bytes; orientação de até 2000.
  Texto válido continua não confiável para HTML e é escapado por Blade.
- `app/Presentation/DemoHelpCatalog.php`: catálogo readonly determinístico;
  lista tipada com no máximo 24 tópicos e referências únicas. Lista vazia permitida.
  Sem repositório, organização, identidade, pedido, rede ou serviço externo.
- `resources/views/components/portal/help-topic.blade.php`: artigo FAQ estático,
  badge de categoria e título h3, reutilizando as classes e badge existentes.
- `resources/views/components/portal/help-topic-list.blade.php`: seção h2 e grade
  existente; reutiliza empty-state para catálogo vazio. Ajuste local de min-width
  e quebra de palavras mantém reflow estreito, sem editar styles compartilhado.
- `resources/views/components/portal/help-context.blade.php`: contexto de suporte
  somente ilustrativo; reutiliza panel e message existentes.
- `resources/views/portal/demo/help.blade.php`: view interna de Central de Ajuda,
  oito orientações e aviso de indisponibilidade. Title Central de Ajuda e painel
  explícito DEMONSTRAÇÃO; current=help é neutro, com zero item ativo.
- `tests/Feature/PortalHelpPresentationTest.php`: cinco testes novos isolados.
- `tests/Unit/HelpCatalogPresentationTest.php`: quatro testes novos puros.
- `docs/PORTAL-UI-05.md`: este relatório.

Estados visuais: catálogo com orientações, catálogo vazio e aviso de
funcionalidades indisponíveis. Não há estado de atendimento/serviço concedido.
Não são usados acordeões, details/summary, scripts ou controles de envio.

Conteúdo fictício da Empresa Aurora: orientação geral, Onepage e Landing Page
independentes, Performance AI com motor separado, solicitações e contratos
apenas ilustrativos, CRM com identidade/login/sessão próprios sem SSO e
Universidade como benefício de clientes elegíveis, nunca assinatura avulsa.
A ajuda do Portal não é a Universidade nem implementa cursos.
Nada confirma implantação, prazo/SLA, reembolso, contratação, preço, elegibilidade,
direito, resposta oficial ou atendimento disponível. Nenhuma URL operacional.

## Verificação local efetivamente executada

PHP **8.4.24**, PHPUnit **12.5.43**, Laravel **13.40.0**.
Runtime temporário já disponível em `/workspace/work/php84/php`.
Vendor do lock idêntico ao da base reaproveitado em checkout isolado; não houve
instalação de pacote nesta atividade nem alteração de Composer/lock.

Em apps/portal, com chave descartável apenas no processo de teste:

```sh
php vendor/bin/phpunit --colors=never --no-progress --filter 'HelpCatalogPresentationTest|PortalHelpPresentationTest'
php vendor/bin/phpunit --colors=never --no-progress
```

`php` representa o wrapper temporário acima. Resultados:
- Novos testes: **OK (9 tests, 182 assertions)**.
- Suíte completa da base UI-03 mais UI-05: **OK (124 tests, 1947 assertions)**.
- Suíte repetida após ajustes de reflow: mesmo resultado.
- PHP 8.3 indisponível localmente; não executado nem presumido aprovado.

Cobertura: determinismo, categorias/fallback, limites, rejeição de referências
não fictícias, duplicatas/listas não tipadas, catálogo vazio, HTML escapado em
títulos/orientações/contexto, conteúdo e separação dos produtos.
As seis views existentes e Help renderizam no mesmo teste sem links externos,
forms, scripts, endpoints ou controles operacionais; somente o skip link interno.
Navegação conserva os seis itens e não registra help. Comparação integral do
registro de rotas antes/depois; somente up e storage/{path} já existentes.
Sessão não iniciada e DatabaseManager::connection proibida via mock PHPUnit
expects(never()). Nenhum banco ou migration executado.

## Navegador, teclado e limites visuais

HTML real gerado por Laravel local via CLI, com guard de DatabaseManager que
lança exceção em connection(). Chromium **156.0.8078.79**, Playwright Python já
instalado. Documento carregado em memória; HTTP/HTTPS bloqueados. Sem servidor,
rotas públicas, navegador de produção ou dados reais.

Viewports **1440×900**, **768×1024**, **390×844**:
sem overflow horizontal da página, sem recorte nos textos medidos, seis itens
de menu/zero item ativo, zero controles operacionais e requisições externas.
Tab alcança o skip link; Enter move foco ao main.
Reflow adicional a metade do viewport CSS (720/384/195px) passou após ajuste
do título e da seção nova. Não equivale a teste do zoom nativo 200%.

Verificações foram de DOM, geometria e teclado automatizado, sem inspeção humana
de screenshots. **Nenhuma captura persistente/artifact foi entregue**: não há
destino persistente de anexos disponível neste ambiente. Não inventar URLs ou
afirmar auditoria visual completa. Relatório temporário de medições:
`/workspace/work/ui5-checks/checks.json` (não é artefato persistente).

## GitHub Actions — registrar sem presumir aprovação

O bloqueio conhecido de pagamento/limite da conta foi comprovado nos últimos
jobs do PR #37, antes de qualquer step, por exemplo
[run #42279891181](https://github.com/rafhael100/imagine-portal/actions/runs/42279891181):

> The job was not started because recent account payments have failed or your spending limit needs to be increased. Please check the 'Billing & plans' section in your settings

Push/PR desta branch pode disparar os workflows preexistentes. O resultado do
[CI desta branch](https://github.com/rafhael100/imagine-portal/actions?query=branch%3Adev%2Fportal-ui-05-central-ajuda-demo-2026-11-07)
e o SHA final explícito são conferidos e registrados no comentário de encerramento
do PR desta entrega. Até haver execução verificável, **nenhum sucesso de CI é
declarado**. Testes locais PHP 8.4 não substituem matriz oficial PHP 8.3/8.4.

Não foram alterados workflows nem limites/cobrança, e não há rerun repetitivo.
Permanece pendente validação oficial após regularização pelo responsável.

## Reprodução e integração posterior

Renderização interna: `view('portal.demo.help')->render()`, após bootstrap Laravel
em CLI ou testes; nunca criar rota para preview público. Os componentes novos
recebem HelpTopic e DemoHelpCatalog de apresentação; não receber dados de
identidade ou HtmlString. `new DemoHelpCatalog()` mostra o estado vazio.

Somente após aprovação/integração da UI-04 e autorização separada, reconciliar
ClientSection e decidir a inclusão de Help na navegação, em alteração isolada.
Nesta etapa current=help não equivale a entitlement ou item cadastrado.

Pendentes: revisão humana de design/leitor de tela, screenshots acessíveis,
outros motores/dispositivos e CI oficial. Autenticação, fonte confiável de dados,
persistência ou qualquer serviço real exigem novo escopo.
PR permanece aberto em rascunho, sem merge. Main, dev, VPS, produção e gates
de Segurança/PostgreSQL preservados.

## RV01 — Navegação derivada do catálogo e compatibilidade provisória

Correção do [parecer RV01](https://github.com/rafhael100/imagine-portal/pull/40#issuecomment-6710394255),
na mesma branch do PR #40. Somente PortalHelpPresentationTest.php e este relatório
alterados em relação à entrega inicial.

Removidas as duas contagens rígidas de seis seções; o nome do teste também não
fixa quantidade. ClientSection é a fonte da quantidade, da ordem e dos rótulos.
Cada view do catálogo é renderizada e seu menu real é comparado ao catálogo:
exatamente um item ativo com aria-current=page e rótulo da seção correspondente.
Help não pertence ao catálogo e deve ter zero itens ativos. Rotas/sessão e o
mock PHPUnit que proíbe connection() permanecem verificados. Não há alteração
de view, enum, layout, navegação de produto ou arquivo do PR #37.

Resultados locais efetivos em PHP 8.4.24 / PHPUnit 12.5.43:

| Cenário | Provas Help | Suíte completa |
| --- | --- | --- |
| Base atual UI-03 + UI-05 corrigida | 9 testes / 209 asserções OK | 124 testes / 1974 asserções OK |
| Convivência provisória UI-04 + UI-05 corrigida | 9 testes / 226 asserções OK | 134 testes / 2141 asserções OK |

O segundo cenário foi executado em clone temporário detached,
`/workspace/work/ui5-rv01-compat`. Aplicou-se apenas na working tree o diff
`03423d866322c8b01bb856210d2da758b4a33dbf..1499a18c9028c6f9e60811cf8e0914436285cad5`
do PR #37, mais o teste Help corrigido. git apply --check aprovado.
Nenhum commit de integração, merge, push ou alteração da branch do PR #37:
é uma prova provisória com sete seções, não autorização de integração.
O diff do PR #40 continua sem interseção com os 19 arquivos do PR #37.

Comandos de teste: os mesmos filtros Help e suíte completa documentados acima,
executados nos dois diretórios. PHP 8.3 continua indisponível localmente.
O resultado do Actions para o novo SHA e os links dos runs são registrados no
comentário de encerramento do PR #40; não houve rerun repetitivo. Teste local
não substitui validação oficial. Continuam pendentes CI efetivamente executado,
revisão humana e autorização expressa antes de qualquer integração.
