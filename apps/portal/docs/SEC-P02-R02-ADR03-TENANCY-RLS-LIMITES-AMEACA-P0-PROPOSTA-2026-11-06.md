# SEC-P02-R02 — ADR03: integridade multiempresa e limites candidatos de RLS

**Data:** 06/11/2026 (America/Sao_Paulo). **Atividade:** [CODEX-SEC-P02-R02 / Issue #30](https://github.com/rafhael100/imagine-portal/issues/30).
**Estado:** CORREÇÕES DOCUMENTAIS CANDIDATAS — GATES ABERTOS — AGUARDANDO PARECER HUMANO.

**Proveniência:** complemento novo, sem substituir nem editar os documentos históricos. Base documental do PR #24: `e76a9089727d8ccefd492cd660014ce3b22dc5d8`; PR #19: `75b32cef4afa0e7eae83e3b30fdc13c571a767d4`. Achados: [R01, comentário 6690120467](https://github.com/rafhael100/imagine-portal/issues/27#issuecomment-6690120467). Referências completas e matriz de provas no [documento de rastreabilidade](SEC-P02-R02-MATRIZ-ACHADOS-NEGATIVOS-PARECERES-PROPOSTA-2026-11-06.md).

**Limites:** somente Markdown. Nenhum código funcional, SQL executável, DDL, migration, conexão a banco, login/MFA real, laboratório, teste operacional, integração, dados reais, custo, merge, main/dev, alteração dos PRs #19/#24 ou VPS. Este texto não ratifica arquitetura, não resolve P0 por si só e não homologa gate. Termos de lock, chave e policy são requisitos em prosa, não implementação.


## 1. Problemas, premissas e opções de ameaça

Trata R01-09 e R01-10; lê em conjunto o [ADR01 R02](SEC-P02-R02-ADR01-CONTRATO-IDENTIDADE-SESSAO-P0-PROPOSTA-2026-11-06.md) e o [ADR02 R02](SEC-P02-R02-ADR02-AUTORIDADES-BARREIRAS-REVOGACAO-P0-PROPOSTA-2026-11-06.md). Sem fonte confiável, a igualdade entre campos de um AuthorizationContext e de uma OrganizationMembership fabricados pode produzir allow puramente lógico. Sem FK composta, uma atribuição A pode apontar para role B. Sem barreira comum, RLS e snapshot também podem ler grant antigo.

**Fronteira A candidata, preferida para discussão e NÃO aceita como risco:** aplicação/autenticador e caminho de operação confiáveis estabelecem ator, G e contexto; RLS reforça escopo e falhas de query sem filtro sob role limitada. Navegador, IDs de request, cookies não validados, jobs, headers e queries acidentais sem tenant são hostis/não autoritativos. A proposta deve mitigar SQL injection, mas **não garante isolamento diante de execução SQL arbitrária com poder de forjar todo o contexto do runtime**.

**Fronteira B alternativa, decisão pendente:** resistência a runtime totalmente comprometido ou a aplicações mutuamente hostis. Exigiria autoridade de identidade/contexto fora do alcance desse runtime, autorização/fencing verificável no ponto do efeito, capacidades restritas não forjáveis/replayables ou isolamento mais forte (credenciais/serviço/banco por domínio conforme ameaça). Apenas assinar um header no mesmo processo comprometido não atende. Nenhuma variante é selecionada, implementada ou comprovada aqui.

**DEC-R02-D pendente de Arquitetura/AppSec/DBA/Produto:** aceitar ou rejeitar a fronteira A para o objetivo futuro. Caso B seja requisito, A é insuficiente e o pedido de laboratório/implementação permanece bloqueado até redesenho. Não presumir aceitação por silêncio.

## 2. Modelo lógico de propriedade e referências, sem DDL

Todos os IDs são locais Portal e gerados por autoridade servidora. Tipos, variante UUID, tamanhos/índices e nomes físicos são pendências G-BB01/02; nenhum DDL acompanha este texto. Convenção: linhas privadas têm organization_id não nulo, estável e derivado de autoridade, não do payload. Todo relacionamento privado carrega a organização nas duas pontas.

| Relação lógica | Chave / unicidade candidata | Referências e invariantes exigidas |
|---|---|---|
| Conta Portal global mínima | portal_actor_id único; namespace fixo Portal | Identidade protegida do ADR01; não diretório de clientes. Sem FK ao CRM nem autorização por e-mail/CNPJ |
| Organização | organization_id único, estado/revisão | A empresa é dona dos dados, não criador/pagador; consulta privada exige vínculo/ação; criação é operação segregada |
| Membership | membership_id; par único (organization_id, portal_actor_id) durante todo o ciclo; chave candidata (organization_id, membership_id) | FK para conta local e organização; invited não autoriza, active exige vigência e demais estados; suspended/revoked/expired negam; reingresso não cria duplicata nem recupera grant antigo |
| Role local | role_id; únicos (organization_id, role_id) e (organization_id, código local) | Estado, validade, role_revision; não há role universal por nome owner/staff |
| Capability global fechada | capability_code único e versão/epoch global | Sem wildcard ou código desconhecido; estado ativo e semântica reconhecida pelo backend; catálogo técnico não contém PII |
| Grant de role | único (organization_id, role_id, capability_code) | FK composta (organization_id, role_id) à role; FK global ao catálogo; alteração sob G com tenant_policy_revision |
| Atribuição de role | único (organization_id, membership_id, role_id); validade/estado/revisão | Duas FKs compostas, à membership e à role **da mesma organização**; active de ambas e grant vigente necessários |
| Recurso privado genérico | resource_id e chave candidata (organization_id, resource_id); resource_revision | Organization_id imutável. Toda relação pai/filho usa (organization_id, parent_resource_id), nunca parent_id sozinho |
| Autoria contextual / trilha | evento opaco; (organization_id, event_id) para evento tenant | Alvo membership/recurso referenciado por par composto quando existente. Ator histórico global não prova poder atual; registrar também vínculo/escopo/revisões usados. Eventos globais de conta ficam em trilha global separada e restrita |
| Entitlement Portal | chave candidata (organization_id, entitlement_id), serviço/capacidade reconhecida, estado e vigência | Recursos/contratos privados referenciam por chave composta; evento externo é dado a validar, não fonte automática de grant |
| Intenção outbox / idempotência | (organization_id, intent_id); unicidade da chave idempotente dentro de tenant/ator/ação | FKs compostas a recurso/vínculo/entitlement quando aplicáveis, membership_incarnation e vetor esperado; não guardar allow nem cookie humano como credencial |
| Convite candidato | (organization_id, invite_id); destinatário/propósito e revisões vinculados | Concedente e role alvo em escopo composto; token só verificador seguro, single-use. Fluxo bloqueado até bootstrap/alçadas; convite não é membership ativa |
| Sessão/desafio/credencial globais | Identificador local + conta Portal, revisões e propósito | Global **não** significa legível por todo runtime; sem ownership empresarial implícito e sem compartilhar dados com CRM |

Na cadeia pai/filho, ambas as colunas de escopo devem ser obrigatórias; nenhuma FK opcional pode usar NULL para escapar do tenant. Relações de catálogo/conta global são exceções explicitamente inventariadas, não um padrão para dados privados. Novos recursos/tabelas/auditoria/arquivos/metadados e relações indiretas precisam entrar no inventário antes de acesso.

Integridade adicional: fim de vigência posterior ao início, início inclusivo/fim exclusivo, estados desconhecidos negados, nenhuma exclusão em cascata que apague prova de revogação/autoria, unicidade concorrente garantida pela fonte e não por pré-check em memória. A revisão é alterada na mesma transação do estado/auditoria. Semântica de recuperação/anonimização requer Privacidade.

Trocar organization_id para “transferir” recurso é proibido inclusive em UPDATE, bulk import, cópia, trigger ou função. Transferência entre empresas, se necessária no futuro, exige workflow novo com autoridade das duas pontas, conteúdo permitido e trilha, não correção administrativa silenciosa.

## 3. Fonte única, projeção e fluxo de autorização

1. Autenticador local resolve sessão/conta atuais sob G conforme ADR01. Organização do request é somente intenção.
2. Resolvedor oficial busca membership pelo par ator comprovado/organização e estado/tempo; nenhuma hidratação de actor, grants, role ou membership vindos do HTTP/CRM. Reingresso continua bloqueado até alçada aprovada.
3. Sob a mesma G e transação, fonte única calcula capacidades a partir de atribuições locais, roles e grants ativos/vigentes e catálogo conhecido/ativo, além de organização/entitlement. Não há união com grants diretos paralelos, allow em sessão ou config do cliente.
4. Recurso é localizado por (organization_id autorizado, resource_id). Antes de materializar conteúdo, conferir ação granular e ownership; UUID isolado nunca prova titularidade. Billing, gestão de membros, manutenção de política, concessão e auditoria são capacidades diferentes.
5. Projeção efêmera server-side para o avaliador puro B-A inclui referências oficiais atuais e a ação pedida. O avaliador não comprova emissor, revisão, RLS ou linearização. Seu allow só tem uso dentro da unidade protegida e não viaja como autoridade para outra request/job.
6. Executar/materializar sob escopo, policies e G; efeito+idempotência+audit no mesmo commit. Qualquer componente ausente, duplicado, desconhecido, stale ou contraditório nega.

Erro de constraint/FK/unicidade pode revelar existência mesmo com RLS. Validar escopo antes da tentativa e converter a resposta externa em erro não enumerável; detalhe SQL/catalog/ID alheio não vai ao cliente. Tratamento de tempo/respostas requer teste AppSec próprio, não promessa de invisibilidade por usar UUID.

## 4. Bootstrap mínimo e caminhos de descoberta

A descoberta “minhas organizações” requer sessão pós-fator válida e operação de leitura restrita ao próprio ator comprovado. Não exige selecionar um tenant desconhecido previamente e não usa um tenant default. Proposta: interface separada que devolve somente referências mínimas das memberships elegíveis desse ator, sob G, sem PII de terceiros, grant detalhado ou dados de negócio. Sem identidade válida, devolve negação genérica antes de listar qualquer organização.

A role de discovery não ganha SELECT amplo em recursos privados nem parâmetro livre “actor para pesquisar”. Resolver a circularidade entre policies de membership e consulta de elegibilidade é obrigação do avaliador mínimo §6, com grafo sem recursão. Falta de desenho desse avaliador bloqueia discovery, não autoriza bypass.

Bootstrap do primeiro administrador Portal e do primeiro gestor empresarial são operações diferentes, fora do autosserviço sem mandato. Convite/verificação de canal não atribui gestor. Inicialização exige alçada/prova de origem ainda pendentes de Produto/AppSec; suporte/CRM/pagamento/domínio de e-mail não substituem essas provas. O fluxo real continua desativado no contrato candidato enquanto R01-06 estiver aberto.

## 5. Inventário candidato de roles e privilégios

O quadro é requisito de desenho, não lista de usuários criados ou GRANTs executados. Relações de herança/SET ROLE e privilégios de PUBLIC precisam ser inventariados transitivamente no ambiente futuro.

| Papel lógico | Permissão mínima candidata | Restrições e exame independente |
|---|---|---|
| Owner de schema/policies | Mantém objetos por processo de mudança separado; sem tráfego de request | Sem login direto para runtime; nenhum membership ou SET ROLE do runtime para owner. FORCE RLS onde apropriado não torna owner/superuser adversário contido |
| Migrador de implantação | Só promoção autorizada com credencial separada, sem uso em workers/web | Não existe autorização de executar migrations aqui. DDL/alteração de policy só com serving controlado, revisão e compatibilidade |
| Runtime web/job | Leitura apenas em interfaces invoker/relações aprovadas sob RLS; execução de operações nomeadas mínimas | Sem superuser, BYPASSRLS, owner, DDL, TRUNCATE, CREATE em schemas de resolução, gestão de roles, replicação ou funções genéricas elevadas. Não lê verificadores/segredos/auditoria global |
| Escritor de operação interna | Operação fixa de negócio/autoridade obtém G antes de reler e escrever; confere alçadas/escopo e registra audit | Proposta é retirar DML direto genérico do runtime em autoridades e efeitos sensíveis, para não permitir ignorar G. Entry points específicos não podem aceitar SQL, nome de tabela, actor “autenticado” ou grant arbitrários como autoridade. Implementação e owner/GRANT/EXECUTE exigem revisão; não aprovar função SECURITY DEFINER genérica |
| Emissor/validador de identidade | Acesso mínimo a registro de conta/sessão/desafio necessário ao contrato | Separação de credenciais/processos, se usada como fronteira forte, precisa prova. Mesmo principal SQL compartilhado não estabelece isolamento entre componentes |
| Avaliador privado de elegibilidade | Consulta apenas metadados mínimos de identidade/tenant/política, resposta booleana contextual | Sem catálogo privado enumerável, SQL dinâmico, DML de negócio, impersonação ou actor arbitrário; grafo e owner mínimo §6 |
| Auditor | Append de evento pela operação autorizada, leitura tenant por capacidade explícita | Runtime não atualiza/apaga trilha; acesso global operacional separado, finalidade/retenção aprovadas |
| Discovery | Interface limitada às organizações do próprio sujeito validado | Não usa BYPASSRLS universal nem retorna existência de tenant por ID fornecido |
| Maintainer de catálogo/política | Mutação por fluxo específico com G, epoch e auditoria | Não herdado por owner empresarial, não editável pelo cliente; deploy que muda semântica também requer controle |
| Role de ensaio futura | Permissões necessárias para exercitar policies e negativos, separadas dos atores reais | Não tornar owner de teste prova de RLS runtime, nem ampliar grant de produção para “fazer teste passar” |

A barreira G não é um predicado RLS que prova identidade. O desenho físico precisa demonstrar que todos os caminhos de escrita sensível passam pelas operações que tomam G. Se houver DML direto, função ou trigger capaz de ignorar a disciplina, a garantia de revogação é invalidada. Funções elevadas aumentam superfície e podem ser recusadas pelo DBA em favor de outro desenho. Não supor que retirar DML direto resolve runtime hostil: chamar entry point com contexto forjado continua risco se não houver autoridade externa não forjável.

## 6. Policies por comando, funções e oracles

| Superfície | Contrato candidato verificável |
|---|---|
| SELECT | Predicado USING combina organização da linha com contexto válido, sujeito/empresa/vínculo vigentes e capacidade da ação; contexto ausente/inválido nega. Sem contexto não é “todos” nem tenant default |
| INSERT | WITH CHECK no novo estado: tenant autorizado/imutável, FKs compostas, autoria servidora e capacidade de criação. Runtime sem INSERT direto continua sendo testado quanto à negação; operação controlada também precisa preservar esses requisitos |
| UPDATE | USING no estado antigo e WITH CHECK no novo, capacidade específica e imutabilidade de tenant; checar colunas sensíveis, upsert, conflito e referências indiretas. Permissão de ler não implica atualizar |
| DELETE | USING e privilégio de operação próprios; negação por padrão para histórico/autoridade onde exclusão não é admitida. Cascades não apagam revogações |
| Policies múltiplas | Inventário completo por role/comando: policies permissivas podem se combinar por OR e ampliar acesso. Barreira de tenant restritiva, quando suportada/aplicável, e matriz de composição devem ser revisadas; policy ausente ou genérica não pode servir de bypass |
| Funções/avaliador | Nomes qualificados, search_path fixo sem schema controlável pelo runtime, sem SQL/nome dinâmico, grants EXECUTE mínimos e sem default PUBLIC excessivo; owner dedicado sem login com alcance mínimo. Rejeitar recursão membership→role→membership e retorno que revele existência de terceiro |
| Views/triggers/partições | Mapear invoker/owner conforme versão PG, funções chamadas, triggers de auditoria e FKs; proibir acesso direto a partição ou caminho alternativo que contorne policy. FORCE RLS/owner e subconsultas precisam exame por objeto |
| Catálogos/constraints/erros | Unicidade/FK não é filtro de confidencialidade; mensagens, cardinalidade, timing e função booleana podem ser oracle. Respostas mínimas/contextuais e diagnóstico restrito; sem garantir impossibilidade geral de canal lateral |
| Exports/jobs/CLI | Mesma resolução, G e policy; conta técnica não herda sessão humana ou tenant global. Manutenção não usa credencial privilegiada no request |

RLS é segunda barreira; não substitui FK composta, autenticação, alçada de concedente ou G. Superuser/BYPASSRLS não são contidos por FORCE RLS; owner capaz de alterar objetos também é autoridade operacional a segregar. Revisar transitivamente role membership, possibilidade de SET ROLE, ownership, default privileges, schema CREATE, EXECUTE, views/funções indiretas, extensões e acessos administrativos antes de considerar o modelo revisável para laboratório.

## 7. Contexto, SQL injection e pooling

Contexto local de transação transporta actor/tenant/correlação; não é token secreto. Aplicação confiável só o preenche a partir da sessão local validada e fontes oficiais, na mesma conexão/transaction que materializa o efeito, depois da aquisição de G e antes de dados privados. Nenhuma consulta a recurso privado ocorre para “descobrir” tenant; discovery é a interface separada §4.

SQLi que só omite filtro pode ser contida pela policy correta sob role mínima. SQLi capaz de substituir todos os parâmetros locais pode representar outro actor/tenant se o banco confiar nesses parâmetros. **Esse ataque pode passar pelo desenho A; o teste deve registrar a insuficiência da fronteira, não fabricar um resultado de negação.** Sob fronteira B seria resultado impeditivo até que contexto/autoridade não forjável fosse demonstrado. Mesmo com contexto não forjável, replay, escopo e vínculo ao efeito precisam desenho próprio.

Mitigações candidatas: consultas parametrizadas e validação de tipos; nomes/ordenações por vocabulário fechado, sem concatenação de input; nenhuma função SQL dinâmica/elevada genérica, credencial owner no processo, acesso a arquivo/OS/rede por privilégio desnecessário ou gestão de roles. Controle de dependências/segredos/logs e revisão manual dos sinks SQL são pendências AppSec; não foi realizado teste ofensivo ou inventário completo de código.

Cada request/job começa sem contexto/objetos do anterior. Afinidade de conexão obrigatória até commit/rollback; contextos em conexão diferente, réplica ou pool por statement são incompatíveis. Erro, timeout, cancelamento, savepoint/rollback parcial, prepared statement, troca de tenant e retry exigem ensaio real com driver/pooler escolhidos, sem confiar que “SET LOCAL limpa tudo” em qualquer uso. Após falha incerta, conexão não volta ao pool para uso privado até término/limpeza comprovados; retry reabre unidade completa e relê autoridade.

## 8. Casos futuros, risco residual e decisão

Todos **PLANEJADO / NÃO EXECUTADO**: R02-N01/08/09/10/11/12/15, ID-A01/02/04/06 e REV-04/07/10; H01..07/H10/H11, C01..03/C06..10, P01..03/P06..08/P10/P12 segundo matriz. Exigir zero linha/atributo de outra empresa e zero alteração/intent/audit indevida, erro não enumerável e privilégios observados sob role real. DML direto negado por GRANT não prova sozinho que a policy da operação autorizada está correta.

Riscos abertos: SQL arbitrário/runtime hostil sob contexto forjável; abuso de função privilegiada; bootstrap circular; caminho de escrita que ignora G; omissão no inventário de objetos; restore que retrocede política; custo e disponibilidade da serialização. AppSec independente deve avaliar ameaça/origem/SQLi e DBA independente deve revisar ERD, privilégios transitivos, policies/recursão/pool/locks. Produto/Arquitetura precisam deliberar DEC-R02-D e a fronteira exigida. Não há parecer humano ou validação dinâmica. G-ID01/05/06/09, G-BB02/03/04/05/06/08, D02-SEC, D03 físico/RLS e P0 continuam abertos.
