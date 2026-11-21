# Landing Page — pedido de ajuste v1 → revisão editorial v2 → aceite exato (laboratório)

21/11/2026 · **DRAFT / NO-GO** · continuação exclusiva da Portal #1053, sem tocar main ou produção.

## Lacuna e escopo

A #1053 comprovou aceite final do artefato e invalidação por alteração manual dos bytes no PG18, mas **não** demonstrou um pedido de ajuste pelo comprador seguido de nova revisão do conteúdo pelo motor real Sites. Este candidato usa **o mesmo pedido fictício** aceito no navegador nas #1041–#1053, não uma nova compra e não um segundo produto. Sites #465 é precedente de máquina editorial (editor submete, revisor decide), e #473 é precedente de recibo de aceite exato; nenhum deles é usado como autorização de publicação nesta fixture.

O browser autentica a identidade **fictícia** do comprador para pedir ajuste sobre a versão/SHA exatos da prévia v1. A solicitação usa token de apresentação de uso único, CSRF vinculado à sessão, checkbox e clique, com persistência no PG18 e releitura por outro PHP. Depois, duas operações CLI distintas em ambiente descartável escrevem draft v2 e aprovação fictícia v2, com eventos de auditoria de atores distintos. Essa operação **não** instala um writer operacional ou cria papéis reais de produção. O leitor read-only #1019 recusa a v1 quando uma v2 não aprovada passa a ser a revisão mais recente.

Somente após uma aprovação fictícia distinta, o renderizador **real e pinado** do Sites #569 produz HTML v2 (produto Landing Page, sem formulário ou destino). A troca usa CAS por revisão e SHA do briefing v1, SHA do HTML v1 e geração do artefato. Na transição, a prévia privada e o aceite anterior permanecem inválidos até v2 ser renderizada e explicitamente aceita. O navegador mede SHA-256 dos bytes HTTP de v2 e confirma novamente a versão/generation; o Portal (não o Sites) registra o aceite do comprador fictício em armazenamento separado, relido por outro PHP.

## Segurança e negativas

- Outro comprador da mesma empresa, outra empresa, sessão anônima e POST sem checkbox são recusados.
- Token de solicitação consumido não pode ser reutilizado. A v1 obsoleta e o desafio antigo de aceite não aceitam a v2.
- Draft sem aprovação fictícia e aprovação antes da substituição do HTML negam acesso à prévia; o aceite contratual da compra continua diferente do aceite final.
- Contrato, empresa, site e pedido permanecem imutáveis, e o tipo de produto é Landing Page, não OnePage.
- Nenhum formulário ou lead é acionado; nenhuma API OpenAI, Replit, PSP, n8n, VPS, Cloudflare, DNS, credencial real, publicação ou merge.

## Limites inalterados

Identidade de sessão e aprovações são **fixtures**. Não existe writer legal/operacional de contrato e briefing, autenticação nativa, sessão S2S privada, autorização de release, publicação simulada observada ou destino oficial para leads. Sites #38 e demais gates seguem **NO-GO**. A prova de CI da SHA exata deve ser lida dos workflows do PR; não pressupor sucesso antes da execução.
