# PORTAL-F1-AUTHORITY-BOUNDARY — Recorte sintético autorizado (Issue #39)

**Data:** 07/11/2026. **Autorização:** aprovação expressa do responsável para o F1 restrito da Issue #39 (https://github.com/rafhael100/imagine-portal/issues/39).
**Estado:** composição em código puro e testes sintéticos; NÃO é autenticação real nem homologação P0.

## Estrutura entregue

- PortalSessionAuthority, PortalMembershipAuthority e PortalResourceAuthority são portas abstratas para futuras fontes confiáveis, sem adaptadores reais.
- PortalSessionSnapshot é um registro livremente construível, com campos de estado, MFA e revisão sintética; não prova emissor ou revisão real.
- DenyingPortalAuthorities implementa as três portas com negação por padrão.
- PortalAuthorityBoundary compõe as portas com o avaliador puro existente e não aceita actor, grants ou owner fornecidos pela solicitação. Erros ou falta de fonte produzem negação.
- PortalBoundaryVerdict.syntheticChecksPass só indica pré-condições de demonstração. PortalBoundaryVerdict.canAuthorizeHttp() é SEMPRE false, mesmo quando os fakes de testes estão coerentes.

## Fronteira e proibições

Nenhuma rota, sessão HTTP, cookie, login, MFA, endpoint, Eloquent, banco, PostgreSQL, RLS executável, SQL, DDL, migration, cliente, CRM, Performance, n8n, VPS ou provedor externo é acessado. As portas abstratas não convertem objetos caller-constructible em prova de identidade, e o mesmo texto de ID CRM/Portal não determina a origem.

Sem sincronização P→A→S→O→M→R real, sem revogação concorrente, proteção por RLS ou prova de ausência de vazamentos no banco. Isso não resolve R01-01/02/03/09/10 nem G-ID/G-BB/D02-SEC/D03. Não conectar esta inspeção demonstrativa ao container de produção como middleware.

Os testes negativos usam dados fictícios e permanecem sem autorização operacional. Manter o teste PortalAuthenticationRoutesClosedTest que exige respostas 404 nas rotas de autenticação e de áreas privadas. Um teste PHPUnit que passa não equivale a segurança homologada.

## Próximos passos separados

A Direção avaliará o PR draft com evidências reais de lint e CI quando disponíveis. Autenticador oficial, sessōes verificáveis, PostgreSQL/RLS, autorização operacional, testes de concorrência, merge e deploy requerem novas autorizações específicas. A documentação normativa R02–R05 continua em PR #33; PRs #19 e #24 continuam separados e sem merge.
