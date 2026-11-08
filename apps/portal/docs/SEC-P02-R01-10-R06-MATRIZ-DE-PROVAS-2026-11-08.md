# SEC-P02-R01-10-R06 — Matriz de provas e critérios de revisão

**Data:** 08/11/2026. **Issue:** #48. **Situação:** testes FUTUROS; todos NOT RUN nesta etapa.
**Fonte factual única do LAB-T07:** https://github.com/rafhael100/imagine-portal/actions/runs/42496521968, PostgreSQL 18.6/PHP 8.4.27, quatro arquivos no PR #47, ainda draft.

## 1. Contratos de ensaio futuro, sob cenário sintético/descartável

| Prova | Atacante / cenário | Oráculo / resultado mínimo | Estado |
| --- | --- | --- | --- |
| R06-N01 | Browser envia actor_id/tenant/grant/owner de B | Nenhum token/sessão privado, nenhum efeito, erro não enumerável | NOT RUN |
| R06-N02 | Credencial CRM com mesmo ID textual de Portal | Autenticador Portal nega sem emissor próprio; sem SSO implícito | NOT RUN |
| R06-N03 | SQL sem WHERE tenant sob role mínima | RLS ou interface de autoridade retorna somente A/nega; não basta HTTP 403 | NOT RUN |
| R06-N04 | SQL arbitrário altera SET LOCAL ou set_config de A→B | **A atual falha; B desejada deve impedir que esse SQL seja executado com credencial de dados protegidos** | NOT RUN (LAB-T07 provou lacuna anterior) |
| R06-N05 | Runtime web comprometido chama endpoint de efeito com tenant e ator falsos | Guardião verifica origem/prova e nega, sem escrita no banco; testar replay | NOT RUN |
| R06-N06 | Runtime web possui bearer de usuário válido e envia ação abusiva | Identificar limitação; para ações sensíveis exigir step-up/intenção independente conforme política ainda a deliberar | NOT RUN |
| R06-N07 | Worker/job com acesso técnico tenta leitura cross-tenant | Sem credencial global genérica; service intent restrito e auditado | NOT RUN |
| R06-N08 | Tentativa de herdar owner, SET ROLE, BYPASSRLS ou função definer | Negar sob role efetiva; inventariar objetos/EXECUTE/CREATE | NOT RUN |
| R06-N09 | SELECT/INSERT/UPDATE/DELETE com políticas permissivas e restritivas | Verificar USING/WITH CHECK, OR permissivo, transferência tenant e operação não prevista | NOT RUN |
| R06-N10 | Cancelamento/retry/savepoint e reuse de conexão | Sem vazamento de contexto ou privilégio, sem write duplicado, conexão inválida descartada | NOT RUN |
| R06-N11 | Revogar sessão durante efeito e outro tenant concorrente | Ordem temporal inequívoca, sem allow stale após corte, sem deadlock não tratado | NOT RUN |
| R06-N12 | Alterar grant/role, último gestor e recurso no meio da operação | Todo writer sincronizado com hierarquia, sem write skew | NOT RUN |
| R06-N13 | Restore antigo de credenciais e session_epoch | Não reanimar sessão ou permissão; reconciliação atômica pré-serving | NOT RUN |
| R06-N14 | Erro FK/unique, timing e mensagens SQL com tenant B | Sem enumeração ou vazamento material de B ao cliente ou log exposto | NOT RUN |
| R06-N15 | Serviço autoridade cai/indisponível ou perde prova de caller | Negação, rollback, sem cache positivo ou bypass emergencial tácito | NOT RUN |
| R06-N16 | Migração/owner/DBA tenta acesso sem trilha, ou busca por dados reais | Regras de segregação, acesso limitado e auditoria; não testar com PII real | NOT RUN |

**Definição de evidência:** SHA, versão PostgreSQL/PHP/libpq, roles efetivas, mapa de privilégios, massa de dados só A/B fictícia, linha do tempo com commits/locks, consultas antes/depois, logs sem credenciais, prova de ausência de efeitos e relatório explícito de FAIL/BLOCKED.

## 2. Cruzamento com achados e riscos

| Achado | Provas correlatas | Não resolvido por |
| --- | --- | --- |
| R01-01 (origem humana) | N01, N02, N05, N06 | Nome PortalUserId, igualdade de strings, header assinado na borda |
| R01-02 (revogação e sessão) | N10, N11, N13, N15 | snapshot, epoch isolado de efeito, testes com uma única conexão |
| R01-03 (papéis/grants) | N07, N11, N12 | DTO de membership, consulta sem escritor concorrente |
| R01-09 (integridade entre empresas) | N03, N07, N09, N14 | FK composta sozinha, role de laboratório com owner |
| R01-10 (RLS/contexto hostil) | N03, N04, N05, N08, N09, N10, N16 | SET LOCAL, FORCE RLS, role no mesmo runtime hostil, PASS do LAB-T07 |

## 3. Revisões independentes e decisões separadas

- **AppSec/Identidade:** delimitar classes T0..T4 por operação e capacidade ofensiva, plano de SQLi, sessão, segredo, autenticação de serviço e intenção do usuário. Revisão externa/independente ainda não recebida.
- **DBA/PostgreSQL:** roles/GRANT/EXECUTE/owner, SECURITY DEFINER e search_path, FK por tenant, RLS com policies por DML, contexto/pool, ordem P→A→S→O→M→R e fencing. Revisão independente ainda não recebida.
- **Produto/Direção:** decidir frontalmente se T2/T3 precisam ser contidos e qual custo/latência/disponibilidade aceita, sem inferir risco residual aceito por aprovação documental.
- **Engenharia:** antes de novo laboratório, estimar isolamento de runner, custos e rollback; obter autorização do laboratório concreto; NUNCA testar SQL hostil em base com clientes.

**Gate:** todos os casos desta matriz permanecem NOT RUN. O LAB-T07 anterior reproduziu um ataque com dados fictícios e NÃO serve de PASS em R01-10. Acesso privado e login operacional permanecem bloqueados, sem merge, enquanto não houver arquitetura escolhida, provas e revisões.