# PORTAL-INT-F155 — Estado e revisão de segurança da empresa vinculados à sessão

A integração F152/F153 passou a exigir que uma sessão esteja vinculada à organização selecionada e que a revisão de associação corresponda à revisão atual de `portal_memberships`. O esquema PostgreSQL também mantém `portal_organizations.status` e `portal_organizations.security_revision`; a migração F76 já armazena `portal_auth_sessions.organization_revision` (sem valor padrão, para não validar sessões antigas).

**Falha corrigida:** até F154, o contrato sintético de autorização não exigia a versão corrente da organização nem verificava se ela estava ativa. Mesmo com associação ativa e grant explícito, uma empresa suspensa ou cuja revisão de segurança mudou deve bloquear a sessão anterior.

A F155 acrescenta `PortalOrganizationAuthority::lookup()` e um `PortalOrganizationSnapshot` tipado (id da organização, estado ativo, revisão positiva). A `PortalSessionSnapshot` exige `sessionOrganizationRevision` positiva, imutável. A `PortalAuthorityBoundary` compara a organização da sessão, depois exige a evidência atual da organização **antes** de membership/ownership: nega na ausência do registro, em estado inativo, em caso de tenant divergente ou quando a revisão atual difere da sessão.

`AppServiceProvider` vincula a nova interface ao mesmo `DenyingPortalAuthorities` já usado pelas outras três fontes. Não existe nenhum resolver confiável de organização ligado à aplicação, portanto a execução real **continua negando tudo**.

Os testes de Unit, Commercial Review, GSC e F26 PG simulam empresa ativa, suspensa, revisão alterada, propriedade de outra empresa, revisão inválida e revogação anterior. Um resultado positivo de mocks continua apenas sintético e nunca concede HTTP, dados de clientes, CRM, OAuth, escrita nem pagamentos.

**Limites:** nenhuma leitura real do banco, status de empresa, assinatura de issuer, transação serializável, MFA real, autorização de cliente ou RLS ALLOW. A futura fonte precisa da identidade Portal e acesso revisado a PostgreSQL18 com isolamento por tenant e barreira de revogação consistente. A segurança operacional depende de revisão independente AppSec/DBA/LGPD, homologação e aprovação específica. Sem merge dev/main, sem VPS, deploy, credenciais, PII ou novos gastos.
