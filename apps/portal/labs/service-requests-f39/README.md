# PORTAL-F39 — Outbox transacional de solicitações (laboratório)

**09/11/2026 · PHP/Laravel + PostgreSQL 18 descartável · sem clientes ou entrega externa.**

## Lacuna abordada

A F38 já gravava o estado de uma solicitação fictícia e o recibo de
idempotência **na mesma transação PostgreSQL**. Uma futura integração
com Sites, CRM, Performance, notificações ou serviços externos precisa
que exista também um evento pendente *na mesma transação*, para impedir
o caso em que o pedido foi persistido mas a intenção de integração foi
perdida após crash.

F39 ensaia uma **outbox local, ainda sem consumidor**.

## Componentes

- `f39.synthetic_request_outbox`: tabela apenas no PostgreSQL 18 efêmero
  do CI com chave `(organization_id,command_id)`, FK para
  `f38.command_receipt`, tipo de evento fixo
  `service_request.status_changed.demo`, payload `jsonb` sem
  PII, ID `F39-DEMO-CMD-*`, ENABLE/FORCE RLS para
  `f38_writer_a` e `f38_writer_b` e grants somente SELECT/INSERT.
- `F38SyntheticRequestWriter::apply($command,writeSyntheticOutbox:true)`
  exige o opt-in `F39_ENABLE_LAB=TRANSACTIONAL_OUTBOX_CI_ONLY`,
  checa FORCE RLS da outbox antes de travar o pedido e, após UPDATE
  da solicitação e INSERT do recibo, insere a outbox **ANTES
  do commit da mesma transação**. Qualquer falha de INSERT ou
  RLS aborta o lote e restaura o estado/recibo inicial.
- Reenvio idêntico do comando com outbox habilitada verifica que
  **o recibo e o evento existem juntos**, devolvendo resultado
  original sem nova publicação. Recibo de F38 legado sem evento
  F39 é explicitamente `missing_synthetic_outbox`; não é
  representado como entrega bem-sucedida.
- O modo `apply($command)` de F38 continua disponível somente
  nos testes antigos e não cria outbox implícita.
- `F39TransactionalRequestOutboxTest.php` testa commit triplo,
  payload, idempotência, falha de INSERT, recibo sem outbox,
  alteração de FORCE RLS, isolamento A/B, ausência de
  UPDATE/DELETE/DDL, FK de evento órfão e erros de
  conflito/revisão.
- O workflow de integração carrega F39 após F38, gera as mesmas
  credenciais temporárias por tenant, verifica a sintaxe e roda
  o conjunto PHP 8.3/8.4 antes do ensaio F32 de backup lógico.

## Avisos importantes

**Gravar um evento na outbox não é enviá-lo.** Não foi criado
worker, filas, tópico, webhook, endpoint, e-mail, n8n,
serviço pago, sincronização com CRM ou processamento de dados
pessoais. Este registro não é uma prova de entrega, ack externo
ou garantia de processamento exatamente uma vez.

A outbox é descartada com o container e não possui
`delivery_status`, tentativas, backoff, lock de consumidor,
dead-letter, monitoração, retenção, purga LGPD nem
observabilidade operacional. O serviço de escrita usa papel
de negócio fictício — **não** um emissor confiável de usuário.
Mesmo com RLS no SQL, F39 não autoriza HTTP ou clientes.

Uma futura versão produtiva deve exigir IAM Portal-only
verificado, ownership de serviços, permissão, MFA quando
necessário, segredos gerenciados, transação durable e testes
de concorrência/restore, auditoria, revisão AppSec/DBA e
aplicação da política comercial/privacidade aprovada.

**PR DRAFT, sem merge, deploy, VPS Leaseweb, Cloudflare, API
real, pagamentos ou credenciais de clientes.**
P0/D02/D03, SEC-R03/Z4, T3/OPEN, IAM/MFA e AppSec/DBA
continuam sem homologação.
