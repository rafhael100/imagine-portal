# PORTAL-INT-F161 — Sessão tipada na persistência Laravel

## Contexto

A F160 exige propósito explícito `PortalSessionPurpose::Business` no contrato de pré-autorização, impedindo uso de `invitation_only` em serviços, revisão comercial e pré-conexão SEO/GSC. A migração F113 já restringe os valores persistidos. Porém, `PortalAuthSession`, o modelo Eloquent de leitura da tabela, ainda não aplicava cast tipado a `session_purpose` nem à `organization_revision` adicionada pela F76.

## Implementação

- `PortalAuthSession::casts()` agora converte `session_purpose` exclusivamente para `PortalSessionPurpose` (enum fechado) e `organization_revision` para inteiro.
- O comportamento de compatibilidade é **falhar fechado**: propósito legado `NULL` e revisão antiga `NULL` permanecem nulos, sem fallback para `business`. Um valor fora do enum não se transforma silenciosamente em negócio.
- Testes Eloquent em memória verificam tipagem `business`, `invitation_only`, leitura de revisão inteira, rejeição de valores estranhos e manutenção do digest do token em `hidden`.
- O modelo continua com `guarded=['*']`, sem permitir mass assignment de emissor; nenhuma configuração de autenticação real foi introduzida.

Esses testes **não usam PostgreSQL** nem provam leitura com RLS, issuer oficial ou geração de credenciais. O esquema PostgreSQL F113 e seu trigger imutável continuam a fonte da restrição persistida. Um futuro adaptador de autoridade terá de validar sessão e associação/organização atual sob controle transacional e autorização independente.

**NO-GO:** nenhuma API, login, token real, OAuth, GRANT, RLS ALLOW, migração, deploy ou alteração em `dev`/`main`; revisão AppSec/DBA/LGPD segue obrigatória.
