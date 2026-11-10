# PORTAL-F67 — modelos Laravel da identidade própria do Portal

Continuação F66, sem habilitar rotas ou autenticação.

Foram implementados cinco modelos Eloquent relacionados às tabelas de identidade PostgreSQL do Portal:

- `PortalIdentity`, que implementa o contrato de credenciais Laravel com `password_hash`, sem compartilhar `App\\Models\\User` com CRM ou outro sistema.
- `PortalOrganization` e `PortalMembership`, com relação de identidade/organização e vínculo explícito.
- `PortalMembershipGrant`, representando somente permissão explícita da membership (papel não autoriza nada sozinho).
- `PortalAuthSession`, com bearer em formato de SHA-256 **oculto** em serialização, além de revisões e datas de MFA/revogação.

## Defesa padrão

Todos os modelos têm `guarded=['*']`, chaves UUID, sem mass assignment público. Campos `password_hash`, `mfa_secret_ciphertext` e `session_sha256` são ocultos de `toArray()/toJson()`.

**Um registro de sessão/membership NÃO autentica nem autoriza por si só.** Não há login, MFA emitido, provider, policy RLS ativa, sessão de cliente ou API. A condição para uso de dados permanece a revisão posterior de confiança e segregação de empresa. A F66 continua com `FORCE RLS` em cinco tabelas e nenhuma policy de acesso.

O teste `PortalIdentityModelsTest` verifica nomes das tabelas, guarda de atributos, proteção dos segredos, chaves estrangeiras de relacionamentos e todas as rotas de login/cliente 404.

PR DRAFT, não executar migração na VPS, sem deploy, sem ativar clientes ou custo novo. Próximo recorte requerido: desenho e teste do resolvedor de identidade Portal-only + MFA e política PostgreSQL revisada, com SEC-R03 anti-rollback externo antes do uso real.
