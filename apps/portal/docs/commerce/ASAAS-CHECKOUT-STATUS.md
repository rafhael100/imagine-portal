# Checkout com ofertas aprovadas — 14/11/2026

Base de aplicação: 67b064d99e8c7b959ddb3e008811a4cca7738a6e (PR #732). Catálogo recuperado da PR #737 em 4ae3f2489e3002ff91fe8dc55b9fbdf4829a9a24, copiado integralmente para app/Homologation/Commerce/approved-catalog.json e versionado por SHA-256. Nenhuma atualização automática por branch móvel.

## Implementado nesta alteração

- Checkout apresenta Onepage R$ 990 (3×330) e Landing Page R$ 1.290 (3×430), D0/D30/D60 e assinatura obrigatória R$ 79,90 em D90.
- Performance calcula 20% com centavos inteiros e arredondamento final; mínimo de mídia R$ 700 verificado em cada canal. Google e Meta separados, Instagram/Facebook integram Meta. Não soma mídia ao valor cobrável pela Imagine.
- Usuário revisa condições de simulação. Servidor recalcula, recusa preço/owner forjado, versão antiga, orçamento inválido e mudança de payload com mesma chave idempotente.
- Pedido grava snapshot e instante de revisão. Cliente e equipe visualizam as condições persistidas; pedidos anteriores permanecem sem valor atribuído retroativamente.
- Adapter PHP Asaas sandbox: URL fixa, sem redirecionamento ou retries automáticos, timeout, erros sem segredo, verificação de cliente/referência/valor. PIX CONFIRMED não libera como liquidado; consulta deve preceder atualização financeira. Adapter não é chamado pelas rotas de pagamento fictício.

## Aplicação em homologação

Migração aditiva ops/private-purchase/customer-offer-schema.sql, apenas em portal_purchase_rehearsal, com backup e runtime anterior preservados. Não aplicar em banco de identidade ou financeiro real. Executar depois dos schemas customer e customer-work. A migração é repetível e mantém pedidos anteriores.

Não conectar a rota que permite ao cliente escolher resultado fictício ao financeiro. Não chamar PSP com a identidade demo como identidade operacional. Preservar os controles de acesso existentes até IAM definitivo e gate antirollback #64 estarem resolvidos.

## Pendências que impedem afirmar fluxo financeiro completo

1. Conta e chave **Asaas sandbox** instaladas por canal privado; segredo webhook independente. Nunca colocar chave em GitHub, screenshots, logs ou chat. Nenhuma credencial foi disponibilizada nesta execução.
2. Cadastro/vínculo de cliente Asaas, aceite contratual definitivo, intenções duráveis com exclusão concorrente por pedido, conciliação de escrita incerta, ledger e deduplicação de webhooks com releitura pela API. O adapter isolado não substitui esse ledger.
3. Teste externo de pagamento inicial, falhas, duplicatas, estorno e worker D30/D60/assinatura D90. O adapter cria só uma cobrança vencível; não usa parcelamento bancário em substituição às três cobranças aprovadas.
4. Performance: aprovada cobrança antecipada estimada com acerto mensal pela mídia efetiva; cálculo de complemento/crédito sem piso artificial está implementado e testado, mas a prova de gasto real, datas de vencimento e execução financeira do acerto continuam pendentes. Orçamento planejado não é gasto realizado.
5. Login/recuperação definitivos, contrato, anexos, fiscal e ativação real continuam separados da homologação sintética.

Documentação oficial consultada: https://docs.asaas.com/reference/criar-nova-cobranca e https://docs.asaas.com/docs/sandbox. Criar cobrança não confirma pagamento; sandbox não compartilha credenciais com produção.

## Verificação

110 verificações standalone PHP executadas localmente com transporte injetado, sem PSP vivo. PHPUnit/Laravel/browser/PostgreSQL dependem do CI Docker desta branch. Resultado de transporte simulado não equivale à homologação Asaas nem a deploy na VPS.
