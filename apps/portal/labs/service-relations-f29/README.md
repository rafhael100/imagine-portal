# PORTAL-F29 — Deriva negativa de políticas RLS, privilégios e roles (PostgreSQL 18)

**09/11/2026. Exclusivamente laboratório descartável; sem IAM real, merge ou deploy.**

## Motivação

F23 conferia se as tabelas mantinham `ENABLE/FORCE ROW LEVEL SECURITY`
e se a visão de serviços mantinha `security_invoker=true`; F28 conferia
gatilhos de escrita necessários à barreira F25. Isso ainda permitia,
em tese, que um operador de banco modificasse o conteúdo de
`USING(...)`, acrescentasse políticas permissivas ou ampliasse grants
mantendo essas configurações aparentemente corretas.

F29 acrescenta um controle **negativo de deriva da política fictícia**,
antes da leitura assinada F24 e ainda dentro da mesma transação
`REPEATABLE READ, READ ONLY` sob a trava F25.

## Componentes

`app/Labs/F29/F29SyntheticRlsPolicyInspector.php` consulta
`pg_catalog.pg_policy`, `pg_class`, `pg_namespace`, `pg_roles`,
`pg_auth_members` e ACLs SQL:

- Exige exatamente as 10 policies SELECT do laboratório (6 em F21 e
  4 em F24), com nomes, tabelas e papéis de teste esperados.
- Exige políticas PERMISSIVE somente SELECT, sem `WITH CHECK` e
  com a predicação normalizada exata das fixtures:
  `organization_id = 'org-a/b' AND session_user = 'f21_org_a/b'`.
  Uma mudança para `USING (TRUE)`, inclusão de `OR TRUE` ou
  novo papel/escopo deve causar DENY.
- Exige ACLs das seis relações de teste (três tabelas F21, sua view,
  e duas tabelas F24) somente SELECT às duas roles previstas;
  nenhuma concessão de INSERT/UPDATE/DELETE, PUBLIC ou outra role.
- Exige `f21_org_a` e `f21_org_b` com LOGIN, NOINHERIT,
  sem SUPERUSER e BYPASSRLS, e sem herança/membership entre
  quaisquer papéis.
- Em qualquer desvio ou falha de consulta, bloqueia a leitura F24 com
  `DemoServiceRelationPage::blocked('unsafe_rls_policy_contract')`.
  O resultado não contém linhas, totais, cursores ou direitos.

## Testes

`tests/Integration/F29RlsPolicyGrantDriftTest.php` faz dez ensaios
em PostgreSQL 18 de container local e efêmero:

1. Baseline das duas empresas e leitura ainda não autoritativa;
2. Trocar USING em serviço por TRUE;
3. Criar outra policy permissiva;
4. Trocar USING em sessão por TRUE;
5. Expandir role de policy de membership para PUBLIC;
6. Apagar policy SELECT de solicitação;
7. Conceder INSERT a role de leitura;
8. Conceder SELECT ao PUBLIC sobre tabela de sessão;
9. Tornar role de laboratório INHERIT;
10. Criar membership cruzada de roles.

A conta administrativa modifica **somente o banco descartável**,
cada teste exige bloqueio e restaura o estado em `finally`.
As validações são adicionadas ao CI PG18 de PHP 8.3 e 8.4, junto
das etapas F22–F28, sem banco do cliente, credenciais persistentes,
VPS, Cloudflare ou SSO CRM.

## Limitações e bloqueios

- Normalização textual de `pg_get_expr` para predicates das fixtures
  não é prova semântica geral de SQL. O mecanismo não é adequado para
  auditar políticas reais complexas, uso de funções, tipos, subconsultas
  ou grants herdados.
- Não há autoridade independente: quem controla simultaneamente
  banco e artefatos versionados pode fraudar as duas faces.
- Isto não certifica a definição SQL inteira da view, funções
  SECURITY DEFINER externas, criação de objetos, grants em
  outros schemas, conexões por pool, autenticação ou usuários reais.
- Não resolve revogação entre serviços e ambientes, anti-rollback
  SEC-R03/Z4, política global de identidades, autenticação/MFA
  operacional, AppSec/DBA, LGPD ou auditoria completa.
- `SyntheticPgServiceReadAdapter` sem prova F24 permanece somente
  como classe de laboratório, sem rota, provider ou API.
  F29 só é exigido no caminho de prova assinada F24.
- Qualquer merge em `dev/main`, migração, deploy ou abertura de
  endpoint de cliente permanece bloqueado até aprovação específica.

**PR DRAFT, filho do F28.** P0, SEC-R03, D02-SEC, D03-RLS,
T3/OPEN e aprovações independentes permanecem abertos.
