# Portal F145 — Mapa de risco por commit do inventário de integração

A F144 passou a comparar o **SHA real** do candidato contra `dev/fast-track-portal-2026-11-05`, sem ser enganada pelo commit temporário `refs/pull/.../merge` gerado pelo GitHub. Ela produz um inventário integral de caminhos e dez pacotes de revisão por área. A F145 vai além: procura quais **commits concretos** exigem inspeção prioritária, sem que uma mudança que atravesse áreas fique escondida em um pacote de arquivos.

## O que a CI calcula

- `git rev-list --reverse --topo-order base..HEAD` para percorrer todos os commits que pertencem à candidata e não à `dev`.
- `git diff-tree --name-status -r -z --first-parent --find-renames` por commit, para encontrar exatamente os arquivos alterados **em relação ao primeiro pai**. Em merge commits, esse critério mede a contribuição integrada sobre o primeiro pai e não duplica mudanças dos demais pais.
- Classifica por áreas: migração PostgreSQL, identidade/segurança, runtime, CI, domínio, apresentação, testes, labs, docs e arquivos não enquadrados. Alterações de IAM/migration/runtime/CI/outros são **sinalizadas para revisão prioritária**; domínio demanda revisão de negócio. Alterar testes no mesmo commit é apenas uma informação, não substitui execução de testes de toda a PR.
- Marca merges e commits com mudanças em várias áreas, para identificar dependências e pontos de maior risco de integração.
- Produz `commit-review.json` com **todos os commits** e caminhos e `commit-review.md` com totais e os primeiros 60 commits de revisão prioritária. Os caminhos são dados, nunca argumentos de shell; o processo usa Git local, não a API paginada do GitHub.
- A ferramenta mantém `approved=false`, `may_merge=false`, `may_deploy=false` em **todo** o conjunto, mesmo quando os testes passam. A CI apenas emite evidências para [PORTAL-INT-01 #341](https://github.com/rafhael100/imagine-portal/issues/341).

## Segurança e limites

O script só roda em GitHub Actions com `PORTAL_EXPECTED_HEAD` exato; não lê segredos, não executa código dos commits, não faz checkout de PRs externos, não altera remotes, servidores, banco ou permissões. Os relatórios são arquivados com retenção curta para revisão independente.

A classificação não julga qualidade do código, não considera que um commit sem testes no próprio commit não tenha testes em outros lugares e não é parecer de segurança. *Merge commit* e *commit de alto risco* são marcadores de triagem, não erros automáticos. A análise não resolve conflitos nem autoriza integração, deploy ou produção.
