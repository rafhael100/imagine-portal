# GSC-F145 — Tela de sobreposição com contexto de paginação

A prévia HTML já existente da F142 agora aceita tanto o contrato básico SEO-F57 quanto o contrato paginado SEO-F59, sem duplicar renderer nem criar rota HTTP. O DTO paginado passa obrigatoriamente pelo validador PHP F143 antes de reutilizar os indicadores agregados F141.

Quando o relatório possui paginação, a tela exibe lotes processados, linhas recebidas e se a última página pode indicar mais resultados. Nenhum offset/token é exposto, nenhum dado pessoal ou query URL entra no HTML. A CI usa RC24 por SHA fixo e testa os dois contratos com fixtures sintéticas, CSP e noindex, sem scripts, links ou formulários.

O recurso permanece restrito a CLI em `APP_ENV=testing`, `PORTAL_GSC_OVERLAP_LAB=1`, `PORTAL_GSC_OVERLAP_HTML_LAB=1`; para dados paginados exige adicionalmente `PORTAL_GSC_PAGINATION_LAB=1`. Não existe acesso Google, OAuth operacional, deploy, DNS, dados reais em Git, merge na main ou alterações de cliente. Revisão independente e homologação continuam pendentes.
