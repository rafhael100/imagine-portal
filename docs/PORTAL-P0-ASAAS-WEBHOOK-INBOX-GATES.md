# Portal P0 — Inbox de webhooks Asaas autenticados, PostgreSQL18 e idempotência

## Objetivo

Um pagamento só poderá influenciar um contrato depois da conciliação **independente** do estado oficial do Asaas (F1-390). Esta PR F1-391 resolve a etapa de **receber, autenticar e persistir** notificações sem confiar nelas para ativar sites.

Documentação oficial:
- https://docs.asaas.com/docs/receba-eventos-do-asaas-no-seu-endpoint-de-webhook
- https://docs.asaas.com/docs/webhooks-e-eventos
- https://docs.asaas.com/docs/eventos-de-webhooks
- https://docs.asaas.com/reference/recuperar-uma-unica-cobranca

### Fluxo preparado

1. Um controller futuro deverá obter exclusivamente o método HTTP, header `asaas-access-token`, `Content-Type` e corpo bruto, com token de webhook oriundo de vault, nunca `access_token` da API ou parâmetro do cliente.
2. `AsaasWebhookPgInbox::capture()` exige POST com JSON (32 KiB no máximo), token comparado com `hash_equals`, evento e cobrança identificados.
3. Insere numa tabela PostgreSQL18 com chave `(account_ref,event_id)`, protegida por **FORCE RLS**, em transação. A repetição idêntica retorna `duplicate=true`; conteúdo divergente com mesmo ID aborta.
4. Armazena **apenas** conta PSP de origem confiável, ID do evento, tipo de evento, ID de cobrança, hash do payload, timestamp e estado `pending_reconciliation`. Nenhum corpo bruto, senha, cartão, cliente, CPF/CNPJ ou preço é persistido por esta etapa.
5. Somente após `COMMIT` o controller futuro poderá responder HTTP 200. A entrega do evento não é o pagamento: um worker **ainda não implementado** terá de buscar a cobrança de novo no Asaas e comparar pedido, tenant, oferta e recibos com o leitor oficial F1-390.
6. Esta PR não cria o controller, não abre rota pública, não executa migração na Leaseweb e não altera pagamento/serviço/assinatura.

### Provas de CI

O SQL `asaas-webhook-inbox-ci.sql` é **exclusivo de laboratório**: usa senha fictícia, conta fictícia e PostgreSQL18 descartável, cria login SQL sem superuser/BYPASSRLS, policy de uma conta, acesso SELECT/INSERT sem UPDATE/DELETE. Não aplicar este SQL na VPS! A migration de produção exigirá revisão de DBA e identidades reais.

A matriz PHP 8.3/8.4:
- Rejeita token falso, GET, MIME errado, JSON malformado e oversized.
- Grava um `PAYMENT_RECEIVED` fictício com estado pendente, deduplica repetição exata.
- Rejeita repetição de ID com payload diferente, sem sobrescrever o registro original.
- Grava evento posterior de estorno como novo pendente; não reativa site, contrato ou prestação.
- Verifica política RLS, negação de outra conta PSP e ausência de permissões SQL de UPDATE, DELETE e SET ROLE.
- Remove o banco PostgreSQL do runner após terminar.

**NO-GO operacional:** ainda faltam endpoint HTTPS protegido, segredo provisionado no Asaas Sandbox, migration SQL autorizada com backup, worker/reconciliador oficial idempotente, assinatura do contrato, PSP verificado com revogação, S2S/IAM, testes de Pix Automático/cartão recorrente, notas fiscais e revisão AppSec/DBA/LGPD.

**Nenhum webhook fictício ou aprovação em testes pode ativar serviço.**
