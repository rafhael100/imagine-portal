# PORTAL-F35 — Confirmações persistentes de revogação observada em laboratório

**09/11/2026 — Laravel/PHP + PostgreSQL 18 descartável, sem operação com clientes.**

## Problema resolvido na PoC

Até F34, o reconciliador lê o ledger inteiro de até 20 revogações
publicadas na segunda instância e tenta aplicá-las novamente a cada
execução. Não existia confirmação persistida de que um evento já havia
sido aplicado e observado na primária. Um erro de SELECT podia ser
confundido com histórico vazio se o protocolo fosse estendido sem cuidado.

F35 introduz recibos **apenas no witness fictício** e separa explicitamente:

1. *published*: piso monotônico e evento idempotente publicados no
   banco witness por F33, antes de alterar a primária;
2. *applied*: sessão ou membership fictício marcado como revogado e
   atualizado na primária por F33/F34;
3. *observed*: fonte SQL de leitura **tenant-bound** observou
   `mock_state='revoked'`, revisão maior ou igual à do evento, e
   no caso membership `can_view_services=false`;
4. *acknowledged*: função SQL restrita adicionou um único recibo de
   confirmação do evento no banco witness.
   
Um evento sem recibo permanece pendente, mesmo que sua escrita tenha
sido concluída. Repetir o lote aplica idempotentemente a mesma alteração,
confirma de novo e grava o recibo, sem reduzir pisos.

## Contratos criados

- `acknowledgement-schema.sql` (somente segundo container): cria
  `f35.applied_receipt` com chave única `event_id`, FK para o
  ledger F33, RLS `ENABLE/FORCE`, role exclusiva
  `f35_acknowledgement_writer` sem SELECT/UPDATE/DELETE direto
  nas tabelas e função `f35.confirm_observed_primary(...)`
  com `SECURITY DEFINER`, `search_path` fixo, checagem do
  vínculo do ID do evento (empresa/tipo/assunto), revisão mínima
  e rejeição de replay conflitante.
- `F35PrimaryStateVerifier`: duas conexões SQL somente leitura,
  cada uma com identidade SQL fixa `f21_org_a/f21_org_b`.
  Consulta somente a sessão/vínculo fictício do seu próprio tenant
  com predicados preparados e rejeita status não revogado,
  revisão defasada, divergência de papel SQL e permissão ainda ativa.
- `F35WitnessAcknowledgementWriter`: conexão com role SQL de
  confirmação no witness. Só executa a função restrita depois da
  observação primária ter sido concluída. Chamada direta por outro
  código de laboratório **ainda pode mentir** sobre essa observação;
  não é uma atestação de autoridade externa.
- `F34SyntheticLedgerReader::readBatch(onlyUnacknowledged: true)`:
  estende o contrato original de F34, preservando `readBatch()`
  para testes antigos. Em modo F35 exige FORCE RLS/policy da
  tabela de recibos e retorna somente eventos **sem recibo**.
  Erro, permissões ausentes, schema alterado ou mais de 20
  eventos pendentes retornam `null`, nunca falso "vazio".
- `F35AcknowledgedReconciliationRunner`: rodada explícita
  (somente CI) que publica/revalida o witness antes de modificar
  a primária, observa o estado com role do tenant, grava confirmação
  no witness, e interrompe com status `primary_apply_pending`,
  `primary_unconfirmed`, `ack_pending` ou `ledger_unavailable`
  sem informar conclusão de lote parcial.

## Testes adversariais em PostgreSQL 18 real e descartável

A suíte `F35AcknowledgedReconciliationTest.php` testa:
fila realmente vazia; publicar/aplicar/confirmar e reprocessar como
fila vazia; revogar EXECUTE da função de ACK e recuperar em nova
tentativa; papel SQL do tenant incorreto; RLS FORCE de recibos
removido; replay de recibo idêntico x diferente; impossibilidade de
escrever diretamente com a conta ACK; sessão da empresa A e
membership da empresa B; recusa de lote acima do limite.

O workflow F22–F34 carrega schema F35 no **segundo banco**, cria
credencial SCRAM aleatória para ACK no runner e executa os testes
PHP 8.3 e PHP 8.4. O ensaio F32 com `pg_dump/pg_restore`
real da base fictícia primária continua na etapa final do CI.

## Limitações para evitar falsa homologação

**Persistência em um container temporário ≠ durabilidade em produção.**
O GitHub Actions apaga tudo ao final; o witness e a primária continuam
no mesmo domínio administrativo, sem HSM/WORM, assinatura de eventos,
protocolo de consenso, resiliência a restauração simultânea ou
observabilidade. Um cliente malicioso ou operador com acesso ao
código/DB pode forjar confirmações. O uso de `SECURITY DEFINER`
é apenas experimental e precisaria de revisão de AppSec/DBA.

Não há scheduler/worker, outbox real, ack transacional entre
ambas bases, evento proveniente de identidade confiável,
enfileiramento com backoff, particionamento, retries duráveis,
dead-letter, controle de custo ou garantias de entrega. O teto
de 20 eventos pendentes é de laboratório e não suporta escala.

**Nenhum serviço/rota HTTP foi registrado.** Todos os métodos de
autorização real do DTO continuam retornando false. Sem login real,
MFA, CRM SSO, dados pessoais, contratos, VPS Leaseweb, Cloudflare,
pagamentos, deploy ou merge na `main`/`dev`. P0,
D02/D03, SEC-R03/Z4, T3/OPEN e AppSec/DBA permanecem abertos.
