# Imagine Portal P0 — Asaas F1-392: conciliação com lease PostgreSQL 18

## Objetivo da entrega

A PR financeira F1-390 consulta `GET /v3/payments/{id}` e compara o resultado com a oferta/contrato local imutável. A F1-391 persiste eventos Asaas autenticados e idempotentes em uma inbox PostgreSQL 18. **Esta F1-392 liga esses dois componentes por meio de um worker, com reserva durável e confirmação protegida contra duplicação.**

A documentação oficial Asaas descreve entrega de webhook *at least once*, resposta HTTP 200 após persistência e processamento posterior, e distingue `PAYMENT_CONFIRMED` de `PAYMENT_RECEIVED`. Asaas V3 envia `account.id`, já verificado pelo ingresso F1-391.

Fontes:
- https://docs.asaas.com/docs/sobre-os-webhooks
- https://docs.asaas.com/docs/webhook-para-cobrancas
- https://docs.asaas.com/changelog/identifica%C3%A7%C3%A3o-da-origem-da-conta-em-webhooks-v3

## Fronteiras e invariantes implementados

- O worker não compartilha o login SQL da inbox. É uma nova role restrita, `asaas_reconcile_worker`, sem `SUPERUSER`, `BYPASSRLS`, `DELETE`, alteração dos campos de identidade nem `SET ROLE`; permissões somente nas colunas de processamento. `FORCE ROW LEVEL SECURITY` e política por UUID da conta Asaas no banco.
- A rotina usa `FOR UPDATE SKIP LOCKED` para reservar um evento, identificador aleatório de lease (UUID), expiração em 45 segundos e incremento de tentativas. A consulta independente ocorre **fora** da transação SQL. A confirmação `ACK` ocorre numa nova transação, só se o token e a validade da reserva ainda coincidirem; ACK perdido permite retomada após expiração.
- Estados de CI: `pending_reconciliation`, `claimed`, `retry_wait`, `observed_psp_received` e `manual_review`. A observação `observed_psp_received` **NÃO** é contrato válido, pagamento autorizado para site ou liquidação irrevogável. Não é consumida pelo publisher nem pelo emissor Ed25519.
- Só há observação quando o evento é `PAYMENT_RECEIVED`, há vínculo local imutável da conta PSP+ID cobrança para pedido/tenant/contrato/versão e `PortalCurrentSettlementSource` retorna uma evidência válida **após nova consulta oficial**, com todos os campos coerentes. A fonte padrão nega. Um `paid=true` do webhook nunca é autoridade.
- `PAYMENT_REFUNDED`, estorno parcial/em andamento, chargeback, devolução de recebimento e exclusão entram em `manual_review` diretamente, mesmo se um `RECEIVED` anterior teve sucesso; falta integrar a autoridade operacional de revogação.
- Evento `PAYMENT_CONFIRMED`, ordem desconhecida e falha da API permanecem `retry_wait`, com atraso de 120 segundos e no máximo oito tentativas. Depois disso, revisão manual. Workers não reutilizam token de outro lease nem alteram cobrança/referência/tenant.
- Não são armazenados corpo de webhook, token, CPF/CNPJ, nomes, endereço, cartão ou dados reais do provedor. Nenhum controller, endpoint público, webhook de produção, lançamento financeiro, emissão fiscal ou chamada ao Sites foi registrado.
- Arquivos SQL desta PR contêm roles e senhas **fictícias** e só podem ser executados no PostgreSQL efêmero do GitHub. **Nunca aplicar este SQL na VPS**, cujo banco e permissões requerem migration operacional revisada por DBA, segredos via vault e restore testado.

## Testes

O workflow `portal-f1-392-asaas-reconciliation.yml` executa PHP8.3/8.4 sobre PostgreSQL18 real e descartável. As duas roles têm permissões distintas. Os testes injetam uma resposta Asaas **fictícia** no leitor oficial já existente (sem rede) e provam concessão de lease, observação positiva de auditoria, recusa de estorno, repetição bloqueada, retry, backoff, retomada de lease perdido, ausência de concorrência de duas reservas e incapacidade de uma role de webhook assumir o papel do worker.

## Gates ainda pendentes

Implementar a fonte SQL operacional de vínculo imutável `AsaasTrustedEventPaymentSource`, com unicidade por conta+ID de pagamento e auditoria do contrato/valor; criar migração real reversível e roles protegidas; registrar endpoint HTTPS, token webhook no Asaas Sandbox e serviço processador privado na Leaseweb com monitoramento e recuperação automática; validar `GET` oficial, backoff/rate limits, tokenização e Pix Automático, confirmação de cartão, política de estorno, fonte de revogação, assinatura Ed25519, IAM e backup/restore. Um worker de revogação independente terá de impedir emissão/publicação futura após um estorno.

**Estado: DRAFT / NO-GO / sem merge, deploy, cobrança, conta, segredo real ou ativação de Sites.**
