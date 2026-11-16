# Portal F136 — autorização `seo.view` por organização vinculada à sessão administrativa revogável

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Integra duas linhas paralelas: [F134 org SEO #879](https://github.com/rafhael100/imagine-portal/pull/879) e a base de revogação/auditoria [F135 #883](https://github.com/rafhael100/imagine-portal/pull/883).

## Por que consolidar

A branch de grants por organização foi criada paralelamente à F134 de invalidação de sessões e utilizava o mesmo identificador F134. Se publicadas separadamente, poderia haver divergência de permissões. Esta integração mantém ambas as proteções no **mesmo candidato** sem merge na main:

- Banco PostgreSQL `portal_admin_org_grants` com FK do administrador, referência opaca da organização, permissão fechada `seo.view`, prazo, estado e revogação. A migration foi renomeada para `2026_11_15_000014_create_portal_admin_org_grants.php`, pois a F134 de sessões já usa a sequência `000012`.
- Rota administrativa `GET /portal-admin/seo-review/{organization}`, **ausente por padrão** quando `PORTAL_ADMIN_AUTH_ENABLED=false`.
- O guard `PortalAdminMfaGuard` verifica usuário autenticado, conta ativa, TOTP recente e **session_version igual ao PostgreSQL**. Só depois disso `PortalAdminOrganizationSeoGate` procura o grant `seo.view` válido para o usuário atual e a organização requisitada.
- A URL serve somente como alvo de pesquisa: não aceita ID do administrador, permissão, identidade de sessão ou concedente enviados pelo navegador.
- Falta de grant, outro usuário, organização inexistente, expiração ou revogação responde 404, sem expor nomes e metadados de clientes.
- A view exibe exclusivamente mensagem de indisponibilidade, **não** um painel com dados reais, URLs GSC, relatórios ou permissões de publicação.

## Provas em banco descartável

CI PostgreSQL18 em PHP 8.3 e 8.4 reúne testes F132–F136:
1. Sessão sem senha/MFA não lê a rota de organização.
2. Permissão ativa e dentro do prazo dá acesso **somente à página vazia**; expirada/revogada/outra empresa são recusadas.
3. Troca de conta não reutiliza as concessões da conta anterior.
4. Mesmo com um grant ativo, rotação do `session_version` no PostgreSQL invalida o acesso de forma imediata na próxima requisição.
5. O comando CLI de revogação registra um evento obrigatório (F135) e corta o acesso à organização sem precisar remover silenciosamente o grant.
6. Conta desativada, rota pública e acesso de cliente não são habilitados por nenhuma dessas alterações.

## Bloqueios operacionais

O registro `portal_admin_org_grants` é um **grant técnico do Portal**, não prova contratação ativa, pagamento, propriedade de site/Google, nem permissões de clientes. É necessário cadastro autenticado de organizações, assinatura de serviços, revogação de vínculo operacional, papéis DB/RLS apropriados, MFA admin real, OAuth Google readonly e emissor seguro do SEO Engine. Revisão AppSec/DBA/LGPD e staging VPS com TLS, sessões persistentes, backup, monitoramento e rollback continuam obrigatórios.

**ReleaseStatus: HOLD_REAL_ORG_SERVICE_ENTITLEMENT_AND_STAGING.**
