# PORTAL-F76 — autorização empresarial de leitura com papel PostgreSQL de privilégio mínimo

## Problema resolvido no laboratório

As etapas F66–F75 já verificavam sessões e MFA em PostgreSQL, mas a lógica ainda era operada por superuser do banco **exclusivamente no runner**. Essa abordagem não pode ser reutilizada numa aplicação de clientes.

A F76 acrescenta um **predicado SQL de leitura somente**, `public.portal_f76_permission_check(hash, organization_id, permission)`, executado pelo papel `portal_f76_reader` **sem SELECT em nenhuma tabela, sem BYPASSRLS, sem privilégios de administração e com apenas EXECUTE na função**. O predicado é definido `SECURITY DEFINER`, com `search_path` restrito a `pg_catalog,pg_temp`, tabelas referenciadas com `public.`, `REVOKE EXECUTE FROM PUBLIC` realizado na mesma transação de criação, para evitar a permissão padrão de funções novas.

O PostgreSQL conserva `FORCE ROW LEVEL SECURITY` nas cinco tabelas, **sem novas policies permissivas**. O predicado verifica internamente:

- Formato de hash SHA-256, escopo de empresa UUID e permission code explícito;
- Sessão active, não revogada, já emitida, não expirada e duração de no máximo um dia, com MFA registrado;
- Identidade Portal active, email verificado, MFA cadastrado e mesma revisão de segurança da sessão;
- Organização active e **mesma revisão de segurança da sessão**;
- Membership active com revisão igual à registrada na sessão;
- Concessão explícita da permissão pela membership (papel owner/manager não é superpermissão).

A migração `2026_11_10_000004_add_org_revision_to_portal_sessions.php` cria `organization_revision` **NULL por padrão** para invalidar sessões legadas sem esse vínculo. O gate recusa sessão com valor ausente ou diferente do registro da organização. Alterar a revisão da organização, portanto, revoga sessões antigas na prova SQL. O CI faz rollback tanto dessa coluna quanto das tabelas F66 no PostgreSQL descartável.

## Prova com role NÃO superuser

`.github/workflows/portal-f76-permission-sql-ci.yml` aplica a migração F66 e F76 em PostgreSQL 18 descartável com PHP8.3/8.4. O teste `test_pg_permission_gate.php` confirma a verificação positiva com a role execute-only, e nega: SELECT direto de sessões/identidades/grants, execução da função por role não autorizada, cross-tenant, permission wildcard, email não verificado, MFA ausente, conta/empresa/membership suspensas, revisões defasadas, expiração, emissão futura, token não reconhecido, remoção de grant, SQL injection text e sessões sem `organization_revision`. Verifica `pg_policies=0` e `search_path` fixo.

## Advertências críticas

**O parâmetro `session_hash` é equivalente ao próprio bearer!** Não passar esse hash a clientes, browser, Javascript, terceiros, logs, query params ou RPC pública. Nesta etapa, somente a role fechada de laboratório pode invocar a função. Uma credencial roubada da aplicação que possua `EXECUTE` ainda poderá consultar o predicado. Isso não substitui autenticação de usuário, titularidade/consentimento, rate limiting, controle de segredo de sessão ou análise AppSec.

`SECURITY DEFINER` é sensível e exige revisão de DBA independente, owner dedicado sem superuser na arquitetura final, ensaio com concorrência/TOCTOU, logs/auditoria e limites de acesso. A função fica **somente em `labs/identity-f76/permission_gate.sql`**, não é aplicada por migrations normais nem exposta por HTTP ou providers. Em produção deve ser substituída por implementação auditada sob autoridade confiável e monitoramento de acesso.

Continua pendente: IAM/MFA reais, KMS, rotação, antirollback externo SEC-R03, sessão HTTPS, tratamento de recuperação, LGPD, serviços contratados, integração Sites/Performance/CRM e homologação VPS. **PR DRAFT / sem merge/deploy, dados de clientes ou custo novo.**
