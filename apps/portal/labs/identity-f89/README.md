# PORTAL-F89 — registro estruturado e imutável de eventos sensíveis (PG18)

## Entrega efetiva

A migração `2026_11_10_000006_create_portal_security_event_log.php` cria `portal_security_events`, com sequência gerada no PostgreSQL, `event_id` UUID único, organização, identidade opcional, evento de segurança definido por lista fechada, hash SHA-256 de evidência **sem payload sensível**, revisão de segurança e horário de registro. Ela depende das tabelas de identidade e organização F66.

Eventos permitidos: `identity.revoked`, `membership.revoked`, `organization.suspended`, `session.revoked`, `mfa.reset` e `grant.revoked`. Não há campo para senha, bearer, IP ou segredo MFA. Um evento associado a uma identidade deve corresponder a um vínculo de membership **da mesma organização** via FK composta, evitando associação a outro tenant.

Duas triggers PostgreSQL bloqueiam `UPDATE/DELETE` de linhas e `TRUNCATE` de tabela, retornando SQLSTATE 42501. As triggers não mudam o comportamento de `DROP TABLE`, backup, restore ou superuser capaz de desabilitar triggers. Todas as linhas ficam sob `ENABLE/FORCE RLS` e nenhuma policy de leitura/escrita é criada; uma role sem BYPASSRLS não vê os eventos mesmo que receba GRANT explícito por engano.

## CI PostgreSQL descartável

`.github/workflows/portal-f89-security-audit-pg18-ci.yml` prepara PostgreSQL18 e PHP8.3/8.4, aplica somente F66 e F89, insere identidade e empresas fictícias, faz testes físicos de:

- Evento legítimo de laboratório com sequência e hash corretos;
- Negação de `UPDATE`, `DELETE`, `TRUNCATE` até para o administrador do runner;
- Negação de SELECT e INSERT pela role comum com GRANT e FORCE RLS;
- Negação de evento para identidade ligada a empresa diferente, tipos desconhecidos, hash malformado, revisão zero e ID duplicado;
- Nenhuma policy permissiva, duas triggers presentes e rollback que remove todos os objetos criados no banco descartável.

## Restrições operacionais

Esta migração e os testes **não ativam auditoria para usuários reais**. Ainda não existe um writer de eventos ligado a identidade Portal confiável, custódia do hash, transação obrigatória com o evento original, retenção compatível com LGPD, assinatura de evidência nem repositório externo. Um DBA ou invasor com controle de restore pode voltar o log no tempo; por isso não substitui a testemunha SEC-R03 contra rollback. A prova serve somente como base física para posterior integração após revisões AppSec/DBA/LGPD.

**PR DRAFT. Sem migração em VPS, deploy, concessão de direitos, login, integração comercial, cliente ou despesa.**