# PORTAL-F1-01-B-B-DEC-01 — Ratificação preliminar das alternativas A de BB01 a BB08

**Data:** 06/11/2026 (America/Sao_Paulo)  
**Manifestação:** o responsável respondeu **“aprovado”** à pergunta expressa: “Você aprova as alternativas A de BB01 a BB08 exclusivamente para continuidade do planejamento documental da F1-01-B-B, sem SQL, migrations, merge ou deploy?”  
**Estado:** **APROVADO COMO DIRETRIZES DOCUMENTAIS PRELIMINARES** — não homologado, não autoriza implementação funcional, merge, conexão ao banco nem produção.

## 1. Escopo exato da decisão

Ratificam-se, somente para orientar o próximo detalhamento documental, as seguintes alternativas apresentadas em [F1-01-B-B-D01](PORTAL-F1-01-B-B-D01-MODELO-FISICO-CANDIDATO-ISOLAMENTO-2026-11-06.md), [Issue #17](https://github.com/rafhael100/imagine-portal/issues/17) e [PR #19](https://github.com/rafhael100/imagine-portal/pull/19):

| Decisão | Alternativa A ratificada documentalmente | Condição que continua aberta |
|---|---|---|
| **BB01** | Schema compartilhado, chaves estrangeiras compostas e RLS de escopo obrigatório | Definição de roles, policies, versão PG, bootstrap e homologação de RLS |
| **BB02** | Uma membership canônica por par (organização, usuário) com histórico de eventos | Reingresso, unicidade, concorrência, auditoria e retenção |
| **BB03** | Papéis locais versionados e catálogo fechado de permissões | Matriz de poderes, compatibilização com grants explícitos B-A, ausência de autoelevação |
| **BB04** | Barreira transacional comum, revisão e revalidação para revogação e efeitos sensíveis | Garantia de ordenação efetiva, desempenho, lock/retry e teste de corridas |
| **BB05** | Avaliador privado mínimo de autorização usado para viabilizar RLS sem recursão | Fronteira de confiança e revisão independente de privilégio, ausência de oracle/bypass |
| **BB06** | Transações explícitas com contexto local e pooling compatível | Prova de ausência de resíduo, `SET LOCAL` apenas em desenho, timeouts e rollback |
| **BB07** | Bootstrap mínimo de identidade exclusivamente Portal, staff sem privilégio implícito | Prova de origem do ator, regras de delegação, separação Portal–CRM, MFA futuro |
| **BB08** | Retenção de auditoria por finalidade e restauração controlada | Prazo final, LGPD, anonimização, autorização de acesso e reconciliação de revogações |

As alternativas B/C da proposta continuam comparativos **não adotados nesta direção preliminar**; sua rejeição como plano atual não constitui proibição permanente de reavaliação após risco, custo ou desempenho.

## 2. Precedência e limites obrigatórios

1. **Este documento não fecha os gates** D02-SEC01..06, D03 físico/RLS, P0-DOC01..14, P0-DOC06/G-04, contratos de identidade, riscos de delegação, regras LGPD ou homologação H01–H12.
2. **Nenhuma autorização para** SQL executável, migrations, tabelas, Eloquent persistente, modelos/usuários reais, PostgreSQL em operação, RLS implementado, login/MFA, API, SSO, CRM, VPS, deploy, dados reais, lançamento financeiro ou novas integrações.
3. O PR #19 e PR #11 permanecem **em rascunho, sem merge** por esta manifestação; `main` permanece intocada. A presente decisão não autoriza merge em qualquer branch.
4. B-A já possui lógica de permissões explícitas no objeto em memória; BB03-A exige documentação da **projeção fiel dos grants** sem permitir duplicidade de fontes ou criar privilégios automáticos para papéis. O código B-A não será modificado por esta ratificação.
5. O contexto de banco, valores de configuração local de transação e RLS **não comprovam identidade por si**; o domínio de confiança do runtime, auditoria, pooler e proteção a SQL injection ainda exigem projeto.
6. A aprovação cobre a **orientação documental**; **cada decisão física, contrato de segurança, execução de teste e implantação exigirá gate e autorização posterior específicos**.

## 3. Continuidade documental permitida

Preparar **F1-01-B-B-D02 — contratos de fonte de verdade das permissões, revogação e avaliador privado RLS**, com alternativas e critérios de decisão para avaliação do responsável. Pode-se elaborar documento(s) e PR(s) em rascunho somente em branch documental isolada. Não alterar `main`, branch de desenvolvimento, PRs existentes por merge ou executar código.

**Estado da etapa B-B funcional:** BLOQUEADA / NÃO AUTORIZADA.
