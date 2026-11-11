# PORTAL-INT-F163 — Impedir reescrita da identidade e do histórico de uma sessão

## Lacuna identificada na revisão da persistência F162

A migração F113 proíbe mudar o `session_purpose` depois da emissão. A F76 vincula a sessão à revisão de segurança da organização, enquanto F152–F160 verificam tenant, revisão e propósito no domínio. Entretanto, a tabela original F66 **ainda permite SQL UPDATE** em outras colunas críticas: hash do token, identidade, empresa, revisões observadas, data de emissão e vencimento. Uma futura role com permissão de escrita poderia, sem controle adicional, reescrever evidência histórica de uma sessão já emitida — inclusive uma sessão legada com revisão da empresa `NULL` — em vez de revogar e criar outra sessão.

Isso é uma **ameaça futura**: as tabelas ainda têm FORCE RLS sem policies ALLOW e o Portal não possui emissor ou sessão de cliente operacional.

## Migração candidata e regras

A migração de revisão `2026_11_11_000014_guard_portal_session_issuance.php` adiciona um trigger SECURITY INVOKER `portal_f163_session_issuance_guard` sobre `portal_auth_sessions`:

- Em qualquer UPDATE, proíbe alterar `id`, `session_sha256`, `identity_id`, `organization_id`, `identity_revision`, `membership_revision`, `organization_revision`, `session_purpose`, `issued_at` ou `expires_at`. Mesmo `NULL → 1` para uma revisão legada é negado.
- Permite somente transições de status: `pending_mfa → active`, `pending_mfa → revoked` ou `active → revoked`, além de atualização inócua com estado inalterado. Estado `revoked` é terminal.
- A coluna `mfa_verified_at` pode ser preenchida **uma vez**, na transição pendente → ativa, entre emissão e expiração. Não pode ser modificada ou apagada depois.
- `revoked_at` pode ser preenchida **uma vez** durante a transição para `revoked`, não antes da emissão; não pode ser alterada após revogação.
- Reutiliza os CHECKs F66/F113 para consistência temporal e entre status/MFA/finalidade; não cria grants, políticas RLS, roles ou credenciais de cliente.
- Rollback recusa retirar o trigger quando existirem sessões, evitando eliminar essa defesa por um downgrade não auditado.

**Limitação importante:** o trigger **não comprova que a pessoa realmente passou por MFA**, nem valida assinatura, emissão ou revogação externa. Sem uma fonte de sessão oficial e revisada, a transição do registro permanece não operacional.

## Prova PostgreSQL 18 e segurança

A CI `portal-int-f163-session-immutability-pg18.yml` roda em PHP 8.3 e 8.4 com PostgreSQL18 efêmero, aplica F66 → F76 → F113 → F163 e executa `labs/identity-f163/test_pg_session_issuance_immutability.php`.

O teste usa somente dados sintéticos e comprova bloqueio contra reatribuição de usuário/empresa, troca de bearer hash, alteração de revisões, manipulação de horários, conversão de sessão `NULL` e reativação de sessão revogada. Verifica a transição normal pendente→ativa→revogada, sem aceitar substituição dos registros de MFA/revogação. A CI exige FORCE RLS, ausência de policies, funções sem `SECURITY DEFINER`, rollback recusado com registros, ausência de leitura por uma role sem BYPASSRLS e reversão completa depois da limpeza explícita do laboratório.

**NO-GO:** ainda exigimos revisão independente AppSec/DBA/LGPD, staging com roles mínimas, issuer e MFA reais, registro auditável de sessões/revogação, avaliação de retenção e backup. Não houve merge em `dev`/`main`, publicação, VPS, OAuth ou novos gastos.
