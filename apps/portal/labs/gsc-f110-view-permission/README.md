# GSC F110 — permissão tipada de leitura e migração reversível no Portal

**PR candidata de engenharia, não é liberação de produção.**

Esta etapa implementa no ramo da identidade Laravel (e não no ramo
do motor GSC) a permissão separada `seo.gsc.view`.

## Alterações

- `Permission::ViewGoogleSearchConsoleMetrics` representa apenas o
  vocabulário explícito de **leitura**. `seo.gsc.connect` continua
  independente, e nenhuma delas é concedida por padrão.
- Migration `2026_11_14_000019_allow_seo_gsc_view_grant.php` amplia
  a constraint `portal_grant_permission_ck`, preservando as permissões
  existentes, sem `INSERT`, sem `GRANT` SQL, sem políticas RLS novas
  e sem ativar rotas.
- O `down()` verifica se há algum grant `seo.gsc.view` e **recusa
  rollback** até que os grants sejam explicitamente revogados e
  auditados. Para evitar falsa contagem zero causada por `FORCE RLS`,
  exige que o papel da migração tenha visibilidade privilegiada global
  (`rolsuper` ou `rolbypassrls`); isso não autoriza aplicar essa
  migração em produção sem revisão.
- Testes de autorização pura verificam ausência de permissão, que
  `seo.gsc.connect` **não implica** leitura, que `view` não implica
  conexão, isolamento por empresa/usuário e associação suspensa/revogada.
- Teste PostgreSQL 18 descartável executa migrações reais, recusa
  código desconhecido, wildcard e grant duplicado, comprova bloqueio
  do rollback com view ativa, revoga a fixture sintética e reverte as
  migrations sem perder a permissão de conexão.

## Próxima etapa

A função PostgreSQL atual
`portal_browser_permission_preflight` na branch de identidade possui
allowlist de quatro serviços: mesmo que a migration aceite
`seo.gsc.view`, esse preflight **não libera** a permissão nova.
A F111 deverá criar ou revisar um preflight de leitura com
allowlist restrita, finalidade business, MFA, versões atuais e
revogação auditável, **sem transformar preflight em autorização HTTP**.

Não confundir permissão persistida com OAuth Google próprio do Portal,
consentimento, direito de exibição do cliente ou consulta atual à
propriedade. Não criar ingress/callback, grant automático, uso de
credenciais reais, merge/deploy ou execução na VPS.

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.**
