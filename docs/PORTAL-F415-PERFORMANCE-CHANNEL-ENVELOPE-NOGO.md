# F1-415 — Seleção de canais vinculada a envelope assinado (somente revisão)

## Por que este recorte é necessário

O contrato original de revisão Portal→CRM da F413 assina **organização,
pedido, gateway, método, valor e versão de tarifa**, mas não a lista de canais
Performance adquiridos. Duas seleções diferentes, com o mesmo número de
canais e a mesma ativação R$490/canal, teriam valor igual e não poderiam
ser diferenciadas somente pelo HMAC do PSP.

A F415 acrescenta um **segundo envelope de revisão offline**, que vincula
por HMAC, com domínio criptográfico separado, exatamente os canais canônicos
selecionados ao HMAC original Portal→CRM, ao tenant, ao pedido, à versão da
política e à janela de validade de quinze minutos.

## Invariantes

- A fonte deve ser `ActivationGatewayReviewLab::preview` com elegibilidade
  calculada no servidor, **não** valores ou flags do navegador.
- Recalcula R$490 × canais com a F408 e rejeita duplicatas, canal indisponível,
  valor alterado e tentativa de cobrar mídia ou gestão pós-paga.
- Reproduz e verifica o envelope HMAC original da F410/F413 antes de assinar.
- Impede substituição Meta+Google por Meta+ChatGPT apesar do mesmo total.
- Verifica assinatura por `hash_equals`, vínculo organização/pedido/PSP
  e janela UTC estrita de quinze minutos.
- Impede flags de cobrança/ativação e outros atributos extras na verificação.
- Formato e testes sintéticos, sem dados pessoais nem segredos reais.

## Limite de segurança — imprescindível

**Assinar uma revisão não a torna uma autorização de cobrança.** Um envelope
poderia ser reapresentado durante quinze minutos. Para aceitar um pedido
real, é necessário IAM/MFA/consentimento, registro único de uso por pedido,
idempotência, transação RLS e travas de execução/PSP, aceite assinado,
rollback, auditoria, validade de tarifas e confirmação do orçamento.

O mínimo mensal de gestão segue **não definido neste contrato**. Os 20%
sobre mídia real são pós-pagos somente após apuração auditada; nenhum
valor ilustrativo autoriza uma fatura.

Testes: `php apps/portal/tools/test_performance_channel_envelope_offline.php`
e `cd apps/portal && php artisan test --filter=PerformanceChannelReviewEnvelopeTest`.

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY / NO-REAL-MONEY.**
