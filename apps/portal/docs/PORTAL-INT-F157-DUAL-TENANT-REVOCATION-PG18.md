# PORTAL-INT-F157 — Revogação independente entre duas empresas (PostgreSQL 18)

## Objetivo

A F153/F154 invalidou sessões após mudanças da associação ou de permissões. A F155/F156 reforçou o estado e a revisão de segurança da empresa. É indispensável garantir que **alterar a organização A não cause alteração silenciosa de revisão ou autorização na organização B**, mesmo quando a mesma pessoa pertença a ambas.

## Prova executável em PostgreSQL 18 descartável

O laboratório `labs/identity-f157/test_pg_dual_tenant_revision.php` cria **uma identidade sintética, duas organizações distintas A/B, duas associações e duas sessões não operacionais**, cada uma capturando revisão da associação e da organização igual a 1.

A CI `portal-int-f157-dual-tenant-revocation-pg18.yml` aplica as migrações F66, F76, F154, F156 em banco PostgreSQL18 temporário e PHP 8.3/8.4, validando:

- Inserir grant explícito em A incrementa **apenas a revisão de associação de A**;
- Suspender a organização A incrementa **apenas a revisão de organização de A**;
- A sessão antiga de A passa a apresentar revisões incompatíveis e organização suspensa;
- A sessão de B continua com revisões 1, empresa ativa e vínculo não modificado;
- Suspender a associação de B invalida **apenas** a revisão de associação de B, sem interferir em A;
- FORCE RLS segue ativo nas quatro tabelas e não existe política de aplicação liberando acesso;
- A tentativa de downgrade das proteções F154/F156 com registros existentes deve falhar; o laboratório remove somente seus registros antes de testar o rollback completo.

A verificação compara propriedades dos registros de banco sintético sob superuser de laboratório; ela **não autentica um usuário nem comprova privilégio de acesso**. A leitura protegida com papéis sem BYPASSRLS já é objeto de F154/F156, e testes de identidade real, bloqueios concorrentes e auditoria ainda exigem revisão específica.

## Não é liberação operacional

Sem OAuth real, CRM, SSO, emissão de tokens, sessão de cliente, políticas RLS ALLOW, dados reais, migração na VPS, merge na `dev` ou `main`. O resultado permanece **NO-GO** até revisão independente AppSec/DBA/LGPD e homologação em staging autorizado.
