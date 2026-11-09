# PORTAL-F34 — Reconciliador manual de revogações pendentes em laboratório

**09/11/2026 · Dois PostgreSQL 18 efêmeros, backend Laravel/PHP, sem serviço operacional.**

## Continuação técnica do F33

O F33 registrou os eventos de revogação **primeiro na testemunha**
com piso monotônico e ledger idempotente, e só depois aplicou a
alteração na primária. Se houver crash entre essas etapas, a
consulta F31 já bloqueia a prova antiga, mas o estado principal
permanece pendente até uma tentativa de reconciliação.

F34 demonstra **uma rodada explícita de recuperação** via PHP,
sem adicionar cron, supervisor, job Laravel, fila ou serviço externo.

## Implementação

- `labs/service-relations-f34/reconciliation-reader-schema.sql`:
  role sintética `f34_reconciliation_reader LOGIN NOINHERIT`,
  com **USAGE no schema f33 + SELECT no ledger apenas**.
  Habilita FORCE RLS no ledger, com policy SELECT exclusiva
  para a identidade SQL dessa role. A role **não** recebe
  UPDATE/DELETE, EXECUTE de publicação nem escalonamento.
- `App\Labs\F34\F34SyntheticLedgerReader`:
  consulta `f33.publication_ledger` em transação própria
  `REPEATABLE READ, READ ONLY`, com timeout, conferência
  de `session_user/current_user`, role sem superuser/bypass,
  RLS forçado e policy esperada. Busca no máximo 21 linhas:
  permite **até 20 eventos**, com ordenação estável por
  organização/sequência/ID. Recria objetos tipados F33,
  validando valores e progressão de sequência.
  Retorna `[]` apenas quando a leitura foi bem-sucedida e
  o ledger confirmado como vazio; retorna `null`
  em conexão, permissão, RLS, esquema, dado inválido ou
  lote acima do limite. Nunca converte erro em fila vazia.
- `App\Labs\F34\F34ReconciliationRunner`:
  chama a leitura do ledger e, para cada evento, invoca
  `F33WitnessFirstRevocationPublisher::finishPrimary`,
  que reafirma a publicação idempotente **antes da escrita
  do primário**. Se uma escrita falhar, para o lote e
  devolve relatório `completed=false` e contagens explícitas,
  sem apagar ledger e sem declarar a revogação concluída.
  Chamar novamente faz replay do conjunto inteiro.
- `F34ReplayOutcome`: relatório técnico sintético com
  `completed/status/discovered/applied`, **não um
  veredito de autorização** ou autorização de HTTP.
- `F34ReconciliationReplayTest.php`: oito testes em PG18
  descartável com tenants falsos, incluindo fila vazia,
  recuperação de operação publicada antes da queda,
  idempotência, dois tenants/tipos, indisponibilidade
  da leitura, bloqueio da role SQL, recusa de admin
  se passando pela role de replay, falha parcial de batch
  por advisory lock da primária e saturação acima de 20 itens.

## Limites inegociáveis

**Isto NÃO cria um agente automático em produção.** Nenhum
agendamento, automação de replay em background, entrega com SLA,
acknowledgement de recebimento, outbox transacional,
monitoramento, retry persistido, dead-letter queue, compensação,
rate limiting, estratégia de shards ou handoff operacional
foi instalado. A invocação ocorre apenas durante o GitHub CI
temporário; a classe não está ligada a rotas nem a service providers.

O ledger contém apenas eventos fictícios e sua retenção
é ilimitada neste exemplo pequeno. O replay integral tem
limite rígido de 20 eventos e **falha fechado** ao ultrapassá-lo.
Isso não é uma política de volume/retention de clientes.
Sem ack e paginação assinada, não escalar esse código.

A primária usa a conta admin **somente no banco fictício**,
e a testemunha fica no mesmo domínio administrativo do runner.
Administrador com acesso aos dois bancos pode reverter o ledger,
os pisos e o SQL. A função SECURITY DEFINER do F33 é de
laboratório e requer revisão formal se for redesenhada para
ambiente operacional.

Revogações de outros serviços que não seguem o protocolo
permanecem descobertas. Mesmo um replay bem-sucedido não
homologa identidade oficial do Portal, MFA, RLS de cliente,
governança Ed25519, revogação global linearizável,
anti-rollback SEC-R03/Z4, disaster recovery, AppSec/DBA
ou aprovação P0/D02/D03/T3/OPEN.

**PR DRAFT, SEM MERGE/DEPLOY.** Apenas containers efêmeros e
GitHub Actions; nenhuma alteração em VPS Leaseweb, Cloudflare,
main/dev, clientes, CRM, Sites, Performance, Universidade,
agentes, faturamento, logs reais ou dados pessoais.
Os minutos de CI seguem o plano GitHub, sem fornecedor novo.
