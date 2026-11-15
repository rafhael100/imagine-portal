# Portal SEO F108 — PostgreSQL 18 real / RLS / revogação auditada

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.**
Validação técnica descartável em GitHub Actions, sem VPS,
Search Console, CRM, contas Google ou clientes Imagine.

## Escopo comprovável

A F108 executa as migrações Laravel existentes de estados OAuth,
propriedades, auditoria, candidatos de token e políticas RLS em
PostgreSQL 18 real, iniciado como contêiner temporário no CI.

Um processo PHP usa contas SQL de laboratório com privilégios
**NOSUPERUSER / NOBYPASSRLS** para validar:

- Políticas ENABLE ROW LEVEL SECURITY e FORCE ROW LEVEL SECURITY
  nas tabelas de grants e auditoria
- Tenant sem contexto vê zero linhas
- Duas conexões e transações intercaladas, cada uma isolada em sua
  variável local app.tenant_ref, sem vazamento após COMMIT
- Lookup real via GoogleSearchConsoleTenantGrantGateway, com ator,
  tenant, fingerprint e janela de 900 segundos
- Bloqueio para superuser, transação já aberta, ator errado e
  propriedade domain versus URL-prefix
- Rollback atômico se a inserção da auditoria falhar
- Revogação auditada bem-sucedida invalida só o grant certo,
  recusa replay e não afeta a outra empresa

Todos os dados são sintéticos: sc-domain:example.com,
demo-tenant e other-tenant. Senhas no workflow servem somente
para o serviço descartável; nunca correspondem a credenciais reais.

## Reproduzir

Workflow: .github/workflows/portal-seo-f108-pg18-ci.yml

Matriz PHP 8.3/8.4 com PostgreSQL18 e migrações Laravel reais
sob APP_ENV=testing, CI=true e PORTAL_SEO_F108_PG_CI=1.
A PR exige SHA exata e uma lista restrita de três arquivos.
Nenhum merge/deploy, alteração da VPS, OAuth ou API externa.

## Limitações de segurança

**RLS baseada em variável app.tenant_ref escolhida pela aplicação NÃO
autentica o usuário e NÃO protege contra SQL injection.** Uma conta SQL
capaz de executar queries arbitrárias pode tentar manipular esse custom
GUC; a barreira depende de backend confiável, prevenção de SQLi, separação
de papéis e autorização de sessão/membership independente.

O laboratório comprova sessões intercaladas e rollback na falha de
auditoria, mas **não** prova corrida real entre duas revogações do
mesmo grant sob carga. Testes de concorrência, locks e isolamento,
backups/restauração e rollback na VPS continuam pendentes.

Registro recente de grant tampouco comprova OAuth Google atual.
As flags de publicação, acesso produtivo e exposição ao cliente
continuam falsas. Visitas, Leads e Clientes permanecem indisponíveis
sem analytics e CRM autenticados e atribuição correta.

Próximos gates: AppSec/DBA/LGPD, sessão Portal real com MFA, OAuth
readonly Google por tenant/propriedade, defesa contra SQLi,
observabilidade, staging, backups, rollback e aceite de release.

Referência: Portal SEO F107 https://github.com/rafhael100/imagine-portal/pull/844
