# PORTAL-F18 — Catálogo demonstrativo de produtos com fronteiras independentes

**09/11/2026. Escopo: Laravel, código PHP puro e Blade, sem backend Node.js operacional.**

## Problema tratado

A área `portal.demo.available` usava quatro cartões de textos estáticos:
Sites agregava Onepage e Landing Page, enquanto os contratos de demonstração
(F16) e o acompanhamento de serviços (F17) já os tratavam como serviços
independentes. A Universidade Imagine aparecia como se fosse mais uma
oferta contratável, embora sua política formal seja **benefício de clientes
elegíveis**. O CRM precisava continuar com identidade/sessão próprias.

## Implementação

1. `Domain/ServicePortfolio/PortfolioProduct.php`: enum fechada de cinco
   produtos visuais: Onepage, Landing Page, Performance AI, Imagine CRM e
   Universidade Imagine. Define apenas nomes e fronteiras de produto.
2. `PortfolioKind.php`: distingue serviço comercial independente,
   produto com acesso próprio (CRM) e benefício condicionado (Universidade).
3. `PortfolioPreviewItem.php`: DTO readonly, descrição limitada, indicadores
   `isCheckoutEnabled`, `grantsProductAccess`,
   `provesServiceEligibility` e `claimsActiveSubscription`
   **sempre false**, independentemente da categoria.
4. `Presentation/DemoServicePortfolio.php`: cinco fichas fictícias,
   sem consulta SQL, cliente, sessão, estado contratual ou preço.
5. `resources/views/portal/demo/available.blade.php`: os cartões agora são
   gerados pelo catálogo tipado, com aviso de demonstração e sem botões de
   assinatura, links externos, formulários ou preços.
6. PHPUnit testa isolamento conceitual, enum fechada, ausência de direitos,
   unicidade, correspondência das três fichas de acompanhamento/contratos
   existentes, escape HTML e não uso de banco, rotas ou sessões.

## Regras comerciais preservadas

- **Onepage e Landing Page:** serviços distintos, produzidos pela Imagine e
  publicados futuramente pelo **motor Imagine Sites separado** do Portal.
  Implantação e mensalidade são regras do negócio, mas esta etapa não
  calcula nem exibe valores e **não implementa checkout**.
- **Performance AI:** produto independente administrado via Portal;
  motor de Google Ads/Meta não é executado por esta demonstração.
- **Imagine CRM:** produto separado com identidade, login e sessão próprios.
  A ausência de SSO e de compartilhamento de credenciais permanece explícita;
  nenhum acesso ao CRM é concedido.
- **Universidade Imagine:** não se vende isoladamente. O acesso poderá ser
  concedido somente por mecanismo confiável de elegibilidade a clientes
  Imagine com serviço ativo elegível; esta etapa **não avalia nem concede**
  tal benefício.
- `isStandaloneServiceByPolicy()` é somente classificação de negócio;
  não representa capacidade presente de comprar, assinar ou provisionar.

## Diferença entre catálogo futuro e este laboratório

O Laravel F18 não opera banco/tenant autenticado, tarifa, plano comercial,
contrato assinado, status pago, elegibilidade real, autorização, webhook,
histórico, migração ou gateway externo. O namespace de catálogo Performance
P02-A existente continua separado e não foi reescrito.

Para futura operação será necessário catálogo administrável e versões de oferta
aprovadas, contexto confiável da organização, dados de contrato verificados,
sessão Portal, RLS, cobrança independente de cada motor, aprovação humana e
revisão de segurança.

## Controle de mudança

**PR em rascunho, sem merge nem deploy.** Branch filha do PR #115 (que depende
dos PRs #114/#113/#112). Nenhum arquivo `server/` ou `public/` legado foi
modificado; Node.js segue ferramenta de teste CI e Cloudflare. Preservar
`main`, DEV, VPS, Cloudflare, dados de clientes, credenciais, contas,
faturamento e gates P0, SEC-R03, D02-SEC e D03-RLS.
