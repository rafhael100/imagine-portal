# Portal F146 — Compatibilidade entre as duas cadeias de revisão Portal e SEO/GSC

As cadeias de desenvolvimento do Portal e do SEO/GSC avançam em paralelo no **mesmo** repositório, mas partem de um ancestral comum distinto da `dev`. A comparação GitHub inicial entre a candidata F145 e a GSC-F142 mostrou que os dois lados haviam divergido. Isso cria risco de conflitos ao tentar consolidá-los.

Esta entrega executa um **teste real de topologia Git**, não uma estimativa por nome de PR:

1. Usa o **SHA real** da candidata Portal em execução na CI, sem depender do commit sintético `refs/pull/.../merge` do GitHub.
2. Busca a branch pública do SEO/GSC somente para leitura e fixa o commit de comparação `500c7cbd2379a1869a52a8ad3133af5c62de830a` (PR #344, snapshot conhecido). Se esse commit deixar de ser alcançável pela branch, a verificação falha.
3. Calcula `git merge-base`, commits exclusivos de cada candidata e **todos** os caminhos alterados desde o ancestral comum; compara a intersecção exata dos arquivos. Caminho compartilhado não é, sozinho, prova de conflito.
4. Executa `git merge-tree --write-tree --name-only --messages` entre os SHAs fixados. Essa operação constrói apenas objetos no banco de objetos **descartável** do runner GitHub Actions, sem checkout do resultado, sem commit de merge, sem push, sem `main` ou `dev`.
5. Um conflito normal da simulação é **evidência a ser revisada**, não erro de infraestrutura. O JSON e Markdown registram os conflitos ou a ausência deles; em ambos os casos `may_merge=false`, `may_deploy=false`, revisão independente obrigatória.
6. O workflow permite somente `contents: read`, sem credenciais persistidas, usa Node 24 e guarda relatório temporário por três dias. Não faz chamadas ao CRM/GSC real, DB, VPS, DNS nem serviços do cliente.

A F146 mede **duas versões específicas**; o SEO/GSC pode continuar evoluindo, e outra execução sobre uma nova versão exigirá atualizar o SHA fixado e repetir o teste. Um merge-tree limpo não garante regras de produto, IAM/MFA, ausência de vazamento por tenant, migrações seguras, performance nem conformidade LGPD. Use a evidência com o inventário F144/F145, [issue de integração #341](https://github.com/rafhael100/imagine-portal/issues/341), [review CRM #315](https://github.com/rafhael100/imagine-portal/issues/315) e [review SEO #251](https://github.com/rafhael100/imagine-portal/issues/251). Continua NO-GO.
