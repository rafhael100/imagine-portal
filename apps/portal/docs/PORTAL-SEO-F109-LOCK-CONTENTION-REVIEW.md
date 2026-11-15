# Portal SEO F109 — prova de concorrência na revogação GSC (PostgreSQL 18)

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY**. Continuação estritamente
descartável da [F108](https://github.com/rafhael100/imagine-portal/pull/845).

## Objetivo

A F108 comprovou RLS e rollback de auditoria em PostgreSQL18 real. A
F109 acrescenta um teste de **contenção real de bloqueio de linha**,
com duas conexões PHP independentes e roles SQL sem BYPASSRLS:

1. Inicializa os dados sintéticos e a schema existente executando a
   suíte F108 no mesmo contêiner PostgreSQL18 do GitHub Actions.
2. A conexão A abre transação escopada em other-tenant e aplica
   SELECT FOR UPDATE à propriedade sc-domain:other.example.
3. A conexão B tenta, simultaneamente, a operação real
   revokeRecordedGrantWithAudit com lock_timeout de 500ms.
4. A operação B deve ser recusada e a transação revertida; sem
   atualização de grant e sem registro de auditoria parcial.
5. Após A liberar o lock via COMMIT, B deve conseguir realizar a
   revogação auditada **exatamente uma vez**. Repetições são recusadas.
6. A outra propriedade do mesmo tenant, a propriedade de outra empresa
   e os registros da auditoria não podem ser contaminados.
7. Após COMMIT/ROLLBACK, ambas as conexões retornam zero linhas
   sem contexto local do tenant.

## Reprodução

Arquivo de CI:
.github/workflows/portal-seo-f109-pg18-ci.yml

PHP 8.3 e 8.4, PostgreSQL18, migrações reais Laravel e verificador F108,
seguido de verify-locks.php. Gated por APP_ENV=testing, CI=true,
banco descartável com nome fixo, PORTAL_SEO_F108_PG_CI=1 e
PORTAL_SEO_F109_PG_CI=1.

**A prova verifica conflito em linha e retry após liberação, mas
não é teste de carga nem um mecanismo completo de serialização.**
Permanece necessário avaliar locks, pool, SQL injection e RLS sob
cenários reais de homologação, backup/restauração na VPS, MFA,
OAuth Google readonly por tenant e revisão AppSec/DBA/LGPD.

Dados, empresas e senhas são sintéticos, sem contas reais.
Nenhuma rota HTTP, deploy ou autorização de exibir dados ao cliente.
