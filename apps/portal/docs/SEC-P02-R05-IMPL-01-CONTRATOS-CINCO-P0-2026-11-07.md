# SEC-P02-R05-IMPL-01 — Contratos de implementação controlada dos cinco P0

**Data:** 07/11/2026. **Estado:** CONTRATOS DOCUMENTAIS CONSOLIDADOS, SEM CÓDIGO, SEM HOMOLOGAÇÃO.
**Autorização:** Direção de Produto Imagine disse "aprovado" à preparação do pacote final de contratos e testes dos cinco P0, para futura avaliação de um recorte de desenvolvimento. A aprovação NÃO autoriza implementar login, migrations, SQL, banco real, laboratório, merge ou produção.

**Precedência:** D01-B (revogação hierárquica) e D02-A reforçada (backend confiável + PostgreSQL RLS) da R02; R04-D01-A..R04-D05-A ratificadas como diretrizes preliminares pela R04-DEC-02. Este documento especifica contratos verificáveis, mas NÃO fixa algoritmos/SQL/segurança real sem revisão e autorização. Fonte: PR #33, com PR #24 (identidade) e PR #19 (PostgreSQL) como dependências documentais sem merge.

## 1. Fronteiras e critérios transversais

- **Ator humano**: exclusivamente identidade emitida e verificada pelo Portal. ID igual, e-mail, CNPJ, cabeçalho, cookie, token ou sessão do CRM/Performance nunca se tornam identidade Portal.
- **Operações protegidas**: leitura privada, mutação interna, gestão de identidade e autorização, alteração de alçadas e materialização de exportação. Efeitos externos e dados já entregues requerem contrato separado de fencing/reconciliação e NÃO herdam garantia de commit interno.
- **Autoridade e atomicidade**: cliente nunca fornece actor/grants/membership como verdade. A fonte confiável persiste no primário Portal; autorização é revalidada dentro da unidade transacional e sob barreiras dependentes, até commit da ação ou corte de leitura. Falha de fonte/lock/tempo/auditoria obrigatória, contexto inválido, stale epoch e perda de conexão provocam negação e rollback.
- **Hierarquia**: P política global → A conta e credenciais → S sessão → O organização → M membership/concedente/alvo → R recurso, sempre ordem determinística dentro da classe. Precisão de lock shared/exclusive, compensações e isolation_level continuam a definir por DBA/Backend. Não regressar ao lock G exclusivo global por toda operação.
- **Duas empresas independentes**: não devem requerer o mesmo lock global EXCLUSIVO em cada ação; suspensão de mesmo ator em várias empresas e mudanças de catálogo global continuam conflitos legítimos.
- **Banco-alvo documental**: PostgreSQL 18, PHP 8.4, Laravel 13, PDO_PGSQL/libpq, primário e sem pool externo inicial no caminho de autoridade. Patch, versão instalada, migração, TLS, credenciais e driver efetivo ainda não verificados.
- **Tenant**: organização derivada de sessão Portal + vínculo/permite da fonte oficial, nunca de URL/DTO; relação privada exige organization_id não nulo e imutável e FK tenant composta; RLS reforça, mas não substitui autorização.
- **Segurança contra SQL forjado**: RLS/SET LOCAL não comprova identidade. A fronteira escolhida assume backend confiável; SQL arbitrário capaz de forjar inteiramente o contexto pode exceder essa defesa. **Esse risco não foi aceito pela Direção** e deve ser explicitado antes de habilitar funcionalidade privada.
- **Auditabilidade**: toda mudança sensível gera trilha mínima de intenção, quem/onde/qual alçada/revisões, timestamp, estado e motivo, sem incluir segredo, token, handle bruto, payload privado nem identificar terceiros no erro público. Fail closed para auditoria obrigatória não disponível.

## 2. R01-01 — Emissor e autenticador humano Portal

**Dependências:** G-ID01/G-ID02/G-ID03/G-BB03, ADR01 R02. **NÃO implementado.**

| Interface lógica futura | Entrada tratada como não confiável | Operação autoritativa exigida | Saída possível |
| --- | --- | --- | --- |
| Registrar conta | e-mail/canal de contato, metadados de cadastro | Criar portal_actor_id opaco local, estado pending_verification, desafio com propósito e expiração; sem membership automática | Referência de verificação sem sessão privada |
| Verificar canal | código/URL/challenge | Confirmar verificador restrito, vinculado a sujeito, propósito, revisão e single-use; limitar abuso | Estado de canal verificado; NÃO comprova mandato empresarial |
| Validar credencial + MFA | senha/fator/challenge | Verificar segredo local e fator vigente, proteção antirreplay, revisar estado/revisões; não importar senha/OTP do CRM | Desafio ou elegibilidade de emissão; sem autorizar dados privados em pré-MFA |
| Emitir sessão | resultado de verificação | Criar handle imprevisível exclusivamente Portal, registro revogável, session_epoch, credential/mfa_revision, timestamps e duração definidos por política ainda sujeita a AppSec | Cookie Portal host-only seguro; nunca token CRM |
| Autorizar requisição | cookie Portal; tenant/recurso/ação pedidos | Resolver handle na autoridade Portal, revalidar conta/sessão/fator/revisões/tempo, buscar membership e owner em fonte server-side | Decisão interna de uso único na unidade protegida; negar sem fonte |

**Invariantes impeditivos:** namespace não é prefixo apresentado pelo cliente; DTO nem propriedade booleana MFA é prova. Emissão, rotação, logout individual/global, reset de fator e credencial não reativam sessão anterior. Bootstrap/primeiro gestor não é consequência de verificação de e-mail ou pagamento. Política de cookie, CSRF, rate limits e recovery devem ser especificadas e avaliadas antes do login real.

**Provas de contrato:** ID-A01..06; R05-01..04 no plano QA; validade da sessão apenas sob backend, não mock de SessionDemo. O gate segue ABERTO até implementação/testes reais posteriores.

## 3. R01-02 — Revogação de conta e sessão concorrente com o efeito

**Dependências:** G-ID03/G-ID08/G-BB06, R03-HIER-01. **NÃO implementado.**

**Fonte candidata:** conta, fatores e session_epoch em autoridade que permita sincronizar revogação e efeito interno. Mesmo host com dois stores NÃO garante commit atômico. Uma autoridade de sessão externa requer protocolo demonstrável de fencing com o primário de efeitos; nenhum adaptador está aprovado por antecipação.

**Requisito de linearização:** se o commit de suspensão da conta, reset/MFA ou logout ocorreu antes de a ação obter direito ao efeito, esta ação não pode produzir nova mutação autorizada com estado antigo. Se a ação foi linearizada e commitada antes da revogação, não afirmar que ela foi desfeita. Revalidar após espera por locks e não confiar em snapshots anteriores.

**Fluxo conceptual de efeito (não pseudocódigo executável):** resolver referências públicas mínimas → adquirir barreiras hierárquicas na mesma unidade transacional → reler sessão/conta/epochs e grant → conferir tempo/fator/recurso → persistir efeito, idempotência e auditoria → commit ou rollback integral. Nenhuma resposta HTTP é prova de ausência de efeito.

**Fluxo de revogação:** obter barreiras incompatíveis com ações afetadas → mutar estado/revisões e invalidação de desafios/sessões → auditar → commit define corte. Conta de ator multiempresa afeta todas as empresas sem bloquear artificialmente usuários sem relação.

**Provas:** suspensão e reset em concorrência com ação, emissão simultânea, sessão em outra empresa, replay de cookie, deadlock/timeout, rollback, idempotência e restore sem ressuscitar sessão (R05-05..08).

## 4. R01-03 — Revogação de grants, roles e catálogo

**Dependências:** G-ID05/G-BB04/G-BB06 e catálogo de capacidades ainda a homologar. **NÃO implementado.**

**Inventário exigido de writers:** dono de catálogo/política global, organização, membership, convite, papel, atribuição, grant, entitlement, recurso, contrato, job, suporte, CLI, manutenção e restore. Um writer capaz de alterar o grant fora das barreiras quebra a garantia. Revisões (catalog_epoch, tenant_policy_revision, membership_incarnation, role/assignment revision) não substituem atomicidade.

**Unidade de autorização:** identificar ator autenticado, organização desejada, ação granular, permissões atuais do concedente e do alvo, vigências e entitlement; sob barreira P/A/S/O/M/R necessária. Alterar role local deve disputar O com todas as ações dependentes daquela role, não somente travar linha membership do ator.

**Exceções exigem modelagem separada:** último gestor, concessão delegada e mudança de catálogo global, exportações em lotes, reingresso e suspensão de organização. Sem autoelevação e sem conceder "owner" por string ou por mero CNPJ.

**Provas:** duas ações e uma revogação em ordens inversas; mudança global de catálogo durante ações de vários tenants; duas remoções de gestores; concedente revogado durante concessão; expiração sem job (R05-09..12).

## 5. R01-09 — Integridade e ownership multiempresa

**Dependências:** G-ID05/G-ID06/G-BB02/G-BB03/G-BB05, R03-PG-01. **NÃO há DDL.**

**Relações lógicas obrigatórias:** portal_account, portal_session, organization, organization_membership, tenant_role, role_grant, membership_role_assignment, tenant_resource, tenant_entitlement, tenant_intent e auditorias global/tenant. Uma relação privada tem organization_id derivado de autoridade, não editável; parentes e FKs levam o mesmo tenant. Em membership garantir par (organization_id, portal_actor_id) único durante reingresso; membership_revoked não volta a grant vigente sem novo processo.

**Predicados por ação:** status do ator, da organização, do membership, role/grant/capability, entidade e entitlement; autorizar somente se o conjunto oficial e vigente permitir. Descoberta "minhas organizações" tem interface mínima autenticada sem parâmetro actor arbitrário e sem ampliação de acesso a dados privados.

**Provas:** FKs cruzadas, UPDATE que tenta trocar empresa, atributos/IDs fabricados, filtros ausentes e resposta de erro que revela a existência de tenant B (R05-13..16). Login e descoberta nunca dependem de tenant default.

## 6. R01-10 — PostgreSQL RLS, roles e ameaça de contexto falsificável

**Dependências:** G-ID06/G-BB05/G-BB06, R03-PG-01 e R04-D03/D04. **NÃO existem roles/policies reais.**

**Roles lógicas segregadas:** runtime_web, runtime_job, owner sem login de request, migrator separado, funções operacionais restritas, leitura/auditoria e manutenção/recovery autorizada. Runtime não é superuser, owner, BYPASSRLS, migrator ou membro capaz de SET ROLE privilegiado; acesso a schemas/EXECUTE e função SECURITY DEFINER são inventariados. FORCE RLS, quando aplicável, não impede superuser ou role BYPASSRLS.

**Política por comando:** SELECT USING escopo + capacidade; INSERT WITH CHECK em tenant e proprietário novos; UPDATE USING antigo + WITH CHECK novo para impedir transferência; DELETE própria alçada ou negado em autoridades/auditoria. Policies permissivas se combinam por OR, exigindo inventário de composição; row-level security não impede oracles em FK/uniqueness/erros, nem fornece isolamento se o próprio contexto é falsificado.

**Contexto:** ator e empresa resolvidos server-side; transação no primário, mesma conexão do início ao fim; contexto transacional ausente/inválido implica negação. Testar savepoint, cancelamento, retry e retorno ao pool, sem supor SET LOCAL como token seguro.

**Limite de segurança:** SQL injection com poder de alterar o contexto de ator/tenant pode contornar policies que confiam nesses campos. O tratamento A reforçado exige reduzir superfície de SQLi e privilégios, mas **NÃO autoriza afirmar que resiste a runtime inteiramente comprometido**. Se essa ameaça for requisito da Direção, reabrir fronteira e considerar autoridade separada.

**Provas:** role runtime real, policies por DML, owner/FK, bypass por views/funções/role inheritance, contexto falsificado versus omissão de filtro, pool e restore (R05-17..22).

## 7. Critérios de aceite futuro por P0

| P0 | Evidência de desenho ainda pendente | Evidência de execução obrigatória em etapa posterior |
| --- | --- | --- |
| R01-01 | Máquina de sessão/MFA, inventário do emissor, recuperação, biblioteca/cookies/CSRF, bootstrap | Validação com emissor real e negativa Portal–CRM; não mocks |
| R01-02 | Autoridade de sessão/efeito, invariantes de lock/revisão, restores, contratos de operação | Corridas reproduzíveis com duas sessões e suspensão/reset; prova de corte sem efeito posterior |
| R01-03 | Mapa fechado de writers, grafo de grants/catálogo, ordem total, último gestor | Corridas role/membership/global com comprovação de ausência de write skew e allow stale |
| R01-09 | ERD físico, índices/constraints, FKs compostas, tenant imutável, resource owner | Testar mesma role runtime contra linhas A/B, cross-FKs e erros sem enumeração |
| R01-10 | Matriz de roles/GRANT/DDL/EXECUTE, RLS por comando, SQLi threat model, pool | Testes sob PG real com role efetiva, policies, SQL hostil e registro dos limites |

**Escopo de aceitação desta R05:** consolidar as especificações e evidências futuras, NÃO marcar item acima como PASS. Todos os P0 e gates G-ID/G-BB/D02-SEC/D03/RLS permanecem ABERTOS. Aprovação final gerencial permanece exclusivamente da Direção e exige avaliação consciente dos riscos.
