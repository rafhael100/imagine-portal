# SEC-P02-R04-VAL-01 — Matriz futura de evidências por decisão

**Data:** 06/11/2026. **Status:** PLANEJADO/NÃO EXECUTADO. Complementa R04-DEC-01, R03-HIER-01, R03-PG-01, R03-GATE-01, R02-DEC-01 e PR #19. Nenhuma decisão R04 foi ratificada.

| Decisão candidata | Artefatos/contratos exigidos | Negativas e observáveis futuros |
| --- | --- | --- |
| D01-A | Inventário completo writers + grafo de locks/modes/order + ponto de linearização e retry; target p95/p99 a deliberar | A e B independentes progridem sem G exclusivo por ação; suspensão de conta multiempresa, role/global grant em corrida; nenhuma escrita após revogação; deadlock/timeout com rollback |
| D02-A | Evidência de PG18 minor, PHP8.4, PDO_PGSQL/libpq, Laravel13 e roles reais; primário direto sem pool externo inicial | Conexão A→B, transação/rollback/savepoint, retry/timeout, contexto na mesma conexão, compatibilidade com pool quando proposto |
| D03-A | ERD tenant, GRANTs e hierarquia SQL, policies por comando e funções, views, triggers, schema privileges | Role runtime não usa BYPASSRLS/OWNER/DDL/SET ROLE elevado; cruzamento A→B negado com prova de ausência de efeito, FK composta, policy permissiva OR |
| D04-A | Threat model, revisão de sinks SQL e credenciais, severidade/risco residual para aprovação separada | Query sem filtro pode ser barrada; SQL arbitrário falsificando todo o contexto pode escapar: **não registrar teste fictício como sucesso de RLS** |
| D05-A | Matriz de quem conta como gestor elegível, suspensão, mandato e recuperação em estado restrito | Duas revogações concorrentes não criam zero gestores acidental; suspensão de conta comprometida sempre possível; nenhum staff global/convite antigo reabre grants |

### Protocolo de evidência ainda não autorizado

No futuro laboratório isolado com ao menos duas empresas sintéticas, a evidência por cenário deverá registrar SHA, versão de servidor/driver/pool, role SQL, fonte de identidade, revisões, instante da decisão e commit, sequência de locks, estado anterior e posterior, motivo de negação, ausência de efeito e vazamento, logs sanitizados e retries idempotentes. Resposta HTTP 403 sem verificação dos efeitos NÃO comprova isolamento.

Conservar crosswalk dos 14 achados R01, 7 RV01, ID-A01..06, REV-01..10 e H01..12/C01..10/P01..12 do PR #19. Nenhuma pessoa AppSec/DBA está designada, parecer independente não foi obtido e todas as provas estão pendentes.

**Governança:** Direção decide opções R04 e posterior aceitação explícita de riscos. Código, SQL, laboratório, testes operacionais, merge e deploy requerem autorização separada. Não modificar main/dev/VPS. Nenhum gate fechado.
