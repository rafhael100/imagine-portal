# GSC-F161 — Inventário integral de integração, sem merge

A análise GitHub por API da cadeia `dev/fast-track-portal-2026-11-05` → revisão SEO pode retornar somente 300 caminhos mesmo que haja mais alterações. A F161 executa um **inventário completo no CI a partir de Git local**:

- Checkout isolado em `.qa/portal-inventory` com `fetch-depth: 0`, `contents: read`, credenciais não persistidas e commit `head.sha` exato do PR.
- Compara o HEAD **(1)** com a branch `dev/fast-track-portal-2026-11-05` e **(2)** com o `base.sha` do próprio PR. Exige ancestralidade real e contagem consistente de commits.
- Usa `git diff --no-renames --name-status -z` para listar **todos** os caminhos, incluindo nomes com caracteres especiais, sem o limite da API. A classificação de risco por nome (identidade, banco, pagamentos, integrações, CI, UI) é indicativa e não representa auditoria AppSec.
- Arquivo `full-diff.json` lista os caminhos e seus status/categorias; `summary.md` traz totais e SHAs. São artefatos de revisão retidos por dois dias e resumidos no GitHub Actions.
- Testes de regressão incluem 351 caminhos (acima do teto REST), categorias sensíveis, itens repetidos e lista fora dos limites.

**NO-GO obrigatório:** o inventário não lê dados de clientes, não aplica diffs, não concede direitos, não executa migrações/rollback, não mescla branches e não autoriza deploy. A promoção segura exige revisão real de cada lote, resolução de conflitos, AppSec/DBA/LGPD independentes e homologação de ambiente. Consulte [integração PORTAL-INT-01](https://github.com/rafhael100/imagine-portal/issues/341).

## GSC-F162 — Cerca de escopo da PR #394

Quando a base do PR é o commit F154 `d43323c9f0efe8ab5144e0b1e0f2da920a44d817`, a F161 exige que **todos os caminhos** alterados permaneçam na lista estreita de contrato PHP de revisão, testes de laboratório GSC-F155–F161, renderer F159 e CI F79. Qualquer novo caminho fora dessa lista — especialmente rota HTTP, provedor, OAuth real, migration, serviço externo ou deploy — provoca falha no CI. Novos caminhos devem ser reavaliados explicitamente antes de ampliar a lista. O teste não confirma que o conteúdo de um arquivo permitido é seguro; a revisão humana ainda é necessária.

A cerca não é aplicada a outros PRs com bases diferentes. Ela não libera produção e não substitui a revisão independente AppSec/DBA/LGPD nem a avaliação das 545 alterações acumuladas contra `dev`.
