# SEC-P02-R05-QA-01 — Caderno de testes adversariais sintéticos para cinco P0

**Data:** 07/11/2026. **Status de todos os casos:** PLANEJADO / NÃO EXECUTADO. Nenhum teste PHP, PostgreSQL, HTTP ou infraestrutura foi realizado.
**Objetivo:** especificar futuros casos reproduzíveis que comprovem autoridade Portal, concorrência de revogação, isolamento multiempresa e limites de RLS. Complementa ID-A01..06, REV-01..10, R02-N01..15, R03-H01..08, R03-P01..07 e H/C/P do PR #19; não elimina esses casos.

## A. Pré-condições e oráculos de segurança

**Ambiente futuramente necessário, ainda não autorizado:** instalação descartável, duas organizações fictícias A e B sem relação, três atores fictícios (uA apenas A, uB apenas B, uAB em ambas), dois gestores em A e um em B, sessões Portal distintas, fatores dummy seguros, papéis/grants com revisões, recurso privado em A e B, conta de runtime sem privilégios administrativos e atores de manutenção separados. Identidade CRM fake com ID textual propositalmente igual ao Portal, mas outro namespace, só para teste de confusão.

**Sincronização dos testes concorrentes:** controlar interleavings por pontos de barreira observáveis sem produzir bypass no produto. Validar ambos os lados de cada ordem T1→T2 e T2→T1, incluindo falha/timeout/deadlock. Não inferir ordenação apenas por sleep. Observar commit de revogação e commit/negação de efeito sob lock; não basta status HTTP.

**Evidência mínima por cenário:** SHA de código e fixtures, versão PostgreSQL/php/driver/pool, role SQL efetiva, IDs fictícios A/B, política e revisions antes/depois, transações e ponto de corte, evento de auditoria minimizado, SQL/HTTP permitido vs negado sem segredos, prova de **ausência de efeito e leitura indevida**, resultado PASS/FAIL/BLOCKED, risco residual e responsável pelo parecer. Não inventar PASS.

## B. Matriz de casos

| ID | P0 / cenário sintético | Ação adversarial ou corrida | Resultado esperado/limitação |
| --- | --- | --- | --- |
| R05-01 | R01-01 — confusão CRM e Portal | Cookie, senha, ator textual, e-mail/CNPJ e header MFA da CRM apresentados ao Portal | Não emite sessão humana Portal, não concede membership/tenant; resposta não enumera identidades |
| R05-02 | R01-01 — DTO e header falsos | Forged actor/organization/grants/role/mfa em request sem sessão emitida | Não cria allow nem lê recurso privado; rastreio indica origem não autorizadora |
| R05-03 | R01-01 — pré-MFA e replay | Desafio primary_verified, token repetido, expiração e factor_pending | Sem lista privada/tenant e sem sessão de negócio; single use com consumo atômico |
| R05-04 | R01-01 — bootstrap | Cadastro/convite/pagamento sem mandato para primeiro gestor | Nenhuma atribuição owner/staff ou vínculo ativo automática; pendência de alçada reportada |
| R05-05 | R01-02 — logout global vs escrita | T1 tenta efeito interno, T2 revoga session_epoch | Se T2 lineariza primeiro, T1 nega sem efeito; se T1 confirma primeiro, T2 não desexecuta o efeito |
| R05-06 | R01-02 — reset/MFA vs emissão | T1 emite sessão após fator antigo; T2 muda fator/revision | Sessão antiga/emitida com epoch stale não autoriza; nenhum token ressuscitado |
| R05-07 | R01-02 — ator multiempresa | uAB opera em A e B, conta suspensa durante espera | Nenhuma ação posterior ao corte é aceita em A ou B; tenants não relacionados continuam progredindo |
| R05-08 | R01-02 — deadlock, fail e replay | Erro entre validação/commit, conexão cai, retry do mesmo idempotency_key | Sem ação duplicada nem allow reaproveitado; timeout/estado incerto força revalidação |
| R05-09 | R01-03 — role vs efeito | T1 lê role/grant local, T2 remove role em O antes da escrita | T1 não usa grant obsoleto após corte; ambas as ordens registradas |
| R05-10 | R01-03 — catálogo global | T1 uA e T3 uB autorizam; T2 altera capability global | Barreira de política disputa efeitos dependentes; isolamento entre tenants independentes fora da mudança global |
| R05-11 | R01-03 — concedente/alvo | Gestor inicia concessão, perde poder enquanto T1 aguarda | Não outorga role antiga, sem autoconcessão ou reingresso com grant ressuscitado |
| R05-12 | R01-03 — dois últimos gestores | Dois gestores A removem/rebaixam um ao outro simultaneamente | Sem estado normal sem gestor por write skew; suspensão por segurança ainda possível e recuperação restrita |
| R05-13 | R01-09 — link cruzado | Em A criar assignment com role_id válido somente em B | FK tenant composta rejeita ou operação autenticada nega; zero referência cross-tenant |
| R05-14 | R01-09 — tenant imutável | UPDATE ou import para trocar organization_id de recurso A para B | Nega/aborta, sem owner adulterado ou cópia silenciosa |
| R05-15 | R01-09 — recurso fora do escopo | Ator uA acessa recurso B por ID conhecido e por listagem sem filtro | Nenhum conteúdo, metadado, efeito ou enumeração de recurso B |
| R05-16 | R01-09 — descoberta e erro de constraint | Forçar e-mail/role/ID existente em B por consulta de A ou FK/unique error | Não retorna ID, contagem ou detalhe de B; canal lateral residual documentado |
| R05-17 | R01-10 — RLS DML | SELECT/INSERT/UPDATE/DELETE em tenant B sob runtime_web de A | Zero linhas/dados/efeitos indevidos, policy USING/WITH CHECK conforme comando |
| R05-18 | R01-10 — roles e functions | Runtime tenta SET ROLE owner, BYPASSRLS, CREATE, TRUNCATE, EXECUTE elevado, função/view bypass | Falha com privilégio runtime real; inventário completo de grants/roles |
| R05-19 | R01-10 — policy composition | Adicionar policy permissiva OR simulada que abriria linhas B | Auditoria/ensaio deve detectar composição insegura; resultado como FAIL até correção, não mascarar |
| R05-20 | R01-10 — contexto SQL ausente e pool | Request/job A seguido de B na mesma conexão, rollback/savepoint/erro | Contexto anterior não permite A→B; afinidade e limpeza verificadas |
| R05-21 | R01-10 — SQL sem filtro vs contexto forjado | Separar consulta omissa de filtro de SQL arbitrário que falsifica actor/tenant em SET LOCAL | Primeira pode ser contida pela RLS; **segunda pode obter acesso no modelo A** e deve ser reportada como risco, sem declarar PASS |
| R05-22 | R01-10 — restore anterior à revogação | Recuperar snapshot com session/grant antigos sem epoch de corte | Servir privado permanece fechado até reconciliação ou invalidação segura; nenhuma sessão ressuscita |

## C. Casos complementares condicionantes

- Long streaming/read/export pode materializar bytes antes de uma revogação; definir a unidade/corte de leitura e demonstrar o que não pode ser retirado depois de entregue.
- Outbox/serviço externo podem receber efeito antes da revogação; distinguir accepted/rejected/unknown e capacidade de fencing do receptor. O experimento futuro não usará provedores reais.
- MFA recovery, last manager, bootstrap e tratamento de dados pessoais têm processos de Produto, Segurança e Privacidade ainda a especificar; resultado BLOCKED é correto quando a alçada não está definida.
- Performance: a promessa de tenants independentes exige medir p95/p99, filas, waits, deadlocks e fairness sob concorrência fictícia com metas a aprovar. Não fixar um número arbitrário para dizer que escala.

## D. Regra de saída QA

Não fechar P0 por testes em memória, mocks, SQLite, uma mera contagem de PHPUnit ou HTTP 403. Um P0 requer contrato final revisado, implementação autorizada, testes pertinentes reais sobre papel SQL e driver previstos, evidência persistida e decisão explícita de gate pela Direção. Codex/ChatGPT/parecer não aprovam sozinhos. Nenhum laboratório, código ou banco foi autorizado por este caderno.
