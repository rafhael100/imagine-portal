# PORTAL-F68 — Auditoria de limites do CSV demonstrativo (10/11/2026)

## Objetivo
Expandir o contrato de testes F64/F66/F67 de um cenário único para cenários sintéticos simultâneos, validando a reconciliação monetária por linha e por cenário. F68 não implementa um endpoint, dashboard autenticado ou operação publicitária.

## Casos automatizados
- Zero cenários e exportação válida apenas com cabeçalho.
- Um centavo, dias isolados e divisões Google/Meta de 0% e 100%.
- Ano bissexto 2028 (366 dias) e arredondamento por quotas cumulativas inteiras.
- Lote de 20 cenários distintos, com soma independente de centavos Google/Meta/total e CSV canônico em UTF-8 BOM + CRLF + ponto e vírgula.
- Negativos: 21 cenários, referência duplicada, tenant não esperado, data alterada, centavo adulterado, split inconsistente, flag de mídia ativa, APP_ENV de produção e laboratório desabilitado.
- Falha fechada: entrada negada não emite CSV parcial.

## Dependências e execução
A suíte `apps/portal/tests/ui/test-f68-multi-scenario-budget-csv.mjs` executa `apps/portal/tools/f66-export-budget-calendar-csv.php` apenas via CLI de testes com `APP_ENV=testing`, `PORTAL_F64_BUDGET_PREVIEW_LAB=1` e `PORTAL_F66_BUDGET_CSV_LAB=1`.

O workflow `.github/workflows/portal-f68-budget-csv-boundaries-ci.yml` deve concluir quatro combinações PHP 8.3/8.4 × Node 22/24, além das regressões F66 e F67. **Não presumir aprovação antes de conferir todos os jobs da HEAD exata.**

## Segurança / NO-GO
Dados exclusivamente sintéticos. F68 não concede permissão de compra de mídia, execução, cobrança, autenticação, conexão Google/Meta, uso de contas, liberação de VPS ou aprovação humana. A futura integração real exige IAM operacional, consentimentos e contratos, proteção de segredos, isolamento por empresa, AppSec/DBA/LGPD, witness antirollback SEC-R03 e homologação independente.

Não realizar merge na main, deploy, alteração de DNS ou novos gastos com base nestes testes.
