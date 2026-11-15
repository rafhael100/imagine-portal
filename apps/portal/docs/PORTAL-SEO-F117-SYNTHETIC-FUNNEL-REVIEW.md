# Portal SEO F117 — contrato sintético Visitas → Leads → Clientes

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.**
Continuação da [F116 / PR #854](https://github.com/rafhael100/imagine-portal/pull/854).

## Objetivo

Preparar o contrato de cálculo do funil Imagine a partir de duas
fontes independentes, sem confundir cliques GSC com visitas reais:

- **Visitas:** sessões do site fornecidas futuramente por analytics web
- **Leads:** eventos atribuídos de captação no CRM
- **Clientes:** conversões confirmadas e atribuídas pelo CRM

A F117 é um **laboratório fechado**. Não se conecta a nenhum dos
serviços e não fabrica evidência de autorização.

## Contrato de entrada sintético

Uma entrada totalmente artificial com tenantRef, período de no máximo
92 dias e duas fontes rotuladas:
- synthetic_analytics_ci: sessions, providerVerified=false
- synthetic_crm_ci: attributedLeads, attributedCustomers,
  providerVerified=false, attributionVerified=false

A estrutura é fechada: campos extras, credenciais, dados pessoais,
nomes de fontes reais, outra empresa, janelas divergentes e flags
de origem autorizada são rejeitados.

Neste exemplo didático a relação é limitada a
clientes <= leads <= sessões, supondo no máximo um lead atribuído por
sessão e um cliente por lead. **Essa é uma hipótese de demonstração,
NÃO uma regra automaticamente válida para dados reais**. Uma
implementação operacional precisará de um modelo de atribuição
próprio, identidade/cookies consentidos e definição de janela.

## Resultado apenas para laboratório

No exemplo sintético com 1.250 sessões, 75 leads e 15 clientes,
o cálculo gera:
- Visitas → Leads: 6,00%
- Leads → Clientes: 20,00%
- Visitas → Clientes: 1,20%

Quando o denominador é zero, a taxa é **null/N/D** e nunca um
percentual inventado. O retorno contém sempre:
- realAttributionVerified=false
- portalTenantGrantVerified=false
- customerDisplayAuthorized=false
- productionReadAuthorized=false
- networkPerformed=false
- releaseStatus=HOLD_AUTHENTICATED_ANALYTICS_CRM_ATTRIBUTION

Esses números **não podem ser exibidos a clientes**. A view F116
continua usando N/D até haver fontes operacionais autorizadas.

## Testes e gates

Testes PHPUnit/Laravel:
tests/Unit/PortalSeoSyntheticFunnelAttributionReviewTest.php

Para execução exige CI=true, APP_ENV=testing e
PORTAL_SEO_F117_LAB=1. A suíte verifica proporções,
denominador zero, cross-tenant, períodos, campos extras,
falsificação de permissões e bloqueio fora de laboratório.

Ainda faltam analytics autenticado, CRM com eventos de atribuição,
sessão/MFA real e RLS multiempresa, consentimento e LGPD,
validação de qualidade de métricas, homologação em VPS,
revisão independente e liberação de publicação.

**Sem rede, Google, CRM, dados reais, billing, VPS, deploy ou merge.**
