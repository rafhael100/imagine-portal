# Portal Imagine — gate de integração verificável (piloto isolado)

Este é o **primeiro lote pequeno diretamente baseado na branch `dev/fast-track-portal-2026-11-05`**, separado dos mais de mil commits acumulados no bloco de identidade/Portal. Não contém autenticação, migração, endpoint, container, UI de clientes, deploy nem acesso à VPS.

## O que o gate faz

`integration/portal-candidate.json` fixa a **SHA completa** e o número da candidata de revisão [#578](https://github.com/rafhael100/imagine-portal/pull/578), o alvo `dev`, a base e os **nove workflows** obrigatórios. `tools/portal-integration-gate.mjs` lê **somente** a API GitHub com `GITHUB_TOKEN` de leitura e verifica:

1. A PR está aberta, em DRAFT, pertence ao mesmo repositório, preserva a branch-base e a SHA fixada.
2. Cada um dos nove workflows possui conclusão `success` na **mesma SHA exata** e é uma execução de `pull_request`. Uma execução posterior falha ou pendente não pode ser substituída por uma anterior aprovada.
3. A comparação com a `dev` não está atrás nem divergiu. O número agregado de commits usa a API `compare`; o limite de 300 arquivos da resposta **não** é interpretado como revisão de todos os arquivos.
4. Um lote grande demais (limite de revisão: **50 commits**) recebe `BLOCKED_SPLIT_INTEGRATION_LOTS`. A candidata atual apresenta mais de mil commits e **não** pode ser apresentada como um merge unitário aprovado.
5. **Mesmo com as nove execuções verdes**, `mergeApproved`, `deployApproved`, `customerAccessApproved` e `antiRollbackApproved` são *sempre* `false`. Os demais gates de segurança, homologação, AppSec/DBA e anti-rollback permanecem bloqueados.

## Execução

- Teste offline: `node --test tools/portal-integration-gate.test.mjs` (Node 22, sem pacotes externos).
- Auditoria atual: `GITHUB_TOKEN=<token-de-leitura> node tools/portal-integration-gate.mjs --verify-github`. **Não** colocar tokens em chats, commits ou arquivos. Em GitHub Actions, o token é injetado pela plataforma e recebe `actions:read`, `contents:read`, `pull-requests:read`.
- A CI `portal-integration-audit.yml` é disparada neste pequeno PR contra a `dev` e não modifica branches, PRs ou configurações de segurança.

**Como interpretar a CI verde:** significa somente que o verificador funcionou e comprovou o estado declarado. Quando o resultado diz `BLOCKED_SPLIT_INTEGRATION_LOTS`, **o bloqueio de merge é real e deliberado**, não uma autorização indireta. Será preciso separar UI demonstrativa, autoridade/PG18, CRM, Sites e demais dependências em pequenos lotes revisáveis, sem copiar a cadeia inteira. A issue [#341](https://github.com/rafhael100/imagine-portal/issues/341) acompanha esse trabalho, e a issue [#64](https://github.com/rafhael100/imagine-portal/issues/64) continua bloqueando autenticação e restauração operacional.

**Status:** DRAFT / NO-GO para qualquer deploy ou merge automático.

## Lotes pequenos já preparados na dev

O mesmo manifesto fixa e confere agora os dois PRs de interface sem dependências de IAM/PG18:
- [#580](https://github.com/rafhael100/imagine-portal/pull/580): política CSP via `meta` e referrer no HTML demonstrativo, dois commits e dois arquivos, testes Node e Laravel em SHA fixa.
- [#581](https://github.com/rafhael100/imagine-portal/pull/581): **lote cumulativo preferido** (8 commits, 8 arquivos), reunindo os três contratos fictícios independentes com as mesmas proteções HTML CSP/referrer da #580; testes Node e Laravel no SHA congelado. A #580 permanece apenas para auditoria e não deve ser mesclada em duplicidade.

Cada lote exige que a PR continue aberta e em draft, branch-base exatamente `dev`, duas execuções de CI próprias em `success` e **no máximo 20 commits novos**, sem commits atrás da branch de integração. A regressão da ferramenta impede aceitar testes antigos após nova SHA ou um dos lotes que falhe. O resultado continua `REVIEW_CANDIDATE_ONLY`, **nunca merge/deploy aprovado**; qualquer atualização de #580/#581 exige atualizar manifesto e reconferir CI. O bloco empilhado #578 permanece `BLOCKED_SPLIT_INTEGRATION_LOTS`, sem se confundir com os PRs menores.

## Revisão em dois lotes sem duplicações (INT-UI-08 + INT-UI-10)

O manifesto atualizado seleciona somente dois lotes canônicos:

1. **[#588](https://github.com/rafhael100/imagine-portal/pull/588)** — interface demonstrativa unificada de catálogo, contratos, solicitações, fluxo e visão geral; **41 commits** diretamente a partir da `dev` e testes Node/Laravel já aprovados na SHA `735606ed00dcb669daf0ee8e72552c632ee05402`.
2. **[#592](https://github.com/rafhael100/imagine-portal/pull/592)** — exclusivamente os **10 commits** adicionais da revisão editorial do Sites; tem como base a branch exata da #588, não a `dev`, e deve ser revalidada em seu HEAD `559521d3dc384e686b186bffe706cf49f1fa3328`.

O gate confere para cada PR sua base de comparação correta: `dev...#588` e `#588...#592`. Se a #588 mudar sem rebase/reteste da #592, a comparação deixa de ser limpa e a auditoria falha fechada. Não são aceitos desvios silenciosos para outra base ou mais de 20 commits no delta editorial. As PRs históricas #580/#581/#582/#583/#584/#585/#587/#590 não devem ser mescladas simultaneamente com estas duas candidatas, pois contêm código ancestral/duplicado.

**Um resultado verde continua sendo apenas prova de revisão.** A revisão operacional e a autorização específica de merge/deploy são decisões separadas, e a issue de anti-rollback #64 permanece NO-GO. Não existe automática publicação ou ativação de cliente neste fluxo.

## Atualização — quatro etapas canônicas de interface (12/11/2026)

A seleção anterior de dois lotes foi **ampliada**, sem trocar as versões aprovadas. O caminho atual de revisão, sempre pelo par `base...head` exato, é:

| Ordem | PR | Base de comparação | Commits incrementais | Alterações |
| --- | --- | --- | ---: | --- |
| 1 | [#588](https://github.com/rafhael100/imagine-portal/pull/588) | `dev/fast-track-portal-2026-11-05` | 41 | Catálogo, contratos, solicitações, painel |
| 2 | [#592](https://github.com/rafhael100/imagine-portal/pull/592) | Branch da #588 | 10 | Editorial Sites sem catálogo duplicado |
| 3 | [#594](https://github.com/rafhael100/imagine-portal/pull/594) | Branch da #592 | 16 | **Logomarca PNG original**, laranja/grafite, sidebar e CSS responsivo |
| 4 | [#595](https://github.com/rafhael100/imagine-portal/pull/595) | Branch da #594 | 13 | Prévia offline das 9 telas existentes + índice, auditoria acessível |

Cada etapa possui **dois workflows aprovados** no seu SHA exato: testes Node e Laravel PHP 8.3/8.4. A quarta inclui um artefato ZIP privado de revisão com **oito HTML**, o PNG original incorporado e manifesto de integridade; isso **não** é uma publicação.

O gate valida cada base, nome de branch, PR, SHA, workflows e o limite de commits. Se a base ou HEAD de uma etapa for alterada, o conjunto deve ser reavaliado; não é permitido pular ou reaplicar um lote. A candidata extensa #578 continua **bloqueada para merge integral**, independentemente de CI verde.

**NO-GO continua:** nenhum sinal de aprovação de produção é emitido por este gate. QA visual em dispositivos reais, revisão independente AppSec/DBA/LGPD, recuperação/anti-rollback #64, homologação e autorização específica de merge/deploy não foram dispensados. As PRs históricas incrementais não devem ser mescladas em duplicidade.

**Confirmação da quarta etapa:** SHA final `1548c8327e1e4ddbcb99e5354a126bd29e4a2555`; [Node 43312909255](https://github.com/rafhael100/imagine-portal/actions/runs/43312909255) e [Laravel PHP 8.3/8.4 43312909311](https://github.com/rafhael100/imagine-portal/actions/runs/43312909311) aprovados. O pacote [artefato GitHub #13985709640](https://github.com/rafhael100/imagine-portal/actions/runs/43312909311/artifacts/13985709640) contém 8 páginas + manifesto SHA256. Os testes agora também exigem um único h1/main, legendas, cabeçalhos com `scope=col` e regiões de rolagem acessíveis por teclado. Uma tabela adversarial sem foco deve ser rejeitada. Isto não substitui QA visual humano.

**Revisão final do pacote completo:** a etapa #595 foi fixada em `cb95dae2f27238a80e04e9b313a0e6c5d86508e3`, com **13 commits / 5 arquivos** sobre #594. [Node 43313630955](https://github.com/rafhael100/imagine-portal/actions/runs/43313630955) e [Laravel PHP 8.3/8.4 43313630918](https://github.com/rafhael100/imagine-portal/actions/runs/43313630918) **success**. Artefato final [#13987471119](https://github.com/rafhael100/imagine-portal/actions/runs/43313630918/artifacts/13987471119), **96.118 bytes**, contém **10 HTML (nove telas, incluindo Ajuda e Avisos, mais índice) + 1 manifesto**. Os hashes, a logomarca original repetida nas nove telas e os seis componentes de tabela foram verificados no ZIP. Nenhum link externo ou script/form. QA visual humano ainda pendente.

## Revisão responsiva final da #595 — 12/11/2026

A validação no **Chromium real** encontrou e corrigiu a largura global indevida de **912 px num viewport de 390 px**, causada por `grid-template-columns:1fr` numa grid com sidebar de menu rolável. A versão final substitui por `grid-template-columns:minmax(0,1fr)`. O QA inspecionou **70 combinações (dez páginas × sete larguras: 320, 375, 390, 640, 768, 1024, 1440 px)** sem overflow, confirmou nove menus móveis com scroll e **seis tabelas roláveis por teclado**. Relatório JSON produzido localmente; não é certificação WCAG ou de segurança de produção.

SHA fixado da [#595](https://github.com/rafhael100/imagine-portal/pull/595): `0aa7bc09e76f6f1380587eb29e835ac5f2a81663`, **19 commits / 8 arquivos** incrementais sobre #594, zero atrás. [Node 43321514546](https://github.com/rafhael100/imagine-portal/actions/runs/43321514546) e [Laravel PHP 8.3/8.4 43321514798](https://github.com/rafhael100/imagine-portal/actions/runs/43321514798) aprovados; [artefato offline #13991186373](https://github.com/rafhael100/imagine-portal/actions/runs/43321514798/artifacts/13991186373), 10 HTML + 1 manifesto. O script reprodutível `apps/portal/tools/qa_portable_preview.py` valida largura, marcos, scroll de tabela e menus em Chromium, com sintaxe verificada pela CI. O limite de revisão da quarta etapa continua **20 commits**, portanto a candidata segue dentro do limite.

Uma etapa de segurança **separada** [#597](https://github.com/rafhael100/imagine-portal/pull/597) nasceu diretamente da `dev` e passou em Node/Laravel: contrato de laboratório para restauração fail-closed, sem liberar HTTP ou produzir testemunha externa real. **Não integra o caminho de UI nem resolve a [issue #64](https://github.com/rafhael100/imagine-portal/issues/64)**. Continua DRAFT/NO-GO. As quatro etapas de UI continuam sendo revisão somente leitura, sem merge automático.

## Atualização — CI visual real e navegação offline de revisão (12/11/2026)

O quarto lote [#595](https://github.com/rafhael100/imagine-portal/pull/595) evoluiu para a SHA **`8163ab6bc471563926fae930f0cea409573a9c39`**. A PR continua baseada em #594, **25 commits / 8 arquivos** à frente, zero atrás. O limite explícito da etapa 4 passou de **20 para 25 commits**, com teste que **bloqueia 26**; o limite geral de integração permanece 50.

Entregas adicionais: Chromium executado obrigatoriamente no mesmo workflow Laravel antes de disponibilizar o artefato, em 70 combinações de larguras/páginas, com conferência de 10 hashes de arquivo, 9 menus do Portal, 6 tabelas via teclado e **10 barras de navegação offline em faixa horizontal**. O HTML de revisão agora usa laranja escuro `#994B00`, texto legível e indicação da página ativa; não acrescenta rotas. [Node 43324550673](https://github.com/rafhael100/imagine-portal/actions/runs/43324550673) e [Laravel PHP 8.3/8.4 43324550535](https://github.com/rafhael100/imagine-portal/actions/runs/43324550535): **success**. O ZIP privado é [artefato #13990779566](https://github.com/rafhael100/imagine-portal/actions/runs/43324550535/artifacts/13990779566). A execução visual é **CI automatizada, não uma certificação WCAG nem uma aprovação de deploy**.

A PR de segurança independente [#597](https://github.com/rafhael100/imagine-portal/pull/597) corrigiu também uma suposição de relógios lógicos: `witness.sequence` e `snapshot.backupSequence` **não podem ser comparados diretamente**. O laboratório agora exige declaração de cobertura da sequência primária, ainda **não autenticada** e incapaz de liberar sessões reais. [Node 43323975059](https://github.com/rafhael100/imagine-portal/actions/runs/43323975059) e [Laravel 8.3/8.4 43323975084](https://github.com/rafhael100/imagine-portal/actions/runs/43323975084) aprovados. SEC-R03 e [#64](https://github.com/rafhael100/imagine-portal/issues/64) permanecem NO-GO.


## Revisões independentes adicionadas ao gate — 12/11/2026

A auditoria `read-only` passou a verificar também **duas candidatas que NÃO pertencem à cadeia de quatro lotes de interface**. O manifesto fixa SHAs, PRs, branches-base próprias e o conjunto de workflows de cada uma:

| Trilha separada | Base efetiva | Delta máximo | Testes exigidos |
| --- | --- | ---: | --- |
| [#596](https://github.com/rafhael100/imagine-portal/pull/596), login privado/MFA | `ops/portal-private-auth-deployment-2026-11-12` | 20 commits | Dez workflows, incluindo PHP, PostgreSQL 18, Docker e UI |
| [#597](https://github.com/rafhael100/imagine-portal/pull/597), recuperação anti-rollback em laboratório | `dev/fast-track-portal-2026-11-05` | 20 commits | Node e Laravel PHP 8.3/8.4 |

O programa agora expõe `isolatedLots` separadamente de `smallLots`. Um resultado `REVIEW_CANDIDATE_ONLY` significa **somente** integridade dos metadados, ancestralidade e verificações declaradas: não prova que as candidatas foram integradas entre si, nem que o login possui operador real, nem que houve solução operacional da [issue #64](https://github.com/rafhael100/imagine-portal/issues/64).

Um novo commit, alteração da base, divergência, execução CI falha/pendente ou excesso de commits cancela a prova daquele lote. Não inserir #596 ou #597 como quinta etapa da UI: suas bases diferem e fundi-las sem reconciliação seria inadequado. **Merge, deploy, acesso real e recuperação em produção continuam false** independentemente de todos os testes.
