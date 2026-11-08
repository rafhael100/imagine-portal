# PORTAL-CLIENT-ARCH-02 — PostgreSQL multiempresa: contrato físico preliminar

**Estado:** proposta técnica para revisão interna e posterior PoC isolada. **NENHUM SQL foi executado ou aprovado para base operacional.** Não reutilizar scripts experimentais dos PRs #51 e #67 automaticamente. No Portal Laravel os adaptadores confiáveis ainda não existem.

## 1. Garantias físicas obrigatórias

1. O banco Portal é **outro banco** do CRM, com roles e backups próprios, sem sessão compartilhada, dblink ou SELECT cross-service.
2. Todas as tabelas de dados da empresa carregam organization_id NOT NULL e identificadores que permitem UNIQUE(organization_id,id) e FKs compostas. Proibir FK por id isolado que permita associação a outro tenant.
3. Roles separadas por responsabilidade: **owner/migrator** sem ser conta da API, **app_runtime** sem BYPASSRLS/owner/superuser, **read-only diagnostic** sem escrita, workers com concessões específicas e auditoria. Nenhuma credencial SQL na borda/browser.
4. RLS FORCE ROW LEVEL SECURITY nas tabelas elegíveis com policies explícitas para consulta/mutação e default deny. Esse mecanismo é defesa adicional, não prova de identidade: se o processo que define a GUC for comprometido, a GUC não tem confiança intrínseca.
5. Contexto transacional de tenant e ator só pode ser originado do autenticador e vínculo **servidor**. Um SET na conexão ou GUC fornecida por cliente pode vazar em pooling; preferir transação com escopo, rollback/commit e limpeza obrigatória de contexto.
6. Middleware HTTP, workers, fila, eventos, CLI, scheduler, importação, restore, suporte e n8n precisam obedecer às mesmas invariantes. Falar em isolamento “total” antes de inventário de writers e provas seria incorreto.
7. Uma revogação concorrente requer barreira de autorização no momento de efeito/commit, não apenas checagem antes de renderizar. Decidir a estratégia de versão/fence e ordenação linearizável antes de escrever no banco real.
8. Caches não podem ser fonte de grant; toda concessão pode expirar ou ser revogada sem servir estado antigo. Outbox e idempotência devem carregar escopo e geração de autorização.
9. Backup restaurado offline não pode ressuscitar sessões, grants ou intents; ver contrato SEC-R03 e Issue #64. Falha da autoridade de revogação => fail closed.

## 2. Esquema relacional conceitual (sem DDL)

**Portal users** (id global) -> **portal memberships** (organization_id,user_id,status,revision) -> **portal grants** (organization_id,user_id,permission,revoked_at). A organização possui recursos com chave composta (organization_id,id); serviços contratados e cobranças são por contrato e produto, sem confluir bancos dos motores CRM/Performance/Sites/Universidade.

Para objetos transacionais: a referência de produto/contrato deve incluir tenant e fonte/versão para não aceitar contratos de uma empresa em pedidos de outra. Eventos e solicitações precisam de idempotency_key escopada por tenant e recurso e operação, com auditable outcome. Tokens de serviço nunca se tornam principal humano.

## 3. Provas negativas antes de qualquer migration operacional

| ID | Ação adversarial | Resultado obrigatório |
|---|---|---|
| DB-X01 | Login A tenta SELECT recurso B | 0 linhas e sem dados no log |
| DB-X02 | A tenta INSERT com FK apontando B | transação rejeitada por constraint |
| DB-X03 | A tenta UPDATE/DELETE de item B | 0 efeitos e nenhum side effect |
| DB-X04 | Role app tenta SET ROLE owner/superuser/BYPASSRLS | negado |
| DB-X05 | Job sem concessão tenta SQL direto | negado por role e policy |
| DB-X06 | Transação A volta ao pool e serve B | contexto não contamina próxima transação |
| DB-X07 | Troca org A/B no browser sem novo membership | negado |
| DB-X08 | Revogação de grant simultânea ao writer A | writer antigo não obtém commit pós-corte |
| DB-X09 | Owner/migrator indevidamente exposto via Z2/Z1 | credenciais inacessíveis, quebra de isolamento bloqueia release |
| DB-X10 | Backup antes de revogação restaurado em clone | serving negado, até reconciliar com fonte independente |
| DB-X11 | Cache, fila, outbox tentam replay antigo | nenhum efeito indevido |
| DB-X12 | Integrador/CRM usa mesmo ID/e-mail do Portal | não concede acesso ao tenant por colisão textual |

**Todos os DB-X01 a DB-X12: NOT RUN neste lote.** Não inferir sua aprovação pelo laboratório R07-F1; ele valida outra topologia e dados exclusivamente sintéticos.

## 4. Plano de desenvolvimento controlado a aprovar separadamente

Fatia A: elaborar DDL de laboratório para organizations/memberships/grants/resources com PK/FK composta e roles mínimas (sem dados reais). Fatia B: integrar fonte oficial Laravel de sessão e permissões em store confiável, mantendo HTTP fechado até security gate. Fatia C: testar RLS com PostgreSQL descartável e hostil A/B, concorrência e conexão reutilizada. Fatia D: habilitar *apenas* consultas privadas autorizadas sob revisão D02/D03/P0; efeitos externos e billing em etapas independentes.

Não haverá migrations em VPS, merge, produção, abertura de rotas nem custos sem autorização específica. A política de privilégio de restore, HA, RPO/RTO e Z4 anti-rollback permanece em planejamento.
