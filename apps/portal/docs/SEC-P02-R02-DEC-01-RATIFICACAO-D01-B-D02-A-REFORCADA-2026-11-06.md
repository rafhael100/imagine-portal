# SEC-P02-R02-DEC-01 — Ratificação arquitetural D01-B e D02-A reforçada

**Data:** 06/11/2026. **Autorização:** resposta expressa da Direção de Produto Imagine "ok aprovado" à pergunta de aprovar D01-B (revogação hierárquica) e D02-A reforçada (aplicação confiável e RLS).
**Estado:** DIRETRIZES ARQUITETURAIS APROVADAS DOCUMENTALMENTE; NÃO HOMOLOGADAS, NÃO IMPLEMENTADAS, NÃO ENSAIADAS. Nenhum risco residual é tacitamente aceito.

## 1. Precedência e limites

Esta decisão é posterior aos candidatos SEC-P02-R02-ADR02 (barreira única G exclusiva global) e SEC-P02-R02-ADR03 (fronteiras de ameaça A/B). **Na escolha de arquitetura-alvo, D01-B substitui a preferência anterior por G global exclusiva e D02-A reforçada seleciona a opção de aplicativo Portal confiável + RLS**. Não apagar documentos históricos: inventários de escritores, interleavings, riscos e contratos anteriores continuam fontes úteis. Nenhum algoritmo, mecanismo SQL, configuração real ou versão foi aprovado por implicação.

## 2. D01-B — Revogação com sincronização hierárquica

**Decisão:** projetar barreiras hierárquicas com isolamento entre organizações independentes, preservando a capacidade de revogação de conta, sessão, concessão e política global.

Contrato técnico a detalhar e testar, ainda não homologado:
1. Inventariar todas as autoridades e writers de conta/credencial/fator/sessão, catálogo/política globais, empresa, membership, role/grant/atribuição, entitlement, recurso, job, CLI, manutenção e suporte. Um writer ausente invalida qualquer garantia de revogação.
2. Mapear domínios de bloqueio e revisões: global/política → ator/conta/sessão → organização/política local → membership/concedente/alvo → recurso/efeito. A ordem total e os modos compartilhado/exclusivo precisam desenho DBA; não introduzir aquisições parciais que gerem deadlock ou TOCTOU.
3. Operações independentes de empresas distintas **não devem disputar um único lock exclusivo global** a cada leitura/efeito. Uma atualização efetivamente global deve sincronizar-se com todas as decisões afetadas; locks globais compartilhados podem ser candidatos, mas exigem análise de contenção e starvation.
4. Suspensão de conta, troca de senha/MFA, logout e revogação por empresa devem invalidar decisões obsoletas inclusive entre empresas. Fonte separada de sessão não cria transação distribuída; exigir prova de coordenação ou delimitar efeito e negar se necessário.
5. Modificação de role/grant/empresa/catálogo deve competir com os efeitos dependentes da autorização, não apenas incrementar número de revisão sem barreira. Revalidar após adquirir os locks autoritativos e manter a disciplina até o commit.
6. Último gestor, grants delegados e concedente/alvo devem evitar write skew. Suspensão por segurança não é recusada simplesmente para manter um gestor; recuperação com alçada distinta permanece pendente.
7. Comandos para Performance, CRM ou provedores externos exigem fencing/idempotência no receptor ou documentação da janela residual; revogação local não desentrega bytes e comandos já aceitos.
8. Erro, indisponibilidade, deadlock, timeout, sessão inválida, auditoria obrigatória falha e restore sem reconciliação: rollback/negação. Não reutilizar cache positivo de autorizações nem repetir sem idempotência.

**Não escolhido:** A — barreira global exclusiva como arquitetura-alvo (ainda possível como referência simplificada de comparação documental); C — execução distribuída como padrão inicial. Fencing continua necessário para fronteiras externas quando houver efeitos distribuídos.

**Pendências expressas:** grafo fechado de escritores, ordem e compatibilidade dos locks, ponto de linearização, tempos/isolamento, performance, escala e custos; não declarar R01-02 e R01-03 encerrados.

## 3. D02-A reforçada — Backend confiável e PostgreSQL RLS

**Decisão:** o Portal Laravel valida autenticação humana exclusivamente Portal, identidade/sessão/MFA, membership e autorização no servidor. PostgreSQL reforça o isolamento multiempresa mediante row-level security, chaves e FKs compostas e privilégios mínimos. CRM e motor Performance não compartilham sessão humana Portal.

Contrato técnico a detalhar, ainda não homologado:
1. Resolver ator em fonte Portal autenticada; e-mail, CNPJ, IDs iguais entre CRM/Portal, headers, cookies e DTOs não conferem identidade nem poder.
2. Seleção de tenant é entrada hostil; membership/grants/ownership devem vir de fonte autoritativa e atual. Unicidade do par ator–organização, imutabilidade do tenant e FK composta por organização para relações privadas; respostas sem enumeração de terceiros.
3. RLS obrigatória por SELECT, INSERT, UPDATE e DELETE nas tabelas privadas, inclusive caminhos indiretos. Revisar políticas permissivas OR, funções, views, triggers, partições, ownership e mensagens de erro.
4. Runtime de requisições sem superuser, BYPASSRLS, DDL, privilégios do migrador/owner ou função genérica de elevação. Inventariar herança, SET ROLE, GRANTs diretos/indiretos, EXECUTE e search_path.
5. Contexto de ator/tenant estabelecido somente pelo backend na transação e conexão corretas, com fail-closed para contexto ausente, rollback e pool. SET LOCAL transporta contexto; **não é prova de identidade**.
6. Mitigar SQL injection com consultas parametrizadas, identificadores controlados, revisão de sinks e ausência de credenciais privilegiadas no runtime.
7. **Limite de proteção não resolvido:** contexto totalmente forjável por execução SQL arbitrária/runtime comprometido pode contornar a fronteira A. Esta aprovação **NÃO** é aceite expresso desse risco nem demonstra contenção desse atacante. Se a política exigir resistência a runtime hostil, reabrir o desenho antes de implementação e avaliar autoridade separada (B).

**Pendências expressas:** versão PostgreSQL/driver/pool, modelo físico/roles/policies, bootstrap, credenciais, possibilidade de contexto falsificado e avaliação do risco residual por Segurança e Produto. Não declarar R01-09/R01-10 encerrados.

## 4. Governança e próximos passos

- **A Direção de Produto Imagine detém a aprovação final gerencial.** Análise Codex e parecer AppSec/DBA são evidências consultivas, não substituem decisão expressa ou testes.
- Avaliação independente poderá identificar premissas insuficientes; aprovação arquitetural não substitui prova técnica.
- Matriz de evidências está em SEC-P02-R02-VAL-01 nesta mesma branch. Todos os casos continuam PLANEJADOS/NÃO EXECUTADOS.
- Os cinco P0, todos G-ID01–09, G-BB01–08, D02-SEC01–06, D03 físico/RLS, H/C/P, P0 e gates de release continuam **ABERTOS**.
- Não autorizado: PHP/JS funcional, SQL/DDL, migrations, login/MFA, PostgreSQL conectado, laboratório, teste operacional, despesas, dados reais, serviços externos, integração, merge PR #19/#24/#33, main, dev, VPS ou deploy.

**Resumo:** RATIFICADAS APENAS AS DIRETRIZES D01-B E D02-A REFORÇADA. Nenhuma prontidão de segurança ou para clientes foi homologada.
