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
