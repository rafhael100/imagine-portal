# PORTAL-F35 — Confirmação persistida por evento e revisão do estado primário (somente CI)

**09/11/2026 · Continuação do F34; exclusivamente dois bancos PostgreSQL 18 efêmeros em GitHub Actions.**

## Problema testado

F33 publica eventos no banco testemunha antes de alterar a sessão/membership do
banco principal. F34 reconcilia os eventos repetidamente, mas não registra quando
uma operação terminou. Uma queda **após o commit da primária e antes de qualquer
confirmação** exige retry sem confundir execução parcial com sucesso.

Uma simples marca de evento concluído também seria insegura: depois de uma
restauração antiga da primária, a marca no testemunho pode permanecer presente
enquanto a sessão volta a aparecer ativa. F35 **nunca considera um ack antigo
suficiente**, revalida o estado primário sob a identidade SQL restrita de sua
empresa antes de dispensar a reaplicação.

## Implementação

- `labs/service-relations-f35/ack-schema.sql`: no **witness descartável**,
  cria `f35.applied_event_ack` com chave `event_id` referenciando o ledger
  F33 e campos de comparação exata da carga publicada. O usuário de teste
  `f35_ack_worker` possui somente SELECT do próprio ack e EXECUTE da função
  `f35.ack_applied(...)`. A função `SECURITY DEFINER` tem `search_path`
  fixo, confere o chamador e exige correspondência exata a um evento já
  existente antes de gravar. Repetições idênticas retornam `replayed`;
  alteração de identidade ou conteúdo não substitui ack existente.
- `F35WitnessAckStore`: retorna estado tri-valuado `true` (confirmação
  correspondente presente), `false` (ausência efetivamente confirmada)
  ou `null` (fonte indisponível, role indevida, conteúdo adulterado).
  Não transforma exceção/indisponibilidade em ausência.
- `F35PrimaryStateVerifier`: usa conexões SQL `f21_org_a` e
  `f21_org_b` do **banco principal**, com transação de leitura,
  identidade SQL esperada, `FORCE RLS` e `mock_state='revoked'` com
  revisão >= à do evento. Usa `false` quando o estado ainda não foi
  aplicado; `null` para indisponibilidade ou perda de confiança.
  Revisa o estado mesmo quando o witness já tem ack.
- `F35AcknowledgedReconciliationRunner`: varre o ledger limitado da F34.
  Para cada evento, verifica ack e estado principal. Se o primário não
  estiver revogado, invoca o publicador F33 idempotente e relê a primária.
  **Somente depois de verificar que o estado primário está revogado**
  registra o ack no witness. Em qualquer falha interrompe o processamento,
  retorna `completed=false` e informações parciais; não cria endpoint,
  job recorrente ou autorização de cliente.
- `F35ReplayOutcome`: contagens verificadas, novos acks e reparos no
  estado primário, exclusivamente telemetria sintética.
- `tests/Integration/F35AcknowledgedReconciliationTest.php`: dez cenários
  adversariais, incluindo queda após o commit primário antes do ack,
  falha de EXECUTE da função de ack, restore de sessão primária mesmo
  com ack anterior, evento inexistente ou alterado, role ack incapaz
  de DML, role primária indevida, dois tenants/tipos e SELECT de ack
  revogado. Cada cenário usa fixtures, restaura os dados ao final e não
  cria nenhuma permissão ou token verdadeiro.

## Decisões e falhas que permanecem

- O ack está **no mesmo witness PostgreSQL F31/F33**, não num domínio
  independente nem em armazenamento imutável. Sua presença não é um
  testemunho criptográfico de que a primária foi alterada; um ator malicioso
  com credencial do publicador/ack ou superusuário pode falsificar estados.
- A função SQL do witness não consegue verificar diretamente um commit
  confirmado na primária. A ordem `primary verify -> ack` é imposta
  pelo orquestrador PHP **de laboratório** e não por uma transação
  distribuída. Mudanças na primária após a verificação não são vistas
  automaticamente; a F35 verifica novamente quando rodada.
- O leitor F34 limita a 20 eventos acumulados sem paginação: F35 herda
  esse teto, **não é um motor escalável**, não apaga eventos nem usa
  checkpoint como substituto do histórico.
- O F33 usa um escritor administrador **somente no banco inventado**;
  isso é inaceitável como desenho operacional. A identidade confiável
  do Portal e a política de consentimento/escopo/membership não foram
  integradas.
- Não há scheduler real, garantias de liveness, retry com backoff,
  dead-letter, monitoramento, alerta, timeout de negócio, ack durável
  após desastre de witness, quorum, mensagens assinadas, ratchet de
  revisões, gestão de chaves, replicação ou RPO/RTO.
- A proteção de revogação distribuída / anti-rollback SEC-R03/Z4,
  autenticação real/MFA, homologação AppSec/DBA e gates P0/D02/D03,
  T3/OPEN **permanecem abertos**. A prova Ed25519 Portal–Performance
  no ramo paralelo não é um emissor universal de sessões.
- Não instalar esses schemas na VPS nem conectar a Cloudflare, CRM,
  Sites, Performance, Universidade, n8n ou dados reais.

**Estado:** PR DRAFT filho de F34; sem merge em `dev`/`main`, sem
deploy e sem contratação de fornecedores. GitHub Actions pode consumir
minutos conforme o plano. O backend principal do Portal permanece Laravel/PHP.
