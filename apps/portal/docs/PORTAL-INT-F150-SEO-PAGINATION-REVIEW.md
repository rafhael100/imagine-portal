# PORTAL-INT-F150 — Atualizar a candidata integrada com o SEO/GSC mais recente

A candidata de integração [F149 #356](https://github.com/rafhael100/imagine-portal/pull/356) consolidou Portal + SEO/GSC até o teste restrito de permissões GSC-F143. Entretanto, a linha paralela SEO/GSC avançou até [GSC-F148 #355](https://github.com/rafhael100/imagine-portal/pull/355), acrescentando tratamento de paginação de consultas e páginas. Esta entrega incorpora **essa evolução posterior sem descartar o ajuste de compatibilidade de permissões**.

## Proveniência congelada da segunda integração

- Ancestral das linhas nesta atualização: `500c7cbd2379a1869a52a8ad3133af5c62de830a` (GSC-F142).
- Parent Portal: `1427a486434f653e6873ea6f3b37294d781aa316` (F149, com GSC-F143 já integrado).
- Parent GSC: `4c081658b3f740415072c780d8770a676d41c455` (GSC-F148).
- Commit de merge **somente revisão**: `e655c3f8d61debce89920bd22c1ed28878e1d501`.
- Árvore de código integrada: `94c5bf76c1161927ec213bec38abfb0a98892799`.

A comparação de todas as árvores Git retornou **15 paths exclusivos do GSC**, sem arquivo alterado de forma diferente pelos dois lados desde o ancestral comum. Esses caminhos foram incorporados sobre a árvore Portal e publicados apenas em uma branch `review/*`, **não** em `dev`, `main` ou produção.

## Provas automatizadas

A CI `portal-int-f150-latest-seo-ci.yml` valida o commit de dois pais, exige que `git merge-tree` reproduza exatamente a árvore esperada, rejeita alteração adicional de código de execução na branch após a composição e comprova a presença de todos os contratos de paginação GSC recentes. Repete a instalação imutável de dependências, testes Node24 e PHPUnit Laravel8.4 sobre **a mesma árvore reunida**, e exige que continue existindo somente a rota de healthcheck.

As provas anteriores de [F149 #356](https://github.com/rafhael100/imagine-portal/pull/356) — 29/29 checks, 478 testes Laravel — pertencem à **árvore anterior**. Os resultados desta nova candidata só serão considerados concluídos quando sua própria CI confirmar sucesso. Nenhum resultado da versão anterior substitui a nova execução.

**Gate ainda NO-GO:** enum com ambas as permissões não é concessão; dados sintéticos não são homologação. Dependências de revisão independente AppSec/DBA/LGPD, sessão real, token OAuth/GSC, isolamento RLS/tenant, integração CRM, observabilidade, rollback e ambiente de homologação continuam abertas nas issues [#315](https://github.com/rafhael100/imagine-portal/issues/315), [#251](https://github.com/rafhael100/imagine-portal/issues/251) e [#341](https://github.com/rafhael100/imagine-portal/issues/341). Sem deploy, gastos ou alterações de `main/dev`.
