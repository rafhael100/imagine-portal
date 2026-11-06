# PORTAL-F1-01-B-B-D02 — Contratos candidatos de permissões, revogação e avaliador RLS

**Data:** 06/11/2026 · **Estado original:** proposta documental para deliberação. **Atualização posterior:** alternativas A de BBD02-01 a BBD02-06 ratificadas **somente como diretrizes documentais preliminares** pela [DEC-02](PORTAL-F1-01-B-B-DEC-02-RATIFICACAO-DIRETRIZES-BBD02-01-A-06-A-2026-11-06.md); gates, contratos e testes seguem não homologados. **Sem implementação, merge ou deploy.**  
**Precedência:** [D01 — modelo físico candidato](PORTAL-F1-01-B-B-D01-MODELO-FISICO-CANDIDATO-ISOLAMENTO-2026-11-06.md) e [DEC-01 — BB01–BB08 alternativas A ratificadas preliminarmente](PORTAL-F1-01-B-B-DEC-01-RATIFICACAO-DIRETRIZES-BB01-BB08-2026-11-06.md).  
**Escopo permitido:** apenas documentação de contratos técnicos e critérios futuros. Proibido SQL executável, migration, login, esquema em operação, conexão de banco, código de autorização real, alteração de `main`, merge ou deploy.

## 1. Problema e fronteira

O domínio F1-01-B-A tem `OrganizationMembership` com lista explícita de `Permission` em memória. BB03-A aponta papéis locais versionados e catálogo fechado como fonte futura de concessões. **É necessário definir como uma fonte de autoridade persistida produz essas permissões sem duas fontes concorrentes de poder**, sem atualizar o código funcional antes da autorização separada.

Do mesmo modo, BB04-A prevê revogação com barreira transacional, enquanto BB05-A propõe avaliador privado candidato para evitar recursão nas políticas RLS. **RLS e contexto de transação não autenticam o ator** e não impedem um runtime com permissões excessivas; a arquitetura deve prever autoridade do Portal, funções mínimas e revisão independente antes de habilitar acesso a dados privados.

Em todos os desenhos, um ator Portal identificado por sessão local verificada escolhe uma empresa **como pedido**, nunca como prova de permissão. Vínculo, políticas, empresa do recurso e versão são obtidos de autoridade confiável. Falha na consulta ou conflito de revisão significa NEGAR.

## 2. Contrato candidato de concessões e projeção para o domínio B-A

**Fonte de verdade candidata:** catálogo global fechado de permissões técnicas, papéis próprios de cada organização e atribuição vigente de papéis ao vínculo canônico. Nenhuma permissão se origina exclusivamente do nome do papel (owner/manager/member), e não existe correspondência automática entre cliente, pagador e administrador.

**Projeção:** para cada ator Portal, empresa e instante de autorização, carregar vínculo canônico ativo/vigente, papéis locais ativos/vigentes do vínculo e suas permissões efetivas, limitadas ao catálogo reconhecido e à revisão validada. Produzir somente uma **fotografia de permissões efetivas** que possa ser passada ao avaliador puro B-A, e **nunca** confiar em array de permissões enviado pelo navegador, agente, CRM ou API. Recalcular dentro de transação em operações sensíveis. Não conceder wildcard nem preencher permissão implícita por omissão de informação.

**Diferenciação de capacidades:** ler catálogo não concede modificar papéis; a permissão `members.manage` do exemplo B-A não implica gerenciar a própria alçada, aumentar poderes do próprio usuário, conceder poderes que o concedente não possui ou desativar último responsável; é preciso definir a matriz real de ações/atribuições e validação de concedente por recurso/empresa.

**Mudança/versão:** qualquer alteração de role, grant, membership ou revisão organizacional relevante incrementa a revisão no mesmo ponto transacional do evento de auditoria. Leitura de permissão de versão antiga não autoriza efeito futuro.

## 3. Contrato candidato de revogação concorrente — BB04-A

Uma revogação de vínculo ou poder é efetiva após commit da transação oficial de revogação. Para ações **com efeito sensível**, a verificação da autorização, o registro auditável e o efeito devem competir com a revogação em uma **barreira serializante comum** e de granularidade controlada. O desenho físico de lock e algoritmo de retry é **pendente de decisão e teste**.

- Se ação autorizada obtém a barreira primeiro, sua conclusão deve ser anterior ao commit da revogação, salvo regra mais restritiva;
- Depois do commit da revogação, nenhuma nova ação sensível poderá usar decisão, sessão, job ou cache com versão antiga;
- Reexecução por conflito deve buscar novamente usuário, vínculo, política e escopo, nunca reutilizar `allow` anterior;
- Logs e trilha de autoria não podem falhar silenciosamente em concessão/revogação; indisponibilidade deve falhar fechado;
- Exportações longas e arquivos já entregues exigem contrato de cancelamento/revalidação por lote e não prometem revogação retroativa;
- Uma política de isolamento PostgreSQL (READ COMMITTED/REPEATABLE READ/SERIALIZABLE) isoladamente **não prova** esse contrato sem barreira, testes de corrida e revisão de replicação.

**Pontos a homologar:** conjunto de linhas a bloquear, ordem determinística, políticas de deadlock e timeout, idempotência de retry, efeitos externos não transacionais e leitura por réplica. Não autorizar efeitos externos antes da revisão específica desses mecanismos.

## 4. Contrato candidato do avaliador de elegibilidade para RLS — BB05-A

A policy de RLS deverá validar o escopo da linha e o contexto local da transação sob role de runtime sem BYPASSRLS; o avaliador candidato lida com elegibilidade de vínculo/política em um **escopo mínimo e auditável**. Pode haver funções internas de privilégio elevado apenas se a revisão independente demonstrar que não operam como bypass genérico, oracle entre tenants ou vetor de SQL injection.

Controles necessários antes de codificar:
- Roles distintas para migrador/owner, runtime, avaliador, auditoria e testes; runtime não é owner, superuser, BYPASSRLS ou gestor de privilégios;
- Consultas qualificadas por schema e caminho de nomes controlado; sem interpolação dinâmica de nomes, SQL arbitrário, grants ou impersonação;
- Resposta do avaliador apenas **sim/não** para o contexto autenticado, sem devolver existência ou atributos de outros tenants;
- Políticas de tenant não podem ser ampliadas por composição permissiva OR; SELECT, INSERT, UPDATE e DELETE devem observar linha antiga e nova e proteção contra troca de `organization_id`;
- **Evitar recursão circular** ao consultar membership, roles e tabela de grants sob RLS; a arquitetura do avaliador deve ser verificada por análise de dependências;
- Não considerar variáveis `SET LOCAL` como tokens de identidade autenticados: a aplicação deve estabelecer ator antes; o runtime não pode escolher autoridade arbitrária sem controle.
- Views, funções auxiliares, triggers, replicação, manutenção, exportação, restauração e jobs devem obedecer à mesma política e possuir testes próprios.

**Status:** alternativa de avaliador privado mínimo é *diretriz de BB05-A*, não autorização de função com privilégios elevados, e muito menos aprovação de uma implementação particular.

## 5. Critérios de homologação técnica (propostos, não executados)

| Prova | Caso negativo obrigatório | Evidência futura |
|---|---|---|
| C01 — Projeção de papéis | Role sem permissão explícita não concede; role suspenso/revogado retira grant | Testes de matriz comparando persistência autorizada com domínio B-A |
| C02 — Alçadas | Usuário tenta conceder a si, elevar terceiro além do próprio mandato ou remover último administrador | Casos de concessão controlada com negação e trilha |
| C03 — IDs e emissor | Mesmo texto de ID em Portal e CRM; e-mail/CNPJ igual | Identidade da fonte Portal verificada, sem SSO por igualdade |
| C04 — Barreira de revogação | Duas transações concorrentes: revogação antes e depois de ação sensível | Prova de ordem/efeito e logs com versões |
| C05 — Cache/replica | Sessão, job, cache ou réplica apresentam versão anterior à revogação | Nenhum novo efeito com grant obsoleto |
| C06 — RLS sem contexto | SELECT/INSERT/UPDATE/DELETE por runtime sem contexto, contexto de tenant alheio | Negação sem vazamento de linhas ou metadados |
| C07 — Recursão RLS | Policy consulta membership por ciclo, função auxiliar com excesso de privilégios | Revisão de grafo de dependências e grants sob role real |
| C08 — Pooling | Reutilização de conexão A→B após commit, erro, rollback, timeout e retry | Ausência de vazamento de contexto |
| C09 — DDL/restore | Runtime tenta DDL, TRUNCATE, desabilitar RLS; restore ressuscita acesso revogado | Proibições/grants e reconciliação antes de reabrir |
| C10 — Auditoria | Evento de grant falha, logs contêm tokens/PII ou cliente tenta lê-los | Transação abortada e logs mínimos restritos |

Nenhuma prova acima foi executada. A bateria H01–H12 da D01 continua pendente; C01–C10 a detalha, sem substituí-la.

## 6. Decisões D02 para deliberação humana

Todas as opções abaixo são **candidatas para aprofundamento documental**, não implementações prontas:

| ID | Alternativa A | Alternativa B | Alternativa C | Orientação inicial não vinculante |
|---|---|---|---|---|
| **BBD02-01** Fonte da permissão efetiva | Projeção unidirecional de papéis/versionamento para domínio B-A, sem grants diretos | Grants diretos como fonte exclusiva | Papéis + grants diretos sem regra de precedência | **A**; evita duas fontes de verdade |
| **BBD02-02** Controle de poderes do concedente | Matriz explícita de alçadas com proibição de autoelevação e proteção de último gestor | Delegação apenas por administrador externo controlado | Owner implícito controla qualquer permissão | **A**; B somente mediante política aprovada |
| **BBD02-03** Revogação transacional | Verificação + efeito sensível sob barreira/revisão única | Snapshot com invalidação eventual | Permitir com sessão até expiração | **A**, condicionado a testes de concorrência |
| **BBD02-04** Auditoria de grant/revogação | Evento mínimo atômico com revisão, fail-closed se gravar falhar | Auditoria assíncrona com compensação | Nenhuma auditoria para mudanças | **A**, sujeito a retenção LGPD |
| **BBD02-05** RLS e avaliador | Avaliador interno mínimo de elegibilidade, revisto por Segurança/DBA | Projeção privada de elegibilidade transacional | Runtime com bypass genérico | **A como candidata**; B é fallback se A não demonstrar segurança |
| **BBD02-06** Contexto de transação | Contexto por transação, sem reuso implícito, com prova de afinidade de conexão | Sessão persistente com reset obrigatório | Pool por statement ou contexto global | **A**, sujeito a teste de pooler/driver |

**Solicitação de avaliação:** decidir cada alternativa **somente como orientação documental preliminar**, preservando o direito de pedir correções. A aprovação destas decisões não fechará D02-SEC, D03 físico/RLS, P0-DOC06 ou qualquer outro gate, não criará autorização para programação, SQL, migrations, merge nem deploy.

## 7. Próxima especificação condicionada e impedimentos

Antes de liberar um recorte de código funcional, é preciso um D03 físico e uma revisão técnica/segurança independente da autoridade Portal, catálogo e alçadas de permissão, mecanismo de barreira/revogação, RLS/privilege grants, transação e pooling. Definir PostgreSQL e UUID, política de reingresso, último administrador e retenções por finalidade.

**O que fica expressamente proibido nesta etapa:** migrations, SQL executável, acesso PostgreSQL, Eloquent persistente, rotas/API, login, MFA, sessões, SSO Portal–CRM, reutilização do banco CRM, dados reais, agentes executores, VPS, merge em qualquer branch e deploy.

**Status final:** DOCUMENTAÇÃO PARA DELIBERAÇÃO; GATES DE SEGURANÇA ABERTOS.
