# Portal F144 — Inventário completo e automático do diff de integração

O Portal acumulou uma cadeia extensa de PRs em `review/*`. Em 11/11/2026, a comparação GitHub do topo da cadeia F142 com `dev/fast-track-portal-2026-11-05` indicou **700 commits à frente**. A resposta de comparação apresentou somente as primeiras 300 entradas de arquivos, insuficientes para inventário completo.

A F144 cria uma ferramenta de CI **somente leitura** que usa o histórico Git local no GitHub Actions, sem API de permissões privilegiadas:

1. Check-out do próprio SHA de revisão e busca explícita de `origin/dev/fast-track-portal-2026-11-05`, com `fetch-depth:0`.
2. Calcula o ancestral comum, commits apenas em cada lado (`git rev-list --left-right --count`) e **todos os nomes de arquivos modificados** via `git diff --name-status -z --find-renames`. Essa leitura não está limitada aos 300 arquivos do endpoint de comparação.
3. Classifica paths por categorias para revisão de código: identidade/segurança, migrações, domínio, código de execução, apresentação, laboratórios isolados, testes, workflows, docs e demais arquivos.
4. Gera `inventory.json` com nomes/estados de arquivos e `review-summary.md` com contagens por área, além de sumarizar no job. Não exporta conteúdo dos arquivos nem segredos.
5. O relatório marca invariavelmente `integration_approved=false`, `may_merge=false`, `may_deploy=false` e requer revisão AppSec/DBA independente e homologação. Ele serve para priorizar lotes, **não é aprovação**.
6. Testes Node cobrem sintaxe de diff NUL-delimitada, renomeações, arquivos truncados e inválidos, classificação de risco e proibição de sinalização de release.

O workflow `.github/workflows/portal-f144-integration-inventory.yml` usa apenas GitHub Actions público, Node24, `contents: read` e artefato efêmero com retenção de três dias. Sem execução na VPS, credenciais, acesso a clientes, rede de serviços, migração ou merge. A execução do relatório é repetível na revisão; ela não altera a branch `dev`.

**Próximo passo obrigatório:** usar esse inventário para decompor/fechar dependências de PRs por módulo e obter decisões de integração e revisão, conforme [PORTAL-INT-01 #341](https://github.com/rafhael100/imagine-portal/issues/341). Não aplicar o resultado automaticamente à `main` ou produção.
