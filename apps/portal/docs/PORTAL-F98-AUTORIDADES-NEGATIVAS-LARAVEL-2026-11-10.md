# PORTAL-F98 — Autoridades de identidade negativas por padrão

**Data:** 10/11/2026. **Estado:** segurança de composição Laravel; sem autorização de login real.

## Implementação

O registro de serviços do Laravel agora associa três contratos de segurança próprios do Portal à mesma fonte de negação incondicional:

- PortalSessionAuthority — nunca encontra uma sessão;
- PortalMembershipAuthority — nunca confirma um vínculo, papel ou permissão;
- PortalResourceAuthority — nunca confirma a empresa proprietária de um recurso.

A fonte é DenyingPortalAuthorities, já existente no domínio. As três interfaces usam a mesma instância singleton do contêiner. PortalAuthorityBoundary continua não operacional: mesmo um teste sintético positivo jamais concede permissão HTTP.

## Garantias verificadas

A suíte PortalDefaultDenyAuthorityBindingsTest, executada pelo contêiner Laravel em PHP 8.3 e 8.4, confere a resolução das interfaces, a ausência de sessão ou membership, negação para empresas diferentes, recusa de permissões aparentemente válidas e permanência da única rota /up. O workflow também executa os testes preexistentes PortalAuthorityBoundaryTest e PortalLabIsolationBootGateTest.

No runtime Docker de homologação permanecem somente os contratos de leitura sem adaptadores de banco, login, IAM real, CRM, Google/Meta, pagamento, tokens ou provedores externos. F98 não instala usuários nem cria tabelas, migrações ou endpoints.

## Bloqueios para futura liberação

A substituição do adaptador negativo por uma implementação real exige autorização específica e revisão independente, incluindo origem autenticada do ator na sessão própria do Portal, MFA e revogação, escolha de empresa, versionamento de membership, proveniência de ownership, isolamento transacional PostgreSQL/RLS, proteção de segredos, auditoria e testes adversariais. Um teste verde com mocks não demonstra esses requisitos.

Este PR é **DRAFT / NO-GO**, sem alteração em main, VPS, DNS, CRM, dados de clientes, faturamento ou anúncios e sem custos extras.
