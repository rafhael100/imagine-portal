# GSC-F142 — Painel offline para indícios de sobreposição query/página

A prévia HTML F142 consome o contrato agregado e sanitizado do SEO Engine F57 via validador PHP F141. Apresenta contadores de consultas distintas, consultas associadas a várias páginas, linhas ocultadas por privacidade e impressões, sempre com indicação explícita de demonstração.

A página tem `noindex,nofollow`, CSP sem rede, nenhum JavaScript, hyperlink, formulário ou operação de escrita. Só roda em PHP CLI com `APP_ENV=testing`, `PORTAL_GSC_OVERLAP_LAB=1` e `PORTAL_GSC_OVERLAP_HTML_LAB=1`, em arquivo local privado. O teste de CI produz a saída com o **próprio código SEO RC23 fixado por SHA**, e verifica formato e ausência de elementos interativos.

O resultado não autoriza modificar conteúdos, canonical ou redirecionamentos. Uma mesma consulta em várias páginas não comprova erro. **NO-GO:** ainda faltam OAuth/consentimento/tenant IAM do Portal, revisão SEG/DBA/LGPD, homologação e aprovação de lançamento. Sem dados reais em Git/CI, DNS, deploy ou merge `main`.
