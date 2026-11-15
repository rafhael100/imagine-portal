# Portal F137 — persistência de sessões e pré-implantação administrativa segura

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Continuação de [F136 #884](https://github.com/rafhael100/imagine-portal/pull/884).

## Entrega

1. Migration `2026_11_15_000015_create_portal_sessions.php`: tabela padrão `sessions` do Laravel em PostgreSQL, compatível com o driver de sessão `database`, com ID único, payload, tempo de atividade e metadados mínimos.
2. `PortalAdminStagingSecurityPolicy` verifica políticas obrigatórias para futura homologação:
   - Somente ambiente `staging` (fora da CI), jamais produção por ativação acidental.
   - `PORTAL_ADMIN_AUTH_ENABLED=true` apenas no staging autorizado; rotas seguem ausentes quando a flag está desligada.
   - `APP_DEBUG=false`, `APP_URL=https://...`, chave Laravel `base64:` preenchida.
   - `DB_CONNECTION=pgsql`, `SESSION_DRIVER=database`, `SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true` e `SESSION_SAME_SITE=lax` ou `strict`.
3. `PortalAdminFeatureGate` passa a usar essa política para qualquer acesso HTTP fora dos testes sintéticos. Em produção ou desenvolvimento local a rota administrativa retorna 503 mesmo se alguém alterar a flag. Não libera dados de clientes.
4. `php artisan portal-admin:staging-preflight`: comando **não destrutivo** de checagem de configuração e presença de migrations `sessions`, `portal_admin_users`, `portal_admin_security_events`, `portal_admin_org_grants` e `session_version`. Recusa produção e execução local não autorizada. Só produz mensagens de status com códigos finitos, nunca valores de chaves, senhas, conexões ou IDs.
5. Testes PG18 reais verificam tabela de sessões, leitura/escrita de fixture sintética e comportamento positivo/negativo para HTTP inseguro, sessões em memória, payload não criptografado, cookies sem Secure/HttpOnly, SameSite permissivo, debug ou chave ausente.

## Exemplo mínimo de parâmetros para homologação (sem segredos)

```ini
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://SEU-DOMINIO-DE-HOMOLOGACAO
DB_CONNECTION=pgsql
SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
PORTAL_ADMIN_AUTH_ENABLED=false
PORTAL_ADMIN_PROVISION_ENABLED=false
```

As duas últimas flags ficam **false** inicialmente. A futura ativação para testar login administrativo deve ocorrer somente após configuração real de banco, TLS, conta provisionada, proteção de APP_KEY e checklist revisado, com uso transitório restrito à homologação.

## Bloqueios operacionais que continuam

A CI valida apenas configurações, schemas e requisições Laravel em ambiente descartável. Ela **não comprova** TLS real, balanceamento/cookies entre processos, firewall, proteção de aplicação contra SQL arbitrário, backups/restauração, conexões sob role sem BYPASSRLS, emissão de identidade e MFA real, LGPD/AppSec, central de logs, autorizações comerciais ou disponibilidade da VPS Leaseweb.

A migration foi escrita na branch e **não aplicada à VPS**. O projeto continua sem deploy, sem contas reais e sem acesso a GSC ou CRM.

**ReleaseStatus: HOLD_VPS_STAGING_AUTH_APPSEC_DB_SESSION_VALIDATION.**
