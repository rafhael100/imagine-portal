# PORTAL-INT-F162 — Paridade PostgreSQL 18 ↔ Eloquent da finalidade de sessão

A F160 passou a exigir `PortalSessionPurpose::Business` na pré-avaliação das operações de negócio, e a F161 introduziu casting tipado de `session_purpose` e `organization_revision` no modelo Eloquent `PortalAuthSession`. A F113 já mantém o CHECK de finalidade, TTL e imutabilidade no PostgreSQL. Esta etapa une essas partes em **um teste completo de persistência real**, mas só num banco descartável.

## Teste executável

A CI `portal-int-f162-session-purpose-pg18.yml` instala a fundação F66, revisão de organização F76 e finalidade da sessão F113 em PostgreSQL 18 efêmero, com PHP8.3 e 8.4. A suíte `PortalTypedSessionPg18Test`:

- Insere organização, identidade e associação **artificiais**, mais sessões `business`, `invitation_only` e legada com `session_purpose=NULL`;
- Busca as sessões com o **modelo Eloquent de verdade**, confirmando que os dois valores são hidratados como o enum `PortalSessionPurpose`, que `organization_revision` é inteiro e que propósito/revisão legados `NULL` não recebem default permissivo;
- Confirma que o digest de sessão permanece oculto em `toArray()`;
- Exige rejeição SQLSTATE `23514` ao tentar converter uma sessão de convite em negócio, converter `NULL` em negócio, trocar negócio por convite, exceder 1h de convite ou 24h de negócio;
- Confirma a ausência de políticas RLS ALLOW, a inexistência de rotas operacionais e a permanência do `DenyingPortalAuthorities`;
- Remove os dados de laboratório explicitamente e verifica rollback F113/F76/F66 no banco temporário.

## Limitações

A leitura de Eloquent usa **superuser somente em PostgreSQL descartável**, não uma role real de aplicação, e não comprova controles de emissor, MFA, verificação de credenciais, lock serializável, onboarding, acesso do cliente ou Google OAuth. A implementação não conecta o modelo a autenticação operacional; o modelo ainda nega mass assignment e o Portal permanece com a rota de saúde apenas.

**NO-GO:** revisões independentes AppSec/DBA/LGPD, teste de roles de privilégio mínimo, auditoria, fluxo de sessão real, homologação de staging e plano de rollback são obrigatórios antes de qualquer merge operacional ou deploy. Nenhuma mudança de `main`/`dev`, VPS, dados de clientes ou custos foi autorizada.
