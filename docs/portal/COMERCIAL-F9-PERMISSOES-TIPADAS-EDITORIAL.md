# Portal Comercial F9 — Permissões tipadas por etapa editorial

## Código e testes
- O vocabulário fechado `Permission` agora distingue `commercial.catalog.edit`, `commercial.catalog.approve` e `commercial.catalog.publish`.
- `CommercialPublicationPermissionGate` reutiliza `OrganizationAuthorizationService`, sem inventar um sistema de login independente.
- Para permitir uma operação **apenas no avaliador puro**, exige três atores distintos, memberships ativos do Portal para a organização do recurso, e uma concessão explícita e distinta para cada ação.
- Papel Owner sozinho **não concede** as permissões; usuário suspenso, revogado, membro de outra organização, permissionamento incorreto ou ausência de membership sempre resultam em deny.
- Testes PHPUnit com participantes fictícios; sem persistência, sessão, token ou contas reais.

## Segurança ainda não implementada
Esta é só uma política determinística. O objeto `OrganizationMembership` é construível em memória, portanto não prova que veio do Portal, e a igualdade de ID textual não diferencia emissor Portal de CRM. Não expor esta API em HTTP antes de construir um resolvedor que obtenha ator e organização de sessão autenticada própria, valide MFA, papéis atuais, revisão de grants e barreira de revogação no commit/efeito.

**Nenhuma permissão é concedida automaticamente** ao atualizar a enum. O painel editorial continua sem rota de publicação; PostgreSQL F7/F8 ainda usa papéis sintéticos. A integração com o leitor de identidade e storage operacional é um gate futuro.

Sem merge main, acesso à VPS, banco real, checkout, publicação ou custos.
