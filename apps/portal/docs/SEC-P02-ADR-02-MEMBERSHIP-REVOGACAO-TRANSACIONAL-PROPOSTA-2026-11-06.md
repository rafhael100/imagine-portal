# SEC-P02-ADR-02 — Membership canônica e barreira transacional de revogação (proposta técnica)

**Data:** 06/11/2026 · **Status:** ADR CANDIDATO EM REVISÃO; NÃO HOMOLOGADO.
**Escopo:** G-ID05 e G-BB03/04/06, em correlação com PR #19 F1-01-B-B D03/D05 e SEC-P02-RV01. Nenhuma tabela, lock, role, banco, migration ou teste de concorrência foi implementado.

## Fonte canônica e invariantes

**Modelo candidato:** uma fonte oficial Portal para par (portal_actor_id, organization_id), com unicidade, status, vigência, revisão monotônica de membership e grants, ownership de recurso privado por empresa e decisões granulares por ação. Dados fornecidos pelo request não são autoritativos. Não unir usuários ou empresas por igualdade de e-mail/CNPJ com CRM. Uma organização selecionada por URL representa só intenção.

Representação LÓGICA (não DDL):
- portal_actor: ID local, account_status, identity_revision, credential/mfa revisions;
- organization: ID local, organization_status, organization_revision;
- membership: membership_id, portal_actor_id, organization_id, status, starts_at, ends_at, membership_revision, changed_at, changed_by, event_correlation;
- role_definition: organization_id, role_id, catalog_version, role_revision, active;
- role_assignment: organization_id, membership_id, role_id, assignment_revision, status;
- grant: organization_id, role_id, capability_code, version, active;
- private_resource: organization_id e resource_id ligados por integridade/FK composta;
- authorization_audit: evento/correlação com versões e escopo mínimo.

**Os tipos finais, UUID, FKs, roles SQL, constraints, política RLS e versão PostgreSQL dependem de PR #19 e revisão DBA.** Não incluir senha/segredo no audit log.

## Estados e alçadas

- invited não concede acesso; active somente quando conta Portal, empresa, vínculo e grants são vigentes; suspended/revoked encerram autorização; expired é um resultado de vigência, não reingresso.
- Reingresso nunca recupera grants anteriores automaticamente. Requer novo aceite e concessões autorizadas sob revisão; histórico preservado sob política de retenção.
- Organização suspensa bloqueia recursos privados, com exceções legais de suporte/admin ainda NÃO definidas.
- Papéis são por organização, e nome owner/staff não gera superuser universal. Concedente só delega capacidade para a qual tenha alçada explícita; gerir membros, conceder papéis, editar catálogo, faturamento, auditoria e executar campanhas são capacidades separadas.
- Proteção do último gestor exige operação de recuperação revisada e auditável; não criar bypass global de suporte ou transformar pagador automaticamente em gestor.

## Contrato de revogação com concorrência (candidato de transação única)

**Problema concreto:** checar membership e depois escrever sem barreira permite TOCTOU; cache/replica pode manter grants antigos após revogação. Para o recorte inicial de ações sensíveis totalmente internas ao banco autoritativo, propõe-se **serialização por lock da linha de membership**, em transação no banco primário. Esta é UMA alternativa candidata, ainda depende do desenho físico e da revisão do DBA.

Para cada ação sensível:
1. Abrir transação no banco primário com role runtime de menor privilégio, sob timeout e isolamento definidos e homologados.
2. Resolver sessão Portal e ator atual por fonte confiável; obter organização como intenção; localizar membership **do próprio ator e empresa**. Falha → negar.
3. Obter lock transacional da linha canônica de membership conforme ordem global consistente; sob o lock, reler status, vigência, grants_revision, estado da organização, titularidade do recurso e capacidade exigida. Não aceitar snapshot em cache/replica como autorização.
4. Validar revisão de concessões também em escopo tenant, evitando que mudanças de role/grant contornem o lock de membership: **toda mutação de grants/roles deve atualizar uma revisão central vinculada e respeitar o mesmo protocolo de locks**. Esse detalhe precisa de desenho concreto e prova, não está implementado.
5. Registrar efeitos de escrita privados e evento obrigatório de auditoria na transação. Se precisar de ação externa, persistir apenas uma intenção/outbox condicionada; **não chamar serviço externo dentro da transação**.
6. Commit/rollback atômico; se houver conflito de versão, deadlock, timeout, ausência de fonte, auditoria obrigatória falha ou tenant incerto, rollback/fail closed. Retry idempotente, com revalidação completa, não reuso do allow anterior.

Para revogação/rebaixamento:
1. Obter o MESMO lock canônico de membership e as revisões pertinentes, em ordem acordada.
2. Validar alçada do concedente/revogador e contexto corrente; para operações entre duas memberships, definir ordem estável de locks e revisar deadlock.
3. Gravar status/revisão/grants, trilha obrigatória e cancelamento/intenção de cancelamento de ações ainda não executadas; commit define o **ponto de linearização** do novo estado.
4. Sob essa disciplina, uma escrita concorrente que adquiriu o lock primeiro poderá ser concluída ANTES do commit de revogação; uma escrita que tentar adquirir lock DEPOIS e revalidar sob lock deverá ser negada. Não prometer apagar bytes já exportados ou retroagir efeitos concluídos.

**Limite fundamental:** SELECT FOR UPDATE e locks de linha, isoladamente, não garantem que a revogação de papéis globais, status de organização, store de sessão, réplicas ou transações de outro sistema esteja sincronizada. É necessário escolher autoridade das revisões e mecanismo de serialização de cada tipo de decisão. A frase “revogação imediata” só poderá ser usada para escopo e fronteira de efeito comprovados em ensaio.

## Outbox, filas, Performance e integrações

A transação interna pode autorizar a inserção de intenção de ação, mas **não** garante atomicidade de uma chamada a Google/Meta ou ao motor Performance. O dispatcher deve consultar estado atual do vínculo e do serviço na autoridade, comparar revisão e cancelar intenção obsoleta. A janela entre último check e entrega externa exige contrato específico de fencing/idempotência com o motor; sem esse contrato, não afirmar que nenhuma chamada externa ocorrerá depois da revogação. Reconciliar o resultado posterior sem duplicar campanhas ou cobrança. Sem integração operacional nesta etapa.

## Contexto PostgreSQL e barreiras (dependência PR #19)

- Runtime sem superuser, BYPASSRLS, owner/DDL e funções de elevação genérica.
- Chaves compostas de tenant para recursos privados; RLS por SELECT, INSERT, UPDATE (USING/WITH CHECK) e DELETE, incluindo gravações indiretas e eventos.
- Contexto de ator/tenant transacional por conexão correta, com rollback e limpeza; SET LOCAL não atesta quem se autenticou e pode ser alterado por aplicação comprometida. Evitar consultas privadas antes da definição do contexto.
- Resolvedor de bootstrap que lista apenas organizações do ator não pode abrir bypass universal em tabelas privadas. Modelo de privilégios e funções precisa revisão DBA independente.
- Garantia contra SQL injection, pooler, retry, réplicas atrasadas e restore requer testes reais em laboratório isolado autorizado futuramente.

## Casos adversariais obrigatórios, ainda NÃO executados

| ID | Interleaving/ataque | Resultado exigido |
| --- | --- | --- |
| REV-01 | Efeito obtém lock antes de revogação | Ou conclui antes do commit de revogação, ou aborta; resultado audível e previsível |
| REV-02 | Revogação commita antes da tentativa de efeito | Efeito é negado ao reler status/revisão sob lock, zero mutação nova |
| REV-03 | Grant de role muda sem alterar membership | Mecanismo de revisão invalida toda autorização dependente; não há allow antigo |
| REV-04 | Duas empresas e mesmo ator, URL adulterada | Nenhuma leitura/escrita cruzada mesmo com sessão válida em outra org |
| REV-05 | Job antiga ou evento outbox enfileirado | Revalida grant e serviço, cancela ação obsoleta e não duplica efeito |
| REV-06 | Duas revogações e concessões simultâneas | Sem deadlock permanente, autoelevação, grant duplicado ou perda de auditoria |
| REV-07 | Pool/rollback troca tenant com conexão reaproveitada | Nenhum contexto residual nem bypass RLS |
| REV-08 | Último gestor é suspenso/revogado | Sistema não concede staff global; contingência bloqueada até alçada aprovada |
| REV-09 | Restore anterior a revogação | Reconciliar revisões/tombstones antes de servir recursos privados |
| REV-10 | Store de sessão ou banco indisponível | Negar sem cache de allow, sem efeitos privados |

Esses cenários devem ser ligados a H01–H12, C01–C10 e P01–P12 do PR #19; evidência deve mostrar ausência de efeito e vazamento, não só código HTTP 403.

## Critérios objetivos para revisão independente

**Dados/DBA:** opinar sobre viabilidade do lock canônico e interleavings, controle de versionamento de grants, ordenação de locks, isolamento, índices/unicidade, RLS, roles e pool. **Segurança:** verificar revogação de sessões versus membership, caches e grants, ausência de bypass e threat model da identidade. **Backend:** definir limites de operações sensíveis e outbox/fencing. **Produto:** catálogo de capacidades e alçadas, último gestor e reingresso.

Pontos explicitamente **não resolvidos**: versão real PostgreSQL, isolamento e transações, ORM/driver/pool, role runtime, triggers/eventos de grants, algoritmo de último gestor, retenção de revogação, autenticador real, integração serviço-a-serviço e estrutura de evidências. O ADR é candidato, não assinatura ou homologação.

**Limite de autorização:** documentação somente. Nenhuma migration, SQL executável, conexão a banco, teste de laboratório, login/MFA reais, UI de cliente, merge de PR #19/#24, main, VPS ou deploy.
