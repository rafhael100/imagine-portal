# SEC-P02-R01-10-R07 — Diretriz aprovada: aprofundar Modelo B

**Data:** 08/11/2026. **Origem:** aprovação expressa da Direção à pergunta após PR #49 e Issue #48.
**Estado:** DIRETRIZ ARQUITETURAL APROVADA PARA PLANEJAMENTO. Não é aprovação de arquitetura física, risco residual, implementação, laboratório, merge ou produção.
**Precedência:** R02-D01-B (revogação hierárquica), R02-D02-A reforçada (RLS como defesa), R04-D01-A..D05-A, R05, R06; LAB-T07 de PR #47 (PostgreSQL 18.6/PHP 8.4.27, run 42496521968) reproduziu bypass por contexto forjado.

## 1. Decisões e limites de autorização

- **R07-D01 — APROVADA COMO REQUISITO:** o processo web/worker na borda, sob cenário T2 de SQL arbitrário nas suas credenciais, NÃO deve conseguir ler, exportar ou alterar diretamente dados privados de outro tenant. A falha reproduzida pelo LAB-T07 é impeditiva de produção. O requisito está aprovado; a proteção ainda NÃO foi implementada ou comprovada.
- **R07-D02 — APROVADA COMO DIREÇÃO DE PLANEJAMENTO:** aprofundar o **Modelo B**, no qual uma autoridade própria do Portal valida identidade/sessão/grants e executa operações fechadas sobre dados privados, sem expor credenciais SQL genéricas ao processo web. Ainda não foi definido serviço físico, infraestrutura, protocolo, linguagem ou custo.
- **R07-D03 — RISCO T3 ABERTO:** processo web totalmente comprometido pode possuir sessão de usuário, solicitar operações válidas ou enganar o usuário. Serviço independente, RLS, autenticação mútua ou assinatura originada na borda NÃO demonstram automaticamente a intenção humana. Contenção total de T3 NÃO foi aprovada como garantia; analisar controles de intenção de alto impacto e limites.
- **R07-D04 — PRECEDÊNCIA PRESERVADA:** RLS e grants mínimos continuam úteis DENTRO da autoridade. Modelo A sozinho continua insuficiente para T2 e seu risco residual não foi aceito. Modelo C (separação de DB/role por tenant) permanece alternativa complementar, não decisão.
- **R07-D05 — GATES ABERTOS:** P0 R01-01/02/03/09/10, G-ID, G-BB, D02-SEC, D03/RLS, AppSec/DBA independente e release permanecem abertos.

## 2. Fronteiras de confiança — arquitetura lógica candidata

| Zona | Conteúdo e responsabilidade candidata | Poder que NÃO deve ser concedido |
| --- | --- | --- |
| Z0 — Browser e integrações externas | Inputs, cookies e intenção de ação não verificados; CRM/Performance independentes | Origem de actor, tenant, MFA, membership ou grants |
| Z1 — Portal web / API / workers não autoritativos | Interface, validação de formato, controles preliminares e encaminhamento de operações tipadas | Credenciais SQL de dados privados, owner/migrator, chave emissora de autorização, SELECT/DML cross-tenant |
| Z2 — Autoridade Portal (modelo B) | Verificar sessões emitidas no Portal; resolver tenant, resource owner, membership, política, entitlement; aplicar efeitos e trilha no ponto protegido | Aceitar actor, tenant, MFA ou role do Z1 como fato; fornecer endpoint de SQL livre |
| Z3 — PostgreSQL protegido | Dados privados, constraints, FKs compostas, roles e RLS por operação sob Z2 | Acesso direto Z1, BYPASSRLS e owner para runtime Z1; GUC de sessão como prova humana |
| Z4 — Operação/recuperação | Gestão separada de identidade técnica, segredos, migração, auditoria e restore | Atalhos administrativos disponíveis na borda, job/CLI/n8n sem alçada |

Fluxo lógico: Z0 -> Z1 -> operações específicas Z2 -> Z3. Z4 administra caminhos separados. Separação lógica NÃO comprova isolamento físico. Não contratar ou provisionar infraestrutura por esta diretriz.

## 3. Contratos obrigatórios a refinar

1. **Origem Portal:** Z2 verifica ator humano, sessão vigente, MFA/step-up e época de revogação por fontes Portal próprias. Cookie CRM, ID textual coincidente, e-mail, CNPJ ou DTO não autenticam usuário.
2. **Canal Z1-Z2:** serviço cliente deve ter identidade técnica e escopo explícitos, transporte autenticado e mitigação de replay. Identidade do serviço NÃO é consentimento de usuário. Um token gerado e assinado por Z1 comprometida pode ser falso para fins de principal/tenant.
3. **Contrato de operações:** Z2 expõe apenas ações nomeadas e tipadas, sem executar SQL fornecido pela borda, sem proxy de SELECT genérico, com limites de paginação, escopo, idempotência e erros não enumeráveis.
4. **Dados e permissão:** Z2 deriva organização a partir da sessão e do recurso, revalida membership, status, grants, entitlements e owner. O cliente pode solicitar recurso, nunca determinar autoridade de acesso.
5. **Linearização:** decisão e efeito protegidos contra revogação concorrente (P -> A -> S -> O -> M -> R). Evitar consulta de autorização em Z2 seguida de gravação independente por Z1. Ordem física de locks/coordenação/fencing exige parecer DBA.
6. **Intenção humana sensível:** pagamento, exportação, mudança de grants e operações administrativas podem exigir prova de intenção vinculada a detalhes da transação, com fator/canal que Z1 hostil não consiga forjar. Política de UX, recuperação e risco de sessão capturada ainda depende de decisão.
7. **Privilégios:** inventariar LOGIN, SET ROLE, BYPASSRLS, schema CREATE, membership transitiva, GRANT, SECURITY DEFINER, EXECUTE, search_path, views, triggers, jobs, migração e backup. Credencial de Z2 ou administradora não pode aparecer no ambiente Z1.
8. **Falha fechada:** Z2 ou banco indisponível, timeout, relógio incerto, cache stale ou auditoria obrigatória falha => negar/rollback sem bypass. Sem reuso de allow positivo entre requisições.
9. **Operação e incidente:** segregar deploy, rede, segredos e revisão das alterações; definir restauração com epochs não ressuscitadas, logs de prova sem PII excessiva e mínima exposição.
10. **Agentes e n8n:** jobs recebem identidade e escopo técnicos próprios; não herdar sessão humana nem acesso SQL administrativo, mesmo em servidores fisicamente separados.

## 4. Catálogo inicial de intenções (SEM endpoint implementado)

| Intenção | Dados que Z1 pode encaminhar | Autoridade/efeito que Z2 deve exercer | Ataque a provar |
| --- | --- | --- | --- |
| Descobrir minhas empresas | Sessão opaca e pedido de listagem | Derivar usuário e vínculos oficiais; listar somente os elegíveis | Z1 tenta consultar ator alheio |
| Consultar serviço | Referência opaca e paginação | Derivar owner e capabilities; limitar recursos por tenant | A consulta recurso de B |
| Abrir solicitação | Payload validado e idempotência | Conferir estado, tenant, permissão e efeito na mesma unidade protegida | Role revogada durante escrita |
| Gerenciar equipe/grants | Alvo e alteração solicitada | Verificar concedente e alçada, último gestor, revogação e auditoria | Role admin/tenant forjados |
| Exportar dados | Escopo e finalidade pretendidos | Checar limites e prova de consentimento para ação sensível | Z1 com sessão legítima solicita exportação abusiva |
| Recuperar suporte/restore | Pedido rastreável sem privilégio técnico | Alçada independente, dupla revisão quando exigida, epochs e trilha | Backup antigo reativa sessão |

A tabela não autoriza criação de rotas ou API.

## 5. Matriz de GO/NO-GO para futura PoC R07-F1

- **N01 — SQL T2 na borda:** Z1 tenta ler tenant B usando qualquer SQL, GUC, SET ROLE, view, função ou conexão disponível. Critério: não possuir canal nem credencial SQL de dados protegidos; zero acesso DB cross-tenant. **NOT RUN**.
- **N02 — Forjar API:** Z1 envia identidade, claims MFA, roles e tenant B inventados. Critério: Z2 desconsidera autoridade de Z1, prova sessão e membership, nenhum efeito B. **NOT RUN**.
- **N03 — Sessão legítima abusada em T3:** Z1 usa sessão válida para solicitar operação nociva. Critério: documentar vazamento residual e exigir contrato de intenção independente em ações críticas; NÃO afirmar proteção universal. **NOT RUN**.
- **N04 — Revogação concorrente:** Z2 recebe efeito enquanto conta/grant revoga. Critério: efeito ordenado ou negado de maneira demonstrável até commit, sem TOCTOU. **NOT RUN**.
- **N05 — Privilégios e RLS internos:** avaliar as roles verdadeiras do Z2, views, SECURITY DEFINER, RLS DML, default grants e contexto, inclusive tentativa de falsificação. **NOT RUN**.
- **N06 — Operação em falha:** timeout de Z2, retry, conexão reciclada, service key revogada, restore e auditoria indisponível. Critério: zero bypass, nenhum efeito não autorizado, trilha suficiente. **NOT RUN**.
- **N07 — Multiempresa A/B sob runtime comprometido:** Z1 com acesso ao próprio tenant tenta leitura, escrita, exportação e alteração de grants de B por APIs e jobs. Critério: B íntegro e inacessível, sem enumeração. **NOT RUN**.
- **N08 — Inventário de escritores:** identificar todos os caminhos de DML (web, jobs, CLI, n8n, suporte, migração, restore). Critério: nenhum escritor Z1 contorna Z2 e barreiras. **NOT RUN**.

Os 16 casos anteriores R06-N01..N16 permanecem aplicáveis e NÃO EXECUTADOS. LAB-T07 anterior é evidência de VULNERABILIDADE DO MODELO A sob T2, não PASS de proteção.

## 6. Pendências de decisão e execução

**R07-P01:** definir fronteira física do Z2, tecnologias, operação, serviços/servidores, onde autenticador e DB realmente residirão; não presumir layout da VPS de desenvolvimento.

**R07-P02:** definir protocolo independente de identidade Portal, sessão, MFA, retenção de segredo, recovery, CSRF e prova de intenção por categoria sensível.

**R07-P03:** mapa formal de writer/locks/transactions e coerência entre revogação, privilégios e efeito; revisar hierarquia e escalabilidade.

**R07-P04:** ameaça T3: determinar até onde a aplicação web comprometida deve ser contida, quais ações exigem intenção independente e que risco residual precisa decisão gerencial expressa.

**R07-P05:** estudo de desempenho, latência, disponibilidade, custos, isolamento e rollback, com pareceres AppSec e DBA independentes.

**R07-P06:** auditoria de repositório PUBLIC; nenhum segredo, fixture pessoal real, dados de cliente ou desenho de credencial operacional em branches públicas.

**Próximo recorte proposto e NÃO AUTORIZADO:** PoC isolada Z1 hostil + Z2 autoritativa em container descartável com dados fictícios e testes negativos; projeto com estimativa de custos, identidades técnicas mínimas e rollback, PR draft, sem deploy/VPS. Só iniciar após aprovação específica.

## 7. Governança

Esta autorização aprova o **direcionamento de planejamento B e requisito T2**. **NÃO** autoriza código funcional, endpoints, SQL/migrations executáveis, nova PoC, CI de laboratório, merge dos PRs #19/#24/#33/#46/#47/#49, main, dev, VPS, Cloudflare, cobrança, produção ou liberação a clientes. Não aceitar risco residual por silêncio. Os cinco P0 e todos os gates permanecem abertos.

**Responsável por aprovar futuros merges e desenvolvimento:** Direção Imagine, mediante revisão explícita e critérios de segurança.
