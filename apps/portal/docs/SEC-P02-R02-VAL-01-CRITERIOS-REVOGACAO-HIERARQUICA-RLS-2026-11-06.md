# SEC-P02-R02-VAL-01 — Critérios de evidência para D01-B e D02-A reforçada

**Data:** 06/11/2026. **Estado:** CRITÉRIOS DOCUMENTAIS; NENHUM ENSAIO EXECUTADO. Complementa SEC-P02-R02-DEC-01 e a matriz histórica R02; não encerra gates nem aprova laboratório.

## 1. Contratos a fechar antes de programação

| ID | Artefato técnico revisável | Responsáveis por parecer, ainda não designados |
| --- | --- | --- |
| L01 | Inventário de fontes e writers de identidade, conta, sessões, membership, política global/local, roles, grants, catálogo, organização, entitlement, resources, filas e administração | Identidade, Backend, AppSec |
| L02 | Grafo hierárquico com locks de leitura/escrita, ordenação total, interação de múltiplas empresas, sessões, granted-by/recipient, catálogos e políticas globais | DBA, Backend |
| L03 | Ponto de linearização de revogação vs efeitos internos; snapshot/isolamento/tempo, cache, outbox, retry, leituras longas e fonte externa de sessão | DBA, AppSec, Backend |
| L04 | ERD de tenant, membership única, FKs compostas, owner, dicionário de roles/GRANTs, RLS DML, views/funções/policies, bootstrap/discovery e pool | DBA, AppSec |
| L05 | Limites de ameaça entre requisição maliciosa, query que omite tenant, SQLi capaz de forjar SET LOCAL e comprometimento de runtime. Risco residual explícito para decisão da Direção | AppSec, Arquitetura, Direção |
| L06 | Plano de desempenho com volume alvo ainda a definir, throughput, p95/p99, espera por locks, fairness, deadlocks/timeouts e indisponibilidade | Arquitetura, DBA, Plataforma |

Não presumir números SLO, custos ou PG/driver reais. A existência de L01–L06 por si só não homologa os riscos.

## 2. Negativos e casos de concorrência FUTUROS

| Caso | Falha/desafio | Critério de evidência esperado | Gates/achados |
| --- | --- | --- | --- |
| HIER-01 | Dois efeitos simultâneos de empresas independentes | Não depender do mesmo lock exclusivo global por operação; demonstrar progresso e ausência de vazamento | R01-02/03, G-BB06 |
| HIER-02 | Revogar membership enquanto há ação pendente | Após commit da revogação, novo efeito com grant antigo negado; interleaving inverso explicado | R01-02, G-BB04 |
| HIER-03 | Modificar role/grant/organização e catálogo global enquanto há ações | Todas autoridades afetadas disputam barreira compatível; nenhuma decisão obsoleta | R01-03, G-BB04/06 |
| HIER-04 | Conta suspensa, reset de MFA, logout global enquanto ator opera em duas empresas | Efeito posterior à revogação negado, sem cache positivo | R01-02, G-ID03 |
| HIER-05 | Dois gestores retiram autorização um do outro | Sem write skew, autoelevação ou staff global implícito; suspensão de segurança possível | R01-05, G-BB04 |
| HIER-06 | Deadline, deadlock, snapshot antigo e retry | Rollback e releitura; não reutilizar allow nem duplicar efeito/auditoria | R01-04, G-BB06 |
| HIER-07 | Exportação em lotes, outbox atrasada e serviço externo após revogação | Revalidar; fencing/idempotência no receptor ou documentar impossibilidade/risco | R01-11/12, G-ID07 |
| RLS-01 | Ator A envia tenant B ou cookies/DTOs do CRM | Negar acesso e existência de dados B; sem sessão Portal derivada | R01-01/09, G-ID01/05 |
| RLS-02 | SELECT/INSERT/UPDATE/DELETE de outra empresa, alteração de owner ou FK composta | Negação com role real e prova de zero efeito e vazamento | R01-09/10, G-BB02/05 |
| RLS-03 | Runtime utiliza role owner, BYPASSRLS, herança, SET ROLE, view ou função elevada | Privilégios diretos e transitivos inventariados; runtime comum não ganha bypass | R01-10, G-BB05 |
| RLS-04 | SQL injection omite tenant versus SQL arbitrário que falsifica contexto | Diferenciar a defesa possível da RLS da **falta de garantia** contra contexto inteiramente forjado na fronteira A | R01-10, G-BB05 |
| RLS-05 | Reuso da conexão entre tenants, rollback/savepoint e worker | Nenhum contexto residual; queries privadas somente na conexão/contexto corrente | R01-11, G-BB06 |
| RLS-06 | Backup anterior a suspensão, reset ou grant revogado | Bloquear reabertura até reconciliar revisões/epochs e sessões | R01-13, G-BB07 |

Todos os casos acima são apenas propostas; correlacionar com ID-A01–06, REV-01–10 e H01–12/C01–10/P01–12 da matriz R02 anterior. Não substituir prova de ausência de efeito por status HTTP 403.

## 3. Pré-condições de eventual experimento em laboratório

- Documentar e revisar L01–L06 com responsáveis reais; exigir lista de riscos residuais e divergências de parecer.
- Solicitar autorização **separada** com branch/SHA/ambiente/custos, fixtures fictícias, role runtime, critérios de parada, desmontagem e rollback.
- Usar no mínimo duas organizações fictícias com atores, sessões e grants sintéticos; coletar logs sem segredos, estado antes/depois, alterações, roles e sequência observável dos eventos.
- Testes de carga/tempo precisam meta previamente aprovada, não somente um teste que passa com duas requisições.
- Se o runtime conseguir falsificar totalmente a autoridade e a política exigir proteção contra esse adversário, não mascarar o resultado: requer nova decisão arquitetural.

**Fecho:** diretrizes aprovadas, critérios documentados, **zero testes executados**. Nenhum P0 ou gate de segurança foi fechado; nenhum código, SQL, migration, banco, merge ou deploy foi autorizado.
