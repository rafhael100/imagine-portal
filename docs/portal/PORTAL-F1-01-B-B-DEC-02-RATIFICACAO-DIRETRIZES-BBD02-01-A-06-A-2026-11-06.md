# PORTAL-F1-01-B-B-DEC-02 — Ratificação preliminar das alternativas A de BBD02-01 a BBD02-06

**Data:** 06/11/2026 (America/Sao_Paulo)  
**Decisão do responsável:** “aprovado”, como resposta à pergunta expressa sobre as alternativas **A de BBD02-01 a BBD02-06**, **exclusivamente como diretrizes documentais preliminares**, sem autorizar programação, SQL, migrations ou deploy.  
**Estado:** **RATIFICADAS SOMENTE AS DIRETRIZES DOCUMENTAIS PRELIMINARES.** Nenhuma homologação técnica ou autorização de implementação funcional foi concedida.

## 1. Objeto aprovado

Ratificam-se como orientação de planejamento as **alternativas A** descritas na [proposta F1-01-B-B-D02](PORTAL-F1-01-B-B-D02-CONTRATOS-PERMISSOES-REVOGACAO-RLS-PROPOSTA-2026-11-06.md), integrante do [PR #19](https://github.com/rafhael100/imagine-portal/pull/19):

| ID | Diretriz A aprovada documentalmente | Contrato/gate ainda aberto |
|---|---|---|
| **BBD02-01** | Projeção **unidirecional** dos papéis versionados para permissões efetivas, sem grants diretos concorrentes, preservando o avaliador puro B-A | Catálogo real de ações, origem da identidade e integridade/revisão dos vínculos |
| **BBD02-02** | Matriz **explícita de alçadas**, proibição de autoelevação e proteção do último responsável autorizado | Fluxos de concessão, recuperação e delegação com dupla autoria e revisão |
| **BBD02-03** | Revalidação e **barreira transacional comum** para operações sensíveis e revogação, sem reaproveitar permissões antigas | Seleção de locks, isolamento, idempotência, efeito externo, TOCTOU, concorrência |
| **BBD02-04** | Auditoria mínima **atômica** junto a alterações de concessão/revogação e negação se a gravação obrigatória falhar | Retenção, privacidade, autoria, performance e controle de consulta da auditoria |
| **BBD02-05** | Avaliador RLS **privado, mínimo, com revisão de segurança** e sem bypass genérico | Prevenir recursão, privilégios excessivos, oracle entre tenants, SQL injection e trust boundary |
| **BBD02-06** | Contexto de autorização **local à transação**, com pooler e afinidade de conexão compatíveis e sem herança de tenant | Comportamento real do driver/pooler, falhas, rollback, retries, cancelamentos e troca de empresa |

Aprovação de diretrizes **não valida** uma implementação particular, nem altera os objetos de domínio B-A já integrados na branch de desenvolvimento. As alternativas B/C continuam apenas comparativos descartados como orientação inicial; poderão ser reavaliadas mediante risco e decisão formal.

## 2. Impedimentos preservados

- **D02-SEC01..06, D03 físico/RLS, P0-DOC01..14, P0-DOC06/G-04, H01–H12 e C01–C10:** **ABERTOS / NÃO HOMOLOGADOS**.
- O PR #19 **permanece aberto em rascunho, sem merge**. PR #11 e PR #10 permanecem sob governança própria; a `main` e a VPS não recebem alterações desta ratificação.
- **Não autorizado:** código funcional B-B, tabelas, migrations, SQL executável, políticas RLS instaladas, acesso PostgreSQL real, Eloquent persistente, endpoints, login/MFA, sessões, SSO Portal–CRM, credenciais, dados reais, integração com Sites/CRM, deploy ou merge em qualquer branch.
- O contrato de origem do ator Portal, membership, grants, auditoria e recurso precisa ser fechado antes de qualquer operação persistente.
- Testes documentais propostos **não são** testes executados de PostgreSQL/RLS/concor­rência.

## 3. Próximo detalhamento documental permitido

Preparar **F1-01-B-B-D03 — integridade e autoridade física, ciclo de vínculos, catálogo de papéis, RLS e plano de ensaios negativos**, com alternativas para deliberação, sem inferir nova aprovação ou implementar SQL, migrations, banco, login ou deploy.

**Estado da F1-01-B-B funcional: NÃO AUTORIZADA.**
