# PORTAL-UI-03 — Jornada do cliente

Issue #34. Base inicial: `227e4b7324ab0e600dc37b35f6acfbea22e9bb7c`.
Branch: `dev/portal-ui-03-jornada-navegacao-demo-2026-11-06`.

## Implementação

Sexta view não roteada: `portal.demo.journey`. As cinco views anteriores mantêm
conteúdo e componentes; somente seus títulos passam a vir do catálogo comum.
`ClientSection` é enum puro, imutável, ordenado: overview, services, available,
contracts, modules, journey. Centraliza chave e rótulo usados por layout/sidebar.
Chave desconhecida não seleciona item e usa título neutro. Nenhum item gera link.

Novos componentes anônimos Blade:

| Componente | Props de apresentação | Comportamento |
| --- | --- | --- |
| journey | title, steps (title/description/state) | Lista ordenada, progresso nativo acessível, vazio explícito |
| next-stage | title, description, state | Próximo foco sem botão ou ação operacional |
| service-status | state | Rótulo e paleta fechados; código desconhecido vira example |
| pending-notice | title, items, state | Lista fictícia, sem solicitar documentos ou dados |

`JourneyState` valida o vocabulário ilustrativo:

| Código | Rótulo | Tom |
| --- | --- | --- |
| example | Exemplo ilustrativo | neutral |
| illustrated | Etapa ilustrada | success |
| preparing | Em preparação fictícia | info |
| waiting | Pendência fictícia | warning |
| next | Próxima etapa ilustrativa | info |

Nenhum desses estados representa situação jurídica, financeira ou operacional.
O progresso conta apenas etapas `illustrated` do cenário fornecido. Não usa banco,
identidade, sessão, relógio ou serviço externo. Props textuais são escapadas pelo
Blade; o código desconhecido não é interpolado em classes CSS.

Sites Onepage e Landing Page aparecem separadamente; Performance AI é serviço
próprio administrado pelo Portal com execução externa não implementada; Google Ads
e Meta são descrições. CRM mantém login/sessão próprios sem SSO. Universidade é
benefício de clientes elegíveis, sem assinatura avulsa nem concessão de acesso.

## Extensão futura, sem segurança operacional

`ClientSection::cases()` é catálogo visual, não lista de permissões. A futura
camada backend deverá fornecer uma projeção de navegação após validar ator,
organização, recurso e direitos, depois dos gates SEC-P02/G-ID/RLS. Não confiar
em `current`, `state`, props Blade ou flags da view para permitir acesso. Só então
rotas autenticadas e suas decisões de backend poderão ser implementadas em lote
separado. Este lote não cria middleware, policy, autenticação, link ou endpoint.

## Verificação

O teste existente `PortalUiComponentsTest` passa a cobrir seis telas, mantendo o
mock DatabaseManager com expectativa `never()->connection()`, restrição dos links
a `#pi-content`, ausência de formulários/scripts e invariância exata de rotas e
sessão não iniciada. `PortalJourneyPresentationTest` cobre catálogo/ordem/chave
ativa, estados fechados e fallback, escaping, progresso/vazio e limites dos produtos.

Comando do workflow existente: `php artisan test --no-ansi`, matriz PHP 8.3/8.4.
Nenhuma alteração em workflow, lockfile, configuração ou rotas. Resultado real de
CI e SHA final constarão do relatório do PR após execução. Localmente não há PHP;
nenhum resultado local de Laravel é presumido.

QA visual: a captura, se disponível, será derivada do HTML Blade renderizado por
CLI, sem endpoint público e sem binário no Git. Sem artefato verificável, QA visual
permanece pendente. Não há homologação de acessibilidade completa ou navegador.

Gates G-ID, G-BB, SEC-P02 e demais gates preservados. Sem main/dev diretamente,
merge, deploy, SQL, migrations, pagamentos, contratos reais ou integrações.

## Evidência de revisão do primeiro CI

Run `42270209620`, head `efaee78088c48b731b8adfd1228a576dd0179da2`:
114 testes passaram e um novo teste falhou por exigir HTML sem espaço antes de
`>`. A verificação passou a consultar DOM/XPath para checar item ativo e ordem
sem depender de whitespace. Nenhuma expectativa de DB, rotas ou sessão foi
alterada. A matriz será reexecutada no commit de correção; resultado final no PR.

Captura não disponibilizada: não há runtime PHP local para renderizar Blade por
CLI e o workflow existente não exporta HTML/capturas. Não foram criados endpoints,
workflow alternativo ou imagem que simulasse uma renderização real. QA visual
permanece pendente, conforme critério condicional da Issue #34.
