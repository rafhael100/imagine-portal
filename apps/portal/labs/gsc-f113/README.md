# GSC-F113 — Permissão SEO explícita nas migrations do Portal

## Mudança de aplicação proposta (somente PR, não implantada)

A nova migration PostgreSQL permite o código de permissão exato **seo.gsc.connect** na restrição de vocabulário da tabela existente portal_membership_grants.

- Não insere linha em grants e não concede permissão a owner, manager ou member automaticamente.
- Não abre policy RLS nem concede SELECT, UPDATE ou EXECUTE a papéis da aplicação.
- Não adiciona rotas HTTP, autenticação OAuth, credenciais, leitura Google, consentimento ou KMS.
- Mantém os códigos anteriores services.view, services.manage, billing.view e members.manage.
- A reversão recusa explicitamente seu processamento se houver algum grant seo.gsc.connect ainda registrado. A migração deve rodar com autoridade DBA capaz de ver todo o conteúdo da tabela, para evitar inconsistência sob RLS.

## Validação exigida

GitHub Actions em PHP 8.3/8.4 + PostgreSQL 18 **descartável** executa a migration de identidade original F66 e a nova migration F113. Verifica que não surge nenhum grant automático, que FORCE RLS segue fechada sem policies, que uma role comum não enxerga os grants mesmo tendo SELECT, que o escopo SEO mais amplo é recusado e que o rollback falha fechado até revogação explícita do grant sintético. Depois faz rollback limpo e checa a restrição original.

## Gate operacional

Mesmo com CI verde, essa migration não deve ser aplicada em produção sem revisão DBA/AppSec, plano de backup/reversão, catálogo de permissões por empresa, sessão/MFA operacional e consentimento do titular do Google. Nenhum rollout ou merge em main foi autorizado por esta PR. A autorização de Search Console no OpenSEO não se converte em credencial OAuth do Portal.
