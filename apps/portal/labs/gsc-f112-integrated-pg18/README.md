# Portal GSC F112 — integração do schema REAL de identidade com a cadeia GSC

## O que este laboratório integra

A F110/F111 passou a ter `seo.gsc.view` em enum, constraint
PostgreSQL e leitor de grants de sessão business/MFA. A F108/F109
havia provado atomicidade de sessão/GSC e a separação da permissão
`view`, mas usando uma tabela `portal_membership_grants` de fixture.

A F112 usa, no **mesmo PostgreSQL 18 descartável**, as migrations reais
do Laravel Portal de identidade: `portal_identities`,
`portal_organizations`, `portal_memberships`,
`portal_auth_sessions` e `portal_membership_grants` com `FORCE RLS`.
Aplica as migrations reais (mas só no runner de CI) de
`seo.gsc.connect` e `seo.gsc.view`.

Em paralelo, faz checkout fixado de:
- GSC F109: `de3315df4e8b8c07228d76f6613c799f9c174f31`, com
  migrations GSC F104–F109, função F108 de transação única e leitor PHP
  F109, sem copiá-los para a branch de identidade;
- SEO F104: `ec1739621b20860f729c59d9863ba1b341da43e8`, gerador de
  DTOs sintéticos completos, parciais e vazios.

O script `portal-real-grants-f109-bridge.sql` adapta **apenas a camada
de grant explícito** da F109 ao schema real da identidade. Reaproveita
a função F108 com os locks de sessão, identidade, organização, vínculo
e grant GSC; a função adicional bloqueia `portal_membership_grants`
e `portal_memberships` até COMMIT. O runtime continua exclusivamente
`EXECUTE`, sem SELECT/UPDATE das seis tabelas de identidade/GSC.

O CI testa `connect` sem `view`, `view` explícito em membership
real, duas conexões disputando a exclusão de `view`, recusa após
remoção, empresa diferente, sessão falsa, revisions alteradas,
suspensão e revogação, e limpeza/rollback de todas as migrations.

## Limites

Os registros, a sessão e os grants são **fictícios e emitidos pelo
administrador do banco de laboratório**, não pelo fluxo operacional de
login com senha e MFA. A função F108 confere campos de sessão/MFA e
revision de banco, mas este teste por si só não prova a emissão
criptográfica do bearer. A F111 exercita o autenticador próprio em
outro CI. A revisão de produção precisará unificar os caminhos sem
reusar qualquer prévia como autorização.

Nenhuma permissão Google OAuth atual, propriedade verificada, consentimento
ativo, proteção de tokens, direito de exibição ou chamada externa é
comprovada. Nenhuma rota HTTP, provider ou senha operacional é alterada.
Todas as flags de autorização de cliente ficam negativas.

**Não realizar merge, deploy, migrar VPS, habilitar clientes ou remover
DRAFT antes de revisão externa AppSec/DBA/LGPD, staging, backup e
aprovação explícita.**
