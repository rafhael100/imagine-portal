# SEC-P02-R03-PG-01 — Modelo físico lógico candidato para PostgreSQL, tenants e RLS D02-A reforçada

**Data:** 06/11/2026. **Fonte normativa:** SEC-P02-R02-DEC-01 D02-A reforçada, PR #33 SHA b330578056301d410457d0f908298fd6a7c9f7a7; contratos PG no PR #19 (documentação independente).
**Estado:** ESPECIFICAÇÃO DE DICIONÁRIO/INVARIANTES, NÃO DDL OU MIGRATION APROVADA. Nenhuma tabela real criada, nenhuma versão PG/driver/pool homologada. A versão atual da documentação oficial PostgreSQL não determina a versão escolhida para o Imagine.

## 1. Domínios e fronteiras de confiança

- **Autoridade humana Portal:** conta/sessão/MFA locais. Nenhum login humano, DB de credenciais, cookie ou token de CRM é intercambiável. Identificadores gerados por servidor, e-mail/CNPJ não provam identidade ou vínculo.
- **Global mínimo:** registro de conta e autenticação, políticas/capabilities globais e índices técnicos necessários à avaliação, sob acesso restrito. Global NÃO equivale a acesso público nem ao runtime de todo tenant.
- **Privado por empresa:** organizações, memberships, roles, assignments, grants, contratos/entitlements, recursos, intenções e auditoria tenant. Todas as tabelas privadas levam organização obrigatória; jamais confiar no organization_id da requisição.
- **Auditoria e recuperação:** trilhas globais/tenant segregadas; restore nunca reabre revogação só porque um snapshot restaurado tem epoch menor.
- **Fronteira de risco D02-A:** backend e execução SQL do Portal são considerados confiáveis. RLS mitiga acesso sem filtro e erros de escopo sob role mínima; SQL arbitrário que possa forjar completamente contexto ator/tenant e runtime comprometido NÃO estão comprovadamente contidos. Risco ainda não aceito pela Direção.

## 2. Dicionário de relações candidatas — sem nomes/tipos físicos ratificados

| Entidade lógica | Identidade e unicidade proposta | FK / invariant obrigatória | Dono do dado |
| --- | --- | --- | --- |
| portal_account | portal_actor_id único, namespace Portal, status e revisões monotônicas | identidade Portal não deriva de CRM/e-mail/CNPJ | global restrito |
| portal_session | session_id e referência exclusiva à conta Portal, session_revision/epoch | session handle nunca é autoridade para tenant/role; revogação consistente com conta | global restrito |
| global_catalog / global_policy | capability_code fechado, catálogo e epochs | alterações de semântica obedecem barreira P | global restrito |
| organization | organization_id estável e status/revisions | policy local e entitlement expiram/bloqueiam acesso | global mínimo + metadados privados restritos |
| organization_membership | membership_id e UNIQUE lógica de (organization_id, portal_actor_id) | FK à conta Portal; (organization_id, membership_id) chave composta referenciável; states invited/active/suspended/revoked, vigência e incarnation | tenant |
| tenant_role | role_id e chave composta (organization_id, role_id), código local único | role pertence somente à organização proprietária | tenant |
| tenant_role_grant | (organization_id, role_id, capability_code) único | FK composta à role + código no catálogo, validade/revision | tenant |
| membership_role_assignment | (organization_id, membership_id, role_id) único | duas FKs compostas para membership e role da MESMA organização | tenant |
| tenant_resource | (organization_id, resource_id) única | FK ao próprio tenant, owner imutável; filhos referenciam (organization_id, parent_id) | tenant |
| tenant_entitlement | (organization_id, entitlement_id) e status/revision | serviço/contrato técnico validado, não cria grant automaticamente | tenant |
| tenant_intent / outbox | (organization_id, intent_id), chave idempotente escopada por ator/org/ação/payload | vínculo, recurso e entitlement referenciados por FK composta onde possível; incarnation/revision exigidas | tenant |
| tenant_audit_event | (organization_id, event_id), correlação | append protegido, gravação atomicamente vinculada ao efeito sensível, sem segredo/token | tenant |
| recovery_revocation_log | índice mínimo de revogação/epoch sob custódia confiável | política de restore/retention antes de uso; não pressupor que o mesmo backup preserve revogações posteriores | global restrito |

**Decisões físicas pendentes:** UUID variant, tipos/tamanho dos identificadores, timestamptz UTC, índices, sequências, ON DELETE/ON UPDATE, checks, constrains condicionais, lifecycle de reingresso, data retention, armazenamento de segredos, partitions, versão PG, driver pdo_pgsql e pool. Este dicionário é contrato revisável e não SQL pronto.

## 3. Integridade multiempresa verificável

- Para toda referência de origem tenant A a destino privado, o par organization_id + ID local deve existir no mesmo tenant. FK simples por ID global não basta. As colunas de scoping são NOT NULL no futuro; não escapar por FK parcial com NULL.
- A empresa de um recurso não pode mudar por UPDATE, upsert, transferência administrativa ou trigger. Transferência futura precisa workflow explícito com alçada de ambas as partes, não troca silenciosa de owner.
- Mesmo ID textual em CRM e Portal não cria conta conjunta nem FK cross-product. Mesmo actor em duas empresas tem memberships separadas e capabilities calculadas por organização.
- Concedente deve estar autorizado no instante da concessão/aceite; role global por nome owner/staff não concede bypass. Último gestor e reingresso precisam decisão adicional de alçada e contingência.
- Estados e intervalos temporais desconhecidos devem negar; expiração por passagem do tempo não exige um cron para ser efetiva. Ator/member/role/entitlement inativos negam.

## 4. Matriz de acesso por operações PostgreSQL

| Tipo de acesso | SELECT | INSERT | UPDATE | DELETE |
| --- | --- | --- | --- | --- |
| Dados tenant | Somente linhas da empresa autorizada, ação granular e contexto válido | WITH CHECK de owner/tenant e alçada | USING estado anterior + WITH CHECK estado novo, tenant imutável | Somente quando permitido pela capacidade, nunca para revogações históricas |
| Membership e grants | Somente informações necessárias à própria ação, em interface mínima | Apenas workflow autorizado do concedente/convite, sob locks | Estado e revisões com mesmo protocolo hierárquico; sem autoelevação | Desabilitar exclusão ampla; preservar trilha e revogação |
| Autenticação Portal | Interface mínima de autenticador e sessão, não dados públicos | Emissão/registro por autoridade restrita | Revogação, rotação, epoch com auditoria | Não eliminar trilhas/credenciais por SQL genérico do runtime |
| Auditoria | Capacidade audit.view segregada e privacidade | Append por operação autorizada | Alteração de evento anterior negada | Remoção por processo de retenção independente, não por aplicação |
| Catálogo/owner schema | Visibilidade técnica mínima, não perfil de cliente | Manutenção fora da request | Política global sob barreira P e controle de change | Migração/retention com gates separados |

RLS só permite acesso quando há **também privilégios SQL** apropriados; retirada de DML direto do runtime não comprova que o entry point privilegiado é seguro. O desenho físico deve revisar cada função, default PUBLIC/EXECUTE, security-definer, search_path, views, triggers, policy permissiva combinada por OR, herança, SET ROLE e GRANTs indiretos, sem criar bypass de descoberta entre tenants.

## 5. Roles / confiança e RLS

| Role lógico candidato | Permissões aceitáveis em desenho futuro | Exclusões importantes |
| --- | --- | --- |
| runtime_web | Somente operações nomeadas e consulta estritamente necessária ao Portal | Sem superuser, BYPASSRLS, owner de tabela, DDL, SET ROLE para privilegiado, CREATE em schema controlando resolução |
| runtime_job | Comprovação serviço-a-serviço + tenant/capacidade por job, com mesma disciplina | Não reutiliza cookie humano/tenant anterior, sem allow persistente |
| migration_owner | Aplicação de DDL/policy em janela formal com segredo separado | Nunca na conexão runtime/web nem no pool de requests |
| policy_evaluator | Consulta de elegibilidade estritamente contextual, sem enumeração de terceiro | Sem SQL dinâmico, ator arbitrário, acesso global de negócios |
| audit_writer / audit_reader | Append seguro e leitura autorizada por tenant/finalidade | Sem alteração/destruição arbitrária de trilha |
| recovery_maintenance | Reconciliar sob procedimento controlado quando serving fechado | Não usar como bypass silencioso em endpoints privados |

**FORCE RLS** deve ser avaliado onde compatível; proprietário de tabela costuma ter bypass salvo FORCE, mas superuser e role BYPASSRLS sempre ultrapassam RLS. Policy restritiva/permissiva é combinada conforme PostgreSQL, portanto auditoria de **todas** as policies aplicáveis e herança é indispensável. As verificações de FK/unicidade podem expor existência por erros e timing apesar de RLS; normalizar respostas externas sem alegar eliminação universal de canais laterais.

## 6. Trust boundary transacional e falhas

1. Validar sessão/ator/fator Portal em autoridade segura; selecionar organização como intenção não confiável.
2. Sob hierarquia do HIER-01, reler estado de conta, organização, membership/grants/política e ownership no primário.
3. Estabelecer contexto de tenant/ator exclusivamente a partir da autoridade, na conexão da transação antes de consulta privada; ausência ou alteração inesperada → rollback e falha fechada.
4. Respeitar RLS por comando e integridade composta em todas as operações; registrar efeito + idempotência + auditoria obrigatória na transação.
5. Após rollback, timeout, savepoint, troca de worker ou pool, não reutilizar contexto/allow. Carga por réplicas só se houver prova de consistência com a fronteira de revogação; caso contrário, primário.
6. Não presumir que SET LOCAL é assinado ou secreto. SQL injection que pode chamar SET LOCAL arbitrário pode representar ator/tenant falso se a policy confiar somente no valor. Mitigar SQLi na aplicação e explicitar risco residual; sem prova externa anti-forja, a opção D02-A não cobre runtime hostil.

## 7. Critérios de compatibilidade e evidências

Pré-requisitos para futuro gate G-BB01/02/05/06: versão exata PG suportada e EOL, PHP Laravel driver, pooler, RLS em tabelas e partições, comportamento de views/security-definer, roles, transações/isolation, ergonomia de migrations e rollback. Não converter referência a docs PostgreSQL current em seleção de versão.

Provas futuras: negativa de SELECT/INSERT/UPDATE/DELETE cruzados, FK de tenant B, tenant em payload, grant forjado, contexto ausente, pool A→B, role com BYPASSRLS ou DDL, função view/trigger elevada, policy permissiva OR, SQLi/forja de contexto registrada como limite, restore anterior a revogação. Todas NÃO EXECUTADAS.

**Fonte externa para revisão:** https://www.postgresql.org/docs/current/ddl-rowsecurity.html e https://www.postgresql.org/docs/current/transaction-iso.html. Documentação não é prova de ambiente Imagine.

**Status:** sem DDL, SQL, migration, roles reais, sessão real, banco conectado, merges, VPS ou deploy. G-ID, G-BB, D02-SEC, P0 e R01-09/R01-10 continuam abertos.
