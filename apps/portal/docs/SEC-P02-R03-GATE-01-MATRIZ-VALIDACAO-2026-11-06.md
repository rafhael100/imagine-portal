# SEC-P02-R03-GATE-01 — Matriz de validação e revisão das arquiteturas D01-B / D02-A reforçada

**Data:** 06/11/2026. **Estado:** R03 DOCUMENTAL, sem teste executado e sem homologação. Diretrizes D01-B e D02-A aprovadas em SEC-P02-R02-DEC-01, detalhes R03 sujeitos a validação DBA/AppSec e nova decisão de Produto quando impactarem alçada, SLO, custo ou ameaça.

## A. Rastreabilidade por risco

| Achado em aberto | Contrato complementar | Prova negativa futura | Gate / parecer |
| --- | --- | --- | --- |
| R01-01 (emissor Portal) | R02-ADR01, R03-PG01 §1/6 | CRM cookie/ID/e-mail/CNPJ e DTO de ator não originam sessão Portal | G-ID01/G-BB03; Identidade/AppSec |
| R01-02 (revogação sessão/conta) | R03-HIER01 §2/3 | Suspensão/logout/reset em corrida com efeito em duas empresas | G-ID03/G-BB06; AppSec/DBA |
| R01-03 (role/org/global) | R03-HIER01 §2/4 | Modificar role, tenant policy e catálogo enquanto efeito pendente | G-ID05/G-BB04/06; DBA |
| R01-09 (integridade tenant) | R03-PG01 §2–4 | FK composta, owner imutável e DML cross-tenant sob role real | G-BB02/03/05; DBA |
| R01-10 (limite RLS) | R03-PG01 §4–6 | Contexto ausente, policy OR/role bypass, SQLi forjando parâmetros e risco residual declarado | G-BB05; DBA/AppSec |
| R01-04/05 (retry/último gestor) | R03-HIER01 §4/5 | Retry após deadlock e duas revogações simultâneas | G-BB04/06; Produto/DBA |
| R01-06/07/08 (MFA/cookies) | R02-ADR01 histórico, R03-PG01 §1 | Bootstrap/recuperação, replay, CSRF, rotação concorrente | G-ID02/04/09; AppSec |
| R01-11/12/13 (pool/jobs/restore) | R03-HIER01 §5 e R03-PG01 §6 | Pool A→B, job após revoke e restauração de credencial antiga | G-BB06/07; Plataforma/DBA |
| R01-14 (rastreabilidade) | este documento | Evidência por SHA/version/ação/negação/ausência de efeito | G-BB08; QA |

## B. Casos de concorrência essenciais

| ID | Interleaving / expectativa | Prova mínima obrigatória |
| --- | --- | --- |
| R03-H01 | Ação de A e ação de B simultâneas sem política/ator compartilhado | Progresso sem lock exclusivo global por operação e sem vazamento |
| R03-H02 | Membership revogada antes do commit de efeito | Efeito posterior negado, sem mutação e com ordenação observável |
| R03-H03 | Conta/sessão revogada em A enquanto ator opera em B | Efeito posterior à barreira negado também em B |
| R03-H04 | Catálogo global sofre alteração durante múltiplos tenants | Alteração disputa barreira P; grants antigos não passam |
| R03-H05 | Role/grant local modificado entre leitura e escrita | Exigir releitura e disputa de O com autorizações afetadas |
| R03-H06 | Duas remoções do último gestor | Sem write skew ou staff automático; suspensão de segurança não é impedida |
| R03-H07 | Falha de banco, deadlock, timeout e retry | Rollback, idempotência, nova autorização, ausência de duplo efeito |
| R03-H08 | Exports em lotes e integração outbox após revogação | Revalidação a cada unidade e fencing no receptor ou risco residual expresso |

## C. Casos PostgreSQL/RLS essenciais

| ID | Teste futuro | Evidência mínima |
| --- | --- | --- |
| R03-P01 | Busca/UPDATE/INSERT/DELETE de tenant B com ator A | Ausência de linhas, efeito e vazamento; role efetiva registrada |
| R03-P02 | FK role B em assignment A, UPDATE de tenant e órfão de membership | Constraints e response sem enumeração; integridade sempre |
| R03-P03 | Ausência/mistura de contexto de sessão, savepoint, pool e worker | Nenhum contexto anterior efetua acesso |
| R03-P04 | Policy permissiva adicional, SECURITY DEFINER, views, triggers, SET ROLE | Inventário e resultado por caminho; zero bypass oculto |
| R03-P05 | SQLi sem filtro vs SQLi que forja completamente contexto | Distinguir garantia de RLS e risco que opção A não cobre; não inventar "nega" |
| R03-P06 | Restore pré-revogação | Invalidar sessões/grants antigos ou permanecer fechado |
| R03-P07 | Concorrência, throughput e starvation em locks globais/shared e por org | p95/p99 e espera registrados contra metas aprovadas futuramente |

## D. Critérios de relatório para eventual laboratório (NÃO autorizado)

Para cada negativo será preciso SHA, PostgreSQL/driver/pool/role efetivos, dados fictícios A/B, início/fim de transações, ordem dos locks, revisão antes/depois, queries autorizadas e negadas, prova de zero efeito/vazamento, logs sanitizados, comportamento sob retry, latência e janela residual. Um teste de retorno HTTP 403 ou mock PHP isolado não basta. Revisões humanas não estão designadas; status de todos casos é PLANEJADO/NÃO EXECUTADO.

## E. Pontos que a Direção ainda decidirá separadamente

1. Nível exato de garantia de revogação para leituras, exports e serviços externos; não prometer remoção retroativa de bytes já entregues.
2. SLO e volume de concorrência, disponibilidade do primário e custo/contenda dos locks globais shared (sem números arbitrários).
3. Política de último gestor/reingresso e bootstrap/recuperação de MFA, sem bypass staff.
4. Se o risco de contexto SQL totalmente forjável pode ser aceito sob o modelo D02-A ou exige autoridade separada. **Não foi aceito pelo simples voto por D02-A.**
5. Baseline real PostgreSQL/driver/pool, desenho físico e autorização explícita de laboratório sintético.

**Gates D02-SEC01–06, G-ID01–09, G-BB01–08, D03/RLS, P0 e release continuam abertos. Sem mudança main/dev, login/MFA, SQL/DDL, migrations, banco, VPS, integração, despesas, merge ou deploy.**
