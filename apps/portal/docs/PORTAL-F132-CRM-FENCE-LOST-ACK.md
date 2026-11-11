# Portal F132 — Reenvio proibido após HTTP com código de CRM de verdade

O Portal F131 validou a integração entre a classe PHP F119/F122 do Portal e a implementação de laboratório PHP F3-41/F3-42 do CRM, em dois PostgreSQL distintos. Mas o roteiro anterior do CRM fazia a reserva e iniciava a transmissão sem testar a barreira adicional F123, introduzida no Portal para impedir que uma queda entre HTTP e `note_unknown` permita um segundo envio.

A F132 aplica `dispatch_fence_pg18.sql` no PostgreSQL 18 descartável depois das migrations F118/F119/F122, mantendo o receptor do **código de laboratório do CRM**, fixado no commit público `401dbaf59e58965480a5b41dab43aebb3b023aa2`, em PostgreSQL 16 separado.

Novo teste `test_real_crm_fence.php` executa:
1. Ledger e outbox reais da classe PHP Portal F119; estado inicial sem registro no banco do CRM.
2. Reserva `claim_lab_a` e `begin_dispatch_lab_a` com COMMIT **antes** de emitir a primeira requisição HTTP.
3. CRM F3-41 recebe HMAC e faz COMMIT no seu PostgreSQL 16, mas simula perda da resposta 503 sem recibo.
4. O cenário reproduz a queda do worker **antes de registrar `unknown`**: lease expira na fixture, e a tentativa de reobter/reservar envio é recusada porque a versão atual está em `dispatching`.
5. O titular da versão marca `unknown`, consulta o destino com domínio HMAC separado e valida recibo sintético por HMAC, mas **não** limpa a retenção nem confirma entrega ao visitante.
6. São exigidos exatamente um registro e um nonce do lado do CRM, sem segundo envio.

A infraestrutura permanece totalmente efêmera em GitHub Actions, PHP 8.3/8.4, duas instâncias PG16/PG18 em loopback e chaves criadas durante o job. Não há rota, cliente, empresa, CRM operacional, API TLS, assinatura de consentimento, migração real nem credenciais de produção.

**NO-GO** até revisão independente AppSec/DBA/LGPD [#315](https://github.com/rafhael100/imagine-portal/issues/315), tenant binding e sessão autenticada reais, reconciliação confiável, logging e infraestrutura de homologação. Sem `main`, deploy, VPS ou novas despesas.
