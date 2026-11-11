# GSC-F161 — Inventário integral de integração, sem merge

A análise GitHub por API da cadeia `dev/fast-track-portal-2026-11-05` → revisão SEO pode retornar somente 300 caminhos mesmo que haja mais alterações. A F161 executa um **inventário completo no CI a partir de Git local**:

- Checkout isolado em `.qa/portal-inventory` com `fetch-depth: 0`, `contents: read`, credenciais não persistidas e commit `head.sha` exato do PR.
- Compara o HEAD **(1)** com a branch `dev/fast-track-portal-2026-11-05` e **(2)** com o `base.sha` do próprio PR. Exige ancestralidade real e contagem consistente de commits.
- Usa `git diff --no-renames --name-status -z` para listar **todos** os caminhos, incluindo nomes com caracteres especiais, sem o limite da API. A classificação de risco por nome (identidade, banco, pagamentos, integrações, CI, UI) é indicativa e não representa auditoria AppSec.
- Arquivo `full-diff.json` lista os caminhos e seus status/categorias; `summary.md` traz totais e SHAs. São artefatos de revisão retidos por dois dias e resumidos no GitHub Actions.
- Testes de regressão incluem 351 caminhos (acima do teto REST), categorias sensíveis, itens repetidos e lista fora dos limites.

**NO-GO obrigatório:** o inventário não lê dados de clientes, não aplica diffs, não concede direitos, não executa migrações/rollback, não mescla branches e não autoriza deploy. A promoção segura exige revisão real de cada lote, resolução de conflitos, AppSec/DBA/LGPD independentes e homologação de ambiente. Consulte [integração PORTAL-INT-01](https://github.com/rafhael100/imagine-portal/issues/341).
