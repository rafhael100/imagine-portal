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
