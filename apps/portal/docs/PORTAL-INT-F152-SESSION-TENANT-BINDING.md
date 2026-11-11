# PORTAL-INT-F152 — Sessão vinculada à empresa antes de avaliar autorizações

## Falha identificada na revisão do domínio integrado

O `PortalAuthorityBoundary` já verificava dados sintéticos de expiração, MFA, revisão da conta, associação ativa e proprietário do recurso. Entretanto, `PortalSessionSnapshot` carregava **somente a identidade do ator**, sem registrar **a organização à qual a sessão pertence**. Dessa forma, um usuário fictício associado a duas empresas poderia apresentar uma sessão de A, selecionar B e receber um resultado `syntheticChecksPass` positivo se a associação e o recurso fossem de B. O resultado nunca concedia HTTP, mas esse padrão deveria ser corrigido **antes** de introduzir uma fonte real de sessão.

## Correção

- `PortalSessionSnapshot` exige um `OrganizationId $sessionOrganizationId` imutável na construção. Não aceita ausência de empresa como evidência implícita.
- `PortalAuthorityBoundary` confere a organização associada à sessão **antes** de consultar associações e a propriedade do recurso. Qualquer divergência retorna `blocked()`, não vaza dados de provedor e não faz fallback para outra empresa.
- Testes da fronteira principal verificam usuário multiempresa, sessão de A contra seleção B (nega), sessão de B para B (candidato apenas sintético) e sessão B contra A (nega).
- Os testes `CommercialDeliveryReviewPreflightDefaultDenyTest` agora rejeitam também uma sessão de empresa diferente mesmo quando membership e resource-owner simulam a empresa solicitada.
- A prova de laboratório `F26AuthorityReadRehearsalTest` recebe uma organização na sessão e inclui caso adversarial com token assinado de A e sessão vinculado a B. Os testes integram a evidência com banco descartável quando a CI PG estiver configurada.

## O que continua proibido

A sessão e as associações usadas nos testes **não provam autenticidade**, assinatura de issuer, leitura de PostgreSQL, nem revogação concorrente. `AppServiceProvider` continua usando somente `DenyingPortalAuthorities`. Nenhuma API, login, cookie, sessão de cliente, RLS ALLOW, CRM, GSC OAuth, migração ou chamada remota é ativada.

**NO-GO:** somente reforço do contrato de segurança. Revisão AppSec/DBA/LGPD e homologação continuam exigidas antes de ligar adaptadores de autoridade reais ou liberar rota de cliente. Nenhuma alteração na `main`, `dev`, VPS, DNS, produção ou custos.
