# Interface privada de pedidos Onepage — incremento de homologação

## Alcance

A tela privada fica em GET /_development/commerce/onepage, registrada
exclusivamente quando os mesmos guards do pedido nativo estão ativos:
PORTAL_ONEPAGE_ENABLED=true, autenticação nativa de desenvolvimento,
HTTPS/origem exatos e configuração não armazenada em cache.

O caminho do navegador NÃO é uma área pública de contratação. As rotas e
recursos client.js/client.css usam os mesmos middlewares de origem, sessão,
segurança e cache-control existentes. Uma política CSP bloqueia scripts inline,
conexões de terceiros e formulários externos.

## Sequência de uso

1. O navegador valida /_development/auth/session. Sem MFA/sessão de negócio,
   nenhuma cotação é consultada. O login de desenvolvimento aponta para a tela
   somente quando Onepage está ativada.
2. O usuário seleciona o tier. O servidor recalcula a cotação; o browser nunca
   envia preço, empresa ou comprador. Repetições usam a mesma chave no mesmo tab.
3. GET da cotação e GET da revisão recuperam a versão do contrato no servidor.
   O texto integral é exibido como texto, jamais como HTML executável.
4. O usuário marca o aceite da versão exata. O POST contém somente a confirmação,
   os campos de digest/revisão apresentados e uma chave idempotente tab-local.
5. O navegador lê o pedido próprio novamente, apresentando pending_payment.
   A referência do pedido, quando conhecida, é guardada só em sessionStorage;
   qualquer reabertura revalida a sessão e o pertencimento no PostgreSQL.

As chaves de browser são conveniências de recuperação, não poderes de negócio.
Se a resposta da operação se perder, a próxima tentativa reutiliza a chave.
Agora a aba consulta GET /orders no servidor para encontrar pedidos recentes do
comprador mesmo após abrir outra aba ou dispositivo. A consulta SQL nativa limita
a 20 pedidos ainda utilizáveis, revalida empresa, identidade, MFA, contrato e
revogação e não retorna informações de outras contas. Os identificadores locais
continuam sendo conveniência apenas. O vínculo do recibo Sites na interface
ainda requer um incremento específico.

## O que NÃO se faz

A interface não invoca PSP, não emite Pix ou boleto, não confirma primeira
parcela, não recebe recibo Sites, não libera briefing, não dispara produção,
não oferece botão para publicar e não afirma que houve deploy. A primeira
parcela deve ser confirmada oficialmente para abrir o briefing; publicação
depende de aceite separado do site final. A fonte de termos publicados deve
ser provida pela direção, sem condições fictícias ou ambíguas.

Este incremento mantém #941 e Sites #512 em rascunho, sem merge nem alteração
da VPS, DNS, credenciais ou clientes reais. A sequência posterior é worker
financeiro confiável → transporte autenticado de pedido/recibo → leitura
segura de status → briefing/produção/aprovação/publicação cada qual com seu gate.
