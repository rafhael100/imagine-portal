# Portal F141 — Roteiro guiado das dez telas internas

A F140 disponibilizou duas áreas já implementadas — Ajuda e Avisos — elevando a prévia offline a onze arquivos HTML (dez telas e índice). O índice agora oferece também um **roteiro de avaliação interna** em ordem clara, com descrição objetiva e links diretos para **todas** as dez telas fictícias.

## Experiência de produto

O revisor começa na Visão Geral, percorre serviços, catálogo de Onepage/Landing Page, contratos, módulos, jornada, solicitações, Ajuda e Avisos, e termina no exemplo de revisão técnica de entregas para observar limitações de envio/CRM. Cada etapa é uma orientação de avaliação, **não** um estado registrado do cliente, uma proposta comercial, cobrança, SLA ou aprovação.

## Implementação e testes

- `DemoPortalReviewWalkthrough::steps()` centraliza apenas metadados fictícios de dez telas, sem estado, banco, rede, sessão ou pagamentos.
- O exportador produz HTML semanticamente estruturado com `section`, `h2`, `ol`, links e descrições escapadas. Valida que cada destino pertence ao inventário fechado F135, não é repetido e que todas as dez telas aparecem exatamente uma vez.
- `DemoPortalNavigationAudit` mantém validação de links internos, CSP, `noindex`, `aria-current`, teclado, manifesto e ausência de operações; F139 continua impedindo exportação concorrente ao mesmo diretório.
- PHPUnit verifica ordem, títulos, existência dos dez arquivos, links, ausência de scripts e formulários, e inexistência de ações autorizadas. A CI continua nos PHP 8.3/8.4 e Node 22/24 existentes.

## Limites

O roteiro não é fluxo de atendimento real nem ferramenta de gestão, muito menos substituto de testes E2E de aplicação autenticada. Não cria `ClientSection`, rotas ou credenciais. Continua privado para revisão local/testing com dados fictícios; sem publicar/mesclar a `main`, ativar PSP, Google, Meta, CRM ou Sites, nem gerar despesas.
