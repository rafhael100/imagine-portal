# PORTAL-INT-F153 — Bloqueio de sessão após mudança de associação, papel ou grants

A F152 corrigiu a vinculação explícita da sessão à empresa selecionada. A revisão posterior identificou uma lacuna correlata: `PortalSessionSnapshot` verificava a revisão **da identidade**, mas não a revisão **da associação** que constava na tabela `portal_memberships.membership_revision` e na sessão `portal_auth_sessions.membership_revision`. Uma sessão antiga de um usuário ainda ativo não deve herdar implicitamente novos grants nem sobreviver a uma mudança de papel, associação ou revogação/reemissão.

## Correção no domínio

- `OrganizationMembership` passa a carregar `membershipRevision` positiva. O valor é parte de um DTO puro e deve ser resolvido por uma autoridade Portal **confiável**, nunca pelo navegador ou CRM. Por compatibilidade com os objetos de teste existentes, o campo tem valor sintético inicial 1; o construtor recusa valores zero/negativos.
- `PortalSessionSnapshot` passa a **exigir** `sessionMembershipRevision` positiva, capturada na emissão da sessão. O campo é imutável, junto com a organização da sessão adicionada pela F152.
- `PortalAuthorityBoundary` compara as duas revisões antes de consultar ownership do recurso. Qualquer divergência retorna `blocked()` sem uma decisão de autorização, mesmo para ator ativo com permissões de negócio.
- O contrato F152 também continua exigindo mesma empresa na sessão e na seleção, MFA, data de expiração, revisão de identidade, membership ativo e permissão explícita.

## Testes

PHPUnit valida sessão antiga revision 1 contra membership atual revision 2 (**nega**), sessão atual revision 2 contra membership revision 2 (candidato **apenas sintético**) e sessão revision 2 contra membership revision 1 (**nega**). Os construtores recusam revisão 0. A prova de `CommercialDeliveryReviewPreflight` adiciona membro alterado ao conjunto de negados; a integração PG de laboratório F26 valida bloqueio após mudança de associação quando a CI PostgreSQL descartável está habilitada.

## Limites de segurança

Isso não garante, por si só, atomicidade da leitura do estado do banco, autenticação do issuer, janela de revogação, segurança do token, lock transacional ou semântica de organização ativa. Tais propriedades exigem futura implementação e revisão **independente** de `PortalSessionAuthority`, `PortalMembershipAuthority`, `PortalResourceAuthority`, RLS, MFA, auditoria e log de revogação. Em `AppServiceProvider`, todas as autoridades permanecem `deny-all`.

**NO-GO operacional:** nenhuma sessão real, API, token Google, fila CRM, grant, migração, deploy ou acesso a cliente foi habilitado. Não alterar `main` ou `dev` sem revisão e homologação.
