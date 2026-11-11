# PORTAL-INT-F160 — Separar sessão de convite de sessão de negócio

## Problema de segurança do contrato

O PostgreSQL Portal já prevê, na migração F113, `portal_auth_sessions.session_purpose` com **apenas dois códigos**: `business` e `invitation_only`. Sessões antigas têm `NULL` e devem falhar no futuro. A mesma migração impede mudar o propósito de uma sessão depois da emissão e limita `business` a 24h e `invitation_only` a 1h.

Porém, `PortalSessionSnapshot` — o DTO sintético usado para avaliar o acesso às prévias comerciais, SEO/GSC e serviços — ainda não carregava o propósito. Assim, um teste de fonte falsa poderia oferecer uma sessão destinada apenas a convites para pré-validar operações de negócio se as outras condições fossem atendidas. O resultado sintético jamais autorizou HTTP real, mas **a distinção de propósito precisa constar no contrato antes de conectar um emissor verdadeiro**.

## Correção

- `PortalSessionPurpose` é um enum tipado e fechado: `Business = 'business'`, `InvitationOnly = 'invitation_only'`, sem wildcards.
- `PortalSessionSnapshot` **exige** um valor de propósito sem default, para não classificar automaticamente sessões legadas como negócio.
- `passesSyntheticFreshnessChecks` só aceita `Business` e duração máxima **24h**, além de identidade ativa, MFA, validade temporal e revisão da conta.
- Testes das quatro superfícies já existentes — domínio de autorização, revisão comercial, pré-conexão GSC e leitura PostgreSQL sintética F26 — agora fornecem propósito explícito, comprovam negação de `InvitationOnly`, verificam teto de 24h e conferem paridade de fonte com a migração F113.
- A cadeia F152–F156 continua exigindo mesma empresa, associação com revisão atual e organização ativa com revisão atual.

## Restrições

Este contrato **não introduz login, convite real, emissão de sessão, cookies, OAuth ou acesso de cliente**. `AppServiceProvider` continua com `DenyingPortalAuthorities` nas quatro fontes (sessão, associação, recurso e organização), e a aplicação mantém somente a rota de healthcheck.

**NO-GO:** a nova verificação de propósito é sintética, sem testemunho transacional de banco/issuer. A futura integração requer revisão AppSec/DBA/LGPD independente, teste de sessões herdadas NULL, issuer confiável, rotação de sessão, MFA e homologação autorizada. Nenhuma migração, alteração na VPS, produção, `dev` ou `main`.
