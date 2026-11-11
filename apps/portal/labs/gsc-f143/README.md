# GSC-F143 — Paginação query/page (Node SEO-F59 → PHP)

A CI testa o contrato `seo-gsc-query-page-pagination-offline-v1` com o SEO Engine RC24 fixado no SHA `ab823e074fdd8fdf5de7db27df491b6bea3e29da`. O motor agrupa duas páginas sintéticas e produz somente indicadores de sobreposição; o PHP do Portal verifica as contagens, a continuidade e a presença de possíveis lotes futuros.

O consumidor reusa os controles estritos da classe F141 e valida o envelope de paginação (1–4 páginas, até 4.000 linhas, `nextStartRow`, `mayHaveMore`, status, maxPagesReached). Os testes negativos cobrem dados pessoais, grupos de autorizações falsos, mudança de propriedade, offsets inconsistentes e ambiente de produção.

**HOLD permanente até liberação formal**: não é controller nem route, não consulta Google, não grava tokens e não revela consultas ou URLs. Somente PHP CLI sob `APP_ENV=testing`, `PORTAL_GSC_OVERLAP_LAB=1` e `PORTAL_GSC_PAGINATION_LAB=1`. A conexão no OpenSEO é separada. Sem deploy, DNS, merge `main`, contas reais em CI ou gastos novos. Revisão AppSec/DBA/LGPD, IAM real e staging ainda necessários.
