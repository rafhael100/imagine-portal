# PORTAL-F37 — Diario de comandos de solicitações: versão otimista e idempotência

**09/11/2026 — Domínio PHP puro, somente dry-run em memória.**

## Objetivo funcional

O Portal já modela o fluxo de solicitação de serviços (rascunho,
enviada, em análise, aguardando cliente, resolvida e encerrada).
A função `ServiceRequestWorkflowPreview` sabe propor transições,
mas não existe persistência transacional nem proteção contra a
repetição do mesmo comando após uma falha de rede.

F37 prepara **a semântica de domínio** do processamento, sem
introduzir uma API antes da autenticação oficial.

## Componentes

- `App\Domain\ServiceRequests\DemoRequestCommand`:
  um comando de teste com ID `DEMO-CMD-*`, ID da solicitação,
  `OrganizationId`, papel de negócio, ação, revisão esperada
  e carimbo temporal sintético. Gera impressão digital estável
  do payload para detectar reutilização conflituosa de ID.
  A impressão digital NÃO é assinatura criptográfica.
- `DemoRequestJournal`:
  estado em memória do pedido; valida a empresa e a referência
  **antes** da consulta ao cache idempotente; rejeita revisão
  defasada e ações proibidas pela máquina de estados já existente.
  Um comando válido gera um único evento fictício de transição
  e aumenta a revisão. Reenvio exatamente igual devolve o
  mesmo resultado original sem criar outro evento; reenvio com
  payload modificado e mesmo ID é bloqueado.
- `DemoRequestJournalOutcome`:
  razões explícitas de sucesso apenas em memória ou recusa.
  Os métodos `mayWriteDatabase`, `mayAuthorizeHttp` e
  `mayCreateContractOrCharge` retornam sempre **false**.
- Limite: no máximo **50 eventos** de teste na instância;
  não é política de produção ou retenção LGPD.
- `DemoRequestJournalTest.php`: nove testes unitários para
  ciclo completo, replay idêntico depois de evoluir o pedido,
  conflito de chave, empresa e recurso diferentes, revisão
  stale, papel de negócio incorreto, IDs inválidos, limite
  de eventos, determinismo e impossibilidade de autorização.

## Não implementar automaticamente

- O ator de negócio `Customer`/`ImagineTeam` é entrada
  **não confiável** nesta simulação, não identidade autenticada.
  Deve ser derivado de sessão oficial + membership, e não do
  formulário, ao construir um comando real.
- Em produção, idempotência e revisão precisam ser verificadas
  **na transação do PostgreSQL**, com chave/índice único por
  organização e recurso; armazenamento só em memória não
  protege concorrência entre processos, restauração de backup,
  cache perdido, worker duplicado ou requisições simultâneas.
- Um evento de diário não equivale a audit log assinado,
  outbox durável, consentimento, contrato ou cobrança.
- Ainda faltam validação de serviço ativo/ownership, permissões
  reais, payloads de formulário, anexos seguros, notificações,
  SLA, observabilidade, esquema SQL, migrações, integração
  com autenticação do Portal e testes completos multiempresa.

**Não foi registrada nenhuma rota Laravel, middleware, controller,
provider, job, banco real ou gravação de cliente.** PR DRAFT sem
merge/deploy; Laravel/PHP continua backend principal.
Node.js fica apenas como ferramenta/legado. P0/D02/D03/SEC-R03/
Z4/T3/OPEN, IAM/MFA, AppSec/DBA permanecem bloqueados.
