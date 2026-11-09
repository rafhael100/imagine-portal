# PORTAL-F40 — Consumo simulado de outbox, sem conexões externas

**09/11/2026 · PHP/Laravel + PostgreSQL 18 temporário · somente GitHub CI.**

## Contexto

F39 grava o estado, o recibo idempotente e o evento fictício da
outbox na **mesma transação PostgreSQL**, mas não tem consumidor.

F40 acrescenta uma demonstração do **caminho de leitura e
confirmação**, inteiramente isolada: não há e-mail, webhook,
CRM, Sites, agentes, automação externa, HTTP ou fila real.

## Código implementado

- `relay-schema.sql`: papéis SQL distintos
  `f40_relay_a` e `f40_relay_b` somente leitura da outbox
  F39 por empresa; políticas SELECT adicionais sob FORCE RLS.
  Tabela `f40.fake_delivery_receipt` com FK para a outbox,
  PK tenant/comando, RLS por tenant e sem INSERT/UPDATE/DELETE
  direto para as contas relay. Confirmação apenas por
  `SECURITY DEFINER f40.record_fake_delivery(...)`, com
  `search_path` fixo, vínculo organização/conta SQL,
  referência esperada e SHA-256 do JSONB armazenado.
- `F40InMemoryFakeSink`: receptor de laboratório
  **somente em memória**, sem I/O externo. Pode simular falha
  única e reconhecer reenvio idêntico; não persiste efeitos.
- `F40SyntheticOutboxRelay`: lê até dez eventos pendentes
  por tenant em transação `REPEATABLE READ, READ ONLY`,
  com função SQL de identidade do papel, RLS e validação
  estrita de envelope sem PII. Usa o sink fictício e somente
  após sucesso grava o recibo simulado na tabela do
  PostgreSQL temporário. Erro de leitura, sink ou SQL ACK
  produz status parcial e não marca a fila como concluída.
- `F40FakeRelayOutcome`: resultado com contagem e status
  `complete/source_unavailable/sink_unavailable/ack_pending/
  bounded_backpressure`. Ambos
  `deliveredExternally()` e `authorizesCustomerAccess()`
  retornam **false em qualquer caso**.
- `F40SyntheticOutboxRelayTest.php`: dez testes em PG18
  real e descartável: fila vazia, duas empresas, repetição
  sem duplicação, falha de sink e retry, falha de EXECUTE
  ACK e retry, perda de SELECT, FORCE RLS desabilitado,
  isolamento e negação de DDL/DELETE/UPDATE, role admin
  rejeitada, hash forjado e dois eventos sequenciais.
- GitHub Actions carrega schema F40 após F39 na base
  primária temporária, gera credenciais SCRAM aleatórias
  para as duas roles de relay e executa testes em PHP
  8.3 e 8.4 junto com a regressão F22–F39 e o ensaio
  de restauração F32.

## Limites de segurança e escala

**Um fake ACK NÃO é uma entrega externa.** A função SQL
verifica apenas que o JSONB do evento fonte corresponde
ao hash declarado; ela não pode comprovar que uma mensagem
foi recebida por terceiro. O processo PHP pode mentir
sobre sucesso, o sink está em memória, e o superusuário
do container controla todas as tabelas e as chaves.

F40 não implementa autenticação Portal-only, identity federation,
MFA, outbox de produção, fila com retry persistente,
distribuição de leases, assinatura de eventos, DLQ, purge,
monitoramento, SLA, tempo real, consentimento LGPD ou
integração com CRM/Sites/Performance. Cada rodada tem
limite artificial de dez eventos por tenant e exige
invocação explícita; não há scheduler ou worker ativo.

A arquitetura produtiva exigirá um emissor de identidade
confiável, escopos e memberships reais, PostgreSQL/RLS e
AppSec/DBA revisados, schema migrations com backup validado,
e um mecanismo de entrega independente com idempotência
de destino, transporte seguro e políticas de retenção.

**PR DRAFT, sem merge em main/dev e sem deploy.**
Não há gastos com fornecedores novos, alteração na VPS
Leaseweb ou Cloudflare, credenciais reais, cobrança,
contrato ou cliente. P0/D02/D03, SEC-R03/Z4,
T3/OPEN e demais gates continuam abertos.
