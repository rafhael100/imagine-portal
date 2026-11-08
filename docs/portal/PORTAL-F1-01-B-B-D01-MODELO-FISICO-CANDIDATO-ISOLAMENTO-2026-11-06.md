# F1-01-B-B-D01 — Modelo físico candidato e contratos de isolamento

**Data:** 06/11/2026 (America/Sao_Paulo). **Estado:** proposta documental para deliberação humana; nenhuma alternativa abaixo está aprovada por este documento.

Entrega da [Issue #17](https://github.com/rafhael100/imagine-portal/issues/17). Não contém SQL executável, migrations, modelos persistentes, login/MFA ou integração. B-B funcional exige autorização expressa separada. Aprovar esta proposta não autoriza execução, merge ou deploy.

## 1. Evidências, precedência e destino Git

- Acesso Git autenticado confirmado por consulta de refs, sem escrita. A falha inicial de conexão ao proxy foi resolvida habilitando a permissão de rede na execução do comando; nenhum helper ou credencial foi alterado.
- Base de derivação: `dev/fast-track-portal-2026-11-05`, SHA `0b28ae8d93adaa08889584e07215d7f2858ba2e6`. Inclui o merge do PR #15 em `19bb0aa78c6e97194cc6fa7be406a755f3374712` e avanço posterior P02-A. O SHA indicado originalmente na issue é histórico, não o HEAD atual.
- PR #15: fechado e integrado somente em dev. PR #11: aberto, draft, não integrado, destino `main`. `main` conferida em `50b683a499e674641df5749051f3d05f7ac3f627`.
- Branch filha exclusiva: `docs/portal-f1-01-b-b-proposta-2026-11-06`. PR draft dirigido a dev, conforme autorização documental da Issue #17 e precedente de revisão em ramos filhos; somente este arquivo em `docs/...`. A branch pai não é editada.
- Fontes de planejamento lidas na branch `docs/portal-f1-00-2026-11-05`, congelada nesta consulta em `48c6e8a223f4ff96a2fc364fb09b7a9a2c31c759`. Links canônicos estão na seção 11 porque esses documentos não estão na árvore dev.

D01 aprova Laravel/PostgreSQL; D02 aprova logins independentes; D03 aprova apenas direção lógica e backend novo. D03-R02 prevalece sobre a hipótese anterior de migração obrigatória: legado é protótipo com dados de teste, aproveitar ideias e fluxos. DEC-01 autorizou somente B-A. RV01 identifica fontes não autenticadas, IDs opacos sem emissor e revogação sem contrato; RV02 documenta esses limites e amplia testes sintéticos. O estado histórico “PR #15 draft” no relatório RV02 foi superado pelo estado GitHub consultado, sem liberar B-B.

## 2. Fronteiras e invariantes

Uma identidade humana Portal pode ter N vínculos em N organizações; cada vínculo pertence a exatamente uma identidade e uma organização. A organização é dona dos recursos privados, não o criador nem o pagador. Contratante PF/PJ, organização operacional e pessoa autenticada são conceitos separados; CPF/CNPJ não são obrigatórios na identidade nem chaves de autorização.

Nenhuma igualdade de e-mail, nome, CNPJ ou texto de ID externo prova vínculo. Colaborador Imagine, owner, agente ou conta técnica não recebe acesso global implícito. Banco, credenciais, sessões, recuperação e autoridades CRM permanecem separados; não haverá consulta ao banco CRM, SSO ou importação de credenciais. Eventuais referências externas futuras serão opacas e escopadas, sem FK entre bancos e sem armazenar leads no Portal.

Permitir exige cumulativamente: ator Portal autêntico e ativo, organização selecionada ativa, vínculo canônico ativo e vigente, permissão explícita vigente, recurso pertencente à organização e revisão de autorização atual. Falha, ambiguidade ou ausência de contexto significa negar. Contrato/pagamento não substitui autorização de acesso nem comprova poderes para aceite jurídico.

## 3. Dicionário físico candidato — sem materialização

Convenções propostas: IDs UUID gerados pelo servidor; versão PostgreSQL e variante UUID a aprovar; IDs opacos nunca são segredos. Instantes em `timestamptz`, armazenamento UTC e apresentação conforme contexto. Campos de validade usam intervalo fechado no início e aberto no fim; fim nulo significa sem término programado. Relógio servidor/banco será a referência futura. `revision` inteira positiva permite controle otimista. Nomes e tipos são candidatos.

| Entidade candidata | Chaves e atributos mínimos | Integridade/autoridade |
|---|---|---|
| `portal_users` | PK `id`; `status`; `authz_revision`; criação/alteração; referência local de identidade | Fonte exclusivamente Portal. Sem senha, MFA, sessões ou e-mail desenhados nesta etapa. Perfil privado global, nunca diretório de clientes |
| `customer_organizations` | PK `id`; nome de exibição; `status`; `authz_revision`; autoria local; instantes | Identidade operacional estável; nome/CNPJ não identificam permissão. Nome não precisa ser globalmente único |
| `organization_memberships` | PK `id`; `organization_id`; `portal_user_id`; `status`; `valid_from`, `valid_until`; `revision`; autor/motivo/instante da transição | FKs para organização e usuário; único par organização/usuário em todo o ciclo; chave única adicional `(organization_id, id)` para referências compostas |
| `organization_roles` | PK `id`; `organization_id`; `code`; `status`; `policy_revision` | FK organização; únicos `(organization_id, code)` e `(organization_id, id)`. Papel é agrupador proposto, não privilégio universal |
| `permissions` | PK `code`; descrição; `status`; versão de catálogo | Catálogo técnico global fechado, sem dados de cliente, sem wildcard; somente leitura pelo runtime |
| `role_permissions` | `organization_id`, `role_id`, `permission_code`; instantes/autor | PK candidata da tripla; FK composta `(organization_id, role_id)`; FK catálogo. Alteração exige controle de revisão e de autoridade |
| `membership_roles` | `organization_id`, `membership_id`, `role_id`; validade, estado, revisão, autoria | Única tripla; FKs compostas para membership e papel na mesma empresa. Suspensão de qualquer componente retira o efeito |
| `membership_audit` | PK evento; `organization_id`; alvo; ator Portal; ação; revisão anterior/nova; motivo codificado; correlação; instante | Registro append-only protegido; vínculo composto ao alvo quando existente. Sem apagar histórico por desligamento |

Os papéis B-A `owner`, `manager`, `member` continuam descritivos. O catálogo inicial candidato espelha `services.view`, `services.manage`, `billing.view`, `members.manage`; não inventa permissões financeiras ou contratuais a partir desses nomes. A tabela papel→permissão e quem pode atribuí-la exigem aprovação. Recomenda-se normalizar concessões por papéis locais versionados; concessões diretas adicionais ficam fora da primeira persistência para evitar duas fontes de verdade. Essa recomendação não altera os objetos B-A existentes.

Convite sem identidade Portal comprovada não cria vínculo ativo nem usuário por heurística de e-mail. Recomenda-se reservar o fluxo de convite/token para B-C; ao haver identidade local validada, um vínculo pode representar `invited`. Nenhum token será desenhado ou emitido aqui.

### 3.1. Constraints, autoria e índices

Todos os campos de escopo, FKs e chaves são não nulos nas linhas tenant. Validade deve ter fim posterior ao início; estados desconhecidos não são aceitos. Usuário/organização ativos não tornam vínculo ativo por inferência. Recursos privados futuros carregam `organization_id` imutável e chave única `(organization_id, id)`: toda relação entre recursos privados usa ambos os campos na FK. Exemplo conceitual: um projeto A não pode referenciar serviço B nem autoria de membership B.

Autoria distingue `created_by_portal_user_id` (fato histórico global) de `authorized_membership_id` e organização (prova contextual). Ator histórico pode estar revogado; isso não invalida a prova antiga nem concede direito atual. Sem exclusão em cascata de usuários, organizações, vínculos ou provas. Exclusão física e anonimização dependem de retenção aprovada; referência histórica não guarda PII desnecessária. Eventos de sistema têm origem tipada explícita, sem fingir identidade humana; conta técnica/delegação ainda não aprovada não pode gravar por conveniência.

Índices candidatos: par único do vínculo; busca por usuário/organização/estado; FKs compostas em tabelas filhas; organização/estado/código dos papéis; organização/instante e correlação da auditoria. Índices de validade e seletividade dependem de plano de consulta com fixtures. Particionamento e tuning não são decisões fechadas. Erros de FK/unicidade podem revelar existência de terceiros: validar escopo antes e devolver erro externo genérico, com diagnóstico interno restrito.

## 4. Estados, revogação e concorrência

| Objeto | Estados candidatos | Transições/regras |
|---|---|---|
| Usuário | `active`, `suspended`, `revoked` | Suspensão/revogação bloqueia todos os vínculos no Portal; não altera conta CRM |
| Organização | `active`, `suspended`, `archived` | Apenas ativa admite operações de cliente; arquivo não apaga contratos/provas |
| Membership | `invited`, `active`, `suspended`, `revoked` | Convite não autoriza. Ativação requer aceite e autoridade comprovados em fluxo futuro. Suspenso exige decisão explícita para voltar; revogado permanece sem acesso |
| Papel/concessão | `active`, `suspended`, `revoked` | Ausência, expiração ou revogação nega; papel revogado não mantém grants em cache |

O par organização/usuário tem uma linha canônica, preservando `id` e histórico. Reingresso após revogação exige nova concessão expressa e nova revisão/evento, nunca ressurreição automática; o processo de reingresso permanece gate B-C. Não manter duas linhas ativas ou escolher “a mais recente” entre duplicatas. Concorrência de criação deve terminar em unicidade ou conflito controlado.

Revogação prevalece sobre sessão, cache, mudança de empresa, contrato e papel antigo. Toda mudança de estado/permissão incrementa revisão no escopo pertinente na mesma transação que registra auditoria. Recomenda-se não usar cache positivo para decisões sensíveis na primeira versão; TTL isolado não garante revogação. Uma eventual chave de cache inclui usuário, organização, recurso/ação, revisões de usuário, organização, vínculo e política. Indisponibilidade da autoridade bloqueia a operação.

**Contrato candidato de linearização:** ação sensível e revogação competem pela mesma barreira transacional de autorização (organização, usuário, vínculo e versão de política pertinentes). Ordem fixa de locks deve evitar deadlocks. Revalidar estado, validade, permissão, autoria e recurso dentro da transação que produz efeito. Quem obtém a barreira primeiro conclui antes da revogação; após commit da revogação nenhuma nova ação pode ser autorizada com revisão antiga. Falha de revisão/serialização exige nova avaliação integral, com repetição limitada e idempotente; nunca repetir um `allow` antigo. A escolha exata de locks/isolamento precisa de projeto e testes antes da implementação.

RLS com snapshot não resolve sozinha TOCTOU: `REPEATABLE READ` pode manter autorização antiga; `READ COMMITTED` também não torna duas instruções atômicas. Leituras/exportações longas e downloads devem ter limites, revalidação por lote e cancelamento definido; bytes já entregues não são recuperáveis. Recomenda-se negar início após revogação e interromper lotes subsequentes, sem prometer revogação retroativa. Jobs carregam referências e revisão esperada, não decisões; ao executar revalidam tudo. Nenhum job ou download é implementado aqui.

Mudança de empresa encerra o contexto anterior: descartar objetos carregados, permissões e cache da UI; abrir nova unidade transacional validada. Não transferir ownership por atualização de `organization_id`. Eventual transferência exige fluxo próprio e nova autorização, fora deste recorte. Impedir autoelevação e atribuição de permissões além da alçada do concedente. Remoção do último administrador autorizado requer transferência controlada/recuperação humana sem bypass global.

## 5. Proveniência e unidade de conexão

Fluxo futuro obrigatório: autenticação exclusiva Portal → resolver `portal_users` na fonte local oficial → validar estado/revisão → validar organização solicitada como entrada não confiável → localizar vínculo canônico por usuário e organização → obter política atual → resolver recurso na fonte privada já escopada → avaliar permissão → executar sob a mesma barreira → registrar resultado mínimo. ID de ator, membership, grants ou dono enviados pelo cliente nunca são prova. IDs externos iguais aos locais devem ser recusados como origem de autenticação.

O contexto de banco deverá incluir ator Portal, organização, correlação e revisão verificada. A aplicação usa transação explícita e configuração local à transação, descrita como `SET LOCAL`, sem instruções SQL neste documento. A configuração ocorre antes de qualquer consulta privada, na mesma conexão e transação. Commit, rollback, timeout, erro e retry encerram o contexto; próxima operação começa sem tenant. Ausência/contexto malformado deve negar, sem cair em empresa padrão.

Pooling por transação exige afinidade de todas as instruções até o término; pooling por statement é incompatível com esse contrato. Não confiar em variável de sessão persistente, conexão anterior, middleware que configura outra conexão, réplica atrasada ou estado de worker reutilizado. Jobs, tarefas agendadas, exportações e CLI seguem o mesmo contrato, sem tenant global. Homologar pooler/driver, prepared statements, savepoints, rollback e troca de conexão; sem comprovação, bloquear operação privada.

**Limite de confiança:** parâmetros locais de conexão não autenticam o ator contra uma aplicação comprometida. Um runtime com SQL arbitrário pode tentar alterar o contexto. RLS é defesa adicional contra consultas sem escopo, não prova criptográfica de identidade nem proteção completa contra SQL injection/comprometimento do runtime. Aprovar a fronteira de confiança e o bootstrap controlado do contexto é gate explícito; não vender RLS como isolamento absoluto entre aplicações hostis.

## 6. Políticas RLS candidatas e privilégios

Proposta: schema compartilhado com `organization_id`, FKs compostas e RLS obrigatória em todas as tabelas privadas. Runtime não é owner, superuser ou `BYPASSRLS`; owner de DDL é separado e não usado em tráfego. Considerar `FORCE ROW LEVEL SECURITY` para owner, sem alegar proteção contra superuser/BYPASSRLS. Grants mínimos restringem operações e colunas; runtime não altera schema, políticas ou catálogo de permissões. Proibir `TRUNCATE`, troca de owner, desativação de RLS e funções privilegiadas genéricas pelo runtime.

| Classe | Leitura candidata (`USING`, em descrição) | Escrita candidata (`WITH CHECK` e privilégios) |
|---|---|---|
| Recurso privado | Organização da linha igual ao contexto; ator, organização e vínculo elegíveis; permissão da ação atual | Mesmo predicado no novo estado; organização imutável; FK composta para dependências; autoria derivada do servidor |
| Organização | Somente organização selecionada com vínculo elegível | Mudanças por capacidade explícita; sem criação/bootstrap livre pelo cliente |
| Membership e concessões | Próprio vínculo no contexto; lista de membros só com capacidade específica | Sem DML direto genérico; operação controlada verifica alçada, revisão, não autoelevação e audita |
| Papéis locais | Escopo da organização e capacidade de leitura pertinente | Mudança de política controlada, sem criar grant global; nenhuma permissão implícita de owner |
| Identidade global | Próprio perfil por identidade Portal comprovada | Alterações cadastrais/segurança por contrato separado; sem listar PII de outros membros via tabela global |
| Catálogo global | Leitura de códigos técnicos ativos sem PII | Somente manutenção técnica separada e aprovada, sem DML pelo cliente |
| Auditoria | Organização e capacidade de auditoria específica | Inserção controlada; sem update/delete pelo runtime de cliente; retenção por papel operacional separado |

Negar contexto ausente/inválido, status não ativo, vínculo expirado, grant ausente e recurso cruzado em SELECT, INSERT, UPDATE e DELETE. SELECT vazio não basta: INSERT/UPDATE precisam checar linha nova e antiga para impedir movimentação de tenant. DELETE de domínio/histórico fica negado por padrão. Combinação de políticas permissivas pode ampliar acesso por OR: preferir barreira de tenant restritiva e revisar composição de todas as políticas por comando e role.

**Evitar recursão:** RLS de membership/papéis não pode consultar a si mesma ciclicamente para decidir elegibilidade. Recomenda-se encapsular a consulta canônica de autorização em avaliador privado mínimo, com owner dedicado sem login, acesso só a metadados necessários, caminho de nomes fixo, referências qualificadas, sem SQL dinâmico e sem exposição pública. Se for necessária função com privilégios elevados, sua capacidade, grants e ausência de oracle de terceiros exigem revisão independente; não está aprovada. Alternativa: projeção privada de autorização transacionalmente consistente, com custo de sincronização e testes adicionais. Falha de qualquer mecanismo nega.

Views, funções, triggers, partições, consultas administrativas, exports e caminhos indiretos precisam de inventário próprio: não podem contornar RLS. Validar comportamento invoker/owner conforme versão PG escolhida. Constraints não substituem RLS e RLS não substitui FK/permissão de aplicação. Seleção prévia de empresa e gerenciamento de vínculo suspenso requerem bootstrap mínimo que revele apenas referências permitidas do próprio ator, nunca acesso amplo para “descobrir” tenant; contrato ainda pendente.

Delegação/staff fica desabilitada por padrão. Uma proposta futura deverá exigir organização, recurso, ações permitidas, mandante, delegado, justificativa, início/fim, revogação e dupla autoria auditável; não herdar poderes do mandante nem usar impersonação silenciosa. Conta técnica não pode reaproveitar sessão humana ou identidade CRM.

## 7. Auditoria, privacidade e fronteiras externas

Eventos mínimos: concessão/aceite/revogação/suspensão, alteração de papel/política, tentativa negada relevante, troca de contexto e ação sensível. Campos: ID do evento e correlação, instante servidor, organização quando comprovada, ator local/origem, alvo opaco, ação, resultado/motivo codificado e revisões. Negativas não comprovadas não devem registrar dados fornecidos como se fossem fatos oficiais. Minimizar volume e proteger contra injeção em logs.

Não registrar senha, token, sessão, conteúdo de documento, lead, CPF/CNPJ, e-mail completo ou payload por padrão. Evitar IP/user-agent sem finalidade e retenção aprovada. Auditoria de alteração sensível deve ser durável na mesma transação; falha bloqueia essa alteração. Consultas de auditoria têm autorização específica, sem conceder todos os logs ao papel owner. Exportação, retenção, anonimização, descarte e proteção de backups exigem aprovação Segurança/LGPD; não aplicar automaticamente o prazo de 90 dias da integração de leads à auditoria de identidade.

RV-CT01/02 B aprovam diretrizes, não protocolos homologados. Não escolher aqui TTL, assinatura, endpoints, propagação de revogação ou ponto de linearização das APIs Sites/CRM. A revogação de membership Portal não apaga conta CRM, não redireciona backlog e não reabre janela de leads. P0-DOC03/05/06/12 e RV-CT03..08 continuam abertos. Nenhuma dependência de disponibilidade Portal é criada para recepção durável de leads.

## 8. Matriz de homologação futura — não executada

Todos os cenários abaixo estão **pendentes**, a executar somente no ambiente isolado autorizado com fixtures fictícias, nunca no banco real nesta tarefa.

| ID | Cenário/critério negativo | Evidência requerida | Responsável proposto |
|---|---|---|---|
| H01 | A autorizado em X acessa X; seleciona Y sem grant ou pede recurso de X sob Y → negar | Matriz ator × empresa × vínculo × recurso × ação, positivos e negativos | Backend + Segurança |
| H02 | Sem contexto, ID inválido, ator CRM com texto igual, ator/member/grants forjados → negar | Testes de proveniência além de igualdade de strings B-A | Identidade + Segurança |
| H03 | Convite, suspensão, revogação, usuário/empresa inativos e validade no limite final → negar | Relógio controlado e transições versionadas | Backend |
| H04 | FK entre organizações, duplicidade do par e troca de organization_id → rejeitar | Testes de constraints e concorrência; erros externos sem enumeração | Dados + QA |
| H05 | SQL sem filtro e INSERT/UPDATE/DELETE cruzados pelo runtime → negar | Testes sob role runtime real e contexto ausente; catálogo completo de tabelas privadas | Dados + Segurança |
| H06 | Owner/BYPASSRLS, view/função/partição ou política OR amplia escopo → bloquear desenho | Inspeção de grants, role memberships, owner e planos/policies | DBA + Segurança |
| H07 | Conexão X reutilizada por Y após sucesso/erro/rollback/timeout/retry → sem resíduo | Teste com pooler/driver escolhido e múltiplos workers | Plataforma + QA |
| H08 | Revogação concorre com uso, concessão e alteração de papel → ordem consistente | Execuções coordenadas nas duas ordens, locks/revisões e evidência de ausência de efeito pós-barreira | Dados + Segurança |
| H09 | Cache, réplica, sessão antiga, job e exportação longa tentam manter grant → negar/revalidar | Revisões obsoletas, indisponibilidade e cancelamento por lote | Backend + QA |
| H10 | Staff sem mandato, autoelevação e retirada do último administrador → negar | Testes de alçada, fluxo de recuperação especificado e trilha | Produto + Segurança |
| H11 | Logs revelam PII/segredo ou auditoria falha durante grant → impedir vazamento/alteração | Inspeção de eventos e injeção de falhas; retenção aprovada | Segurança + LGPD |
| H12 | Restauração reativa vínculo revogado ou código antigo ignora política → bloquear liberação | Ensaio isolado de restauração/reconciliação e rollback sem ampliar acesso | Plataforma + Dados |

Sucesso dos 21 testes/1.194 asserções por versão PHP da RV02 é evidência histórica de B-A, não execução de H01–H12. Não houve teste funcional PostgreSQL, RLS, pooling, autenticação ou concorrência nesta entrega.

## 9. Decisões A/B/C para aprovação

Responsáveis abaixo são funções propostas; o responsável do projeto deve nomear pessoas e registrar alternativa, versão/SHA, ressalvas e aceite. Todas as linhas permanecem **pendentes**.

| Decisão | A | B | C | Recomendação e aprovadores |
|---|---|---|---|---|
| BB01 — isolamento | Schema compartilhado, FK composta e RLS | Schema por organização | Banco por organização | **A**, menor custo inicial com gates rigorosos; B/C elevam operação e podem ser reavaliados por risco. Arquitetura + Dados + Segurança |
| BB02 — vínculo canônico | Uma linha por par e histórico de eventos | Várias linhas históricas com exclusão de sobreposição | Duplicatas e escolha da última | **A**, seleção determinística e unicidade simples; B exige mais constraints; C rejeitada. Dados + Produto |
| BB03 — concessões | Papéis locais versionados com catálogo fechado | Grants diretos por vínculo como única fonte | Misturar grants diretos e de papéis | **A**, sujeito à matriz de alçadas; B aproxima B-A porém dificulta administração; C aumenta precedência/complexidade. Produto + Segurança |
| BB04 — revogação | Barreira transacional comum, revisão e revalidação | Propagação eventual com TTL | Confiar na sessão até expirar | **A** para efeitos sensíveis; B só para usos expressamente tolerantes, C rejeitada. Dados + Segurança + Produto |
| BB05 — suporte de autorização RLS | Avaliador privado mínimo revisado | Projeção privada consistente | Runtime privilegiado ignora RLS | **A** candidata, provar ausência de recursão/oracle; B alternativa se custo justificar; C rejeitada. DBA + Segurança |
| BB06 — conexões | Transação explícita/contexto local e pool compatível | Sessão persistente com reset comprovado | Pool por statement/contexto herdado | **A**, menor risco de resíduo; B requer prova adicional; C incompatível. Plataforma + Dados |
| BB07 — identidade e delegação | Bootstrap local mínimo, staff negado até contrato | Delegação temporária auditada após especificação | Mesclar identidade CRM ou staff global | **A** nesta evolução; B futura sob aprovação própria; C rejeitada por D02. Identidade + Segurança |
| BB08 — privacidade/retorno | Retenção por finalidade e recuperação controlada | Prazo único de logs sem distinção | Apagar em cascata ou restaurar sem reconciliar revogações | **A**; prazo e responsáveis ainda por definir, B/C não recomendadas. LGPD + Plataforma + Produto |

Antes de converter a proposta em código, definir versão PG, UUID, permissão exata por papel/ação, política do último administrador, relógio/limite de transação, retenção e fluxo de reingresso; fechar contrato de identidade suficiente para fixtures e especificação de persistência, sem antecipar login B-C.

| Gate | Situação e saída necessária |
|---|---|
| D02-SEC01 | Aberto: autoridade de identidade, verificação, MFA/sessões/recuperação e abuso; implementação separada |
| D02-SEC02 | Aberto: deliberar BB01–BB07, convites, titularidade, alçadas e delegação |
| D02-SEC03 | Aberto: identidade técnica Portal×CRM, escopo/revogação sem SSO; não implementada nesta fase |
| D02-SEC04 | Aberto: transição cadastral; D03-R02 remove migração automática, não autoriza descarte |
| D02-SEC05/06 | Abertos: fronteiras Universidade/Performance e UX de acessos independentes |
| D03 físico/RLS | Aberto: aprovar modelo/constraints/roles, algoritmo de concorrência e plano H01–H12 |
| P0-DOC06 / G-04 | Abertos: revisão de segurança, isolamento, segredos, abuso, privacidade e evidências |
| P0 e contratos externos | Sem fechamento tácito; RV-CT01/02 são diretrizes e demais contratos continuam pendentes |
| Autorização B-B funcional | Ausente: requer ordem expressa separada com recorte, ambiente isolado e critérios; não deriva de B-A ou deste PR |

Gates documentais exigem estratégia e decisão suficientes antes do código; testes operacionais são planejados aqui para a etapa de desenvolvimento/homologação autorizada, sem exigir execução proibida nesta entrega. Aprovação documental, autorização de implementar, homologação e autorização de merge/deploy são decisões distintas.

## 10. Reversibilidade, rollout e validação desta entrega

Rollout futuro proposto: aprovar decisões por SHA → autorizar recorte mínimo em ambiente isolado → projetar migrations/roles separadamente → executar H01–H12 com dados fictícios → revisão independente → deliberar integração em dev. Dados reais, login, CRM e produção exigem marcos próprios. Nenhuma fase dessa sequência é iniciada por este texto.

Reversão documental é novo commit. Uma futura reversão de persistência deve bloquear escritas e preservar auditoria, nunca desativar RLS para recuperar serviço. Mudanças de schema deverão ser compatíveis em fases de expansão/contração; remoções destrutivas ficam adiadas. Backup/restauração precisam preservar ou reconciliar revogações e versões antes da reabertura; indisponibilidade segura é preferível a restaurar permissões antigas. AGI-11-RB01 permanece desabilitado.

Validação desta entrega: leitura da Issue #17 e ausência de comentários; conferência Git das refs e estados dos PRs #11/#15; leitura das fontes da seção 11; revisão do diff restrito a Markdown em `docs/portal/` e ausência de conteúdo SQL executável. Sem mudança de código, credencial, helper, workflow, branch pai, `main`, banco ou infraestrutura. Testes funcionais não executados por escopo documental.

## 11. Fontes canônicas consultadas

Todos os caminhos seguintes pertencem a [esta árvore congelada de planejamento](https://github.com/rafhael100/imagine-portal/tree/48c6e8a223f4ff96a2fc364fb09b7a9a2c31c759/docs). A aprovação posterior específica prevalece sobre avisos históricos no corpo da proposta anterior.

- [F1-01-B original](https://github.com/rafhael100/imagine-portal/blob/48c6e8a223f4ff96a2fc364fb09b7a9a2c31c759/docs/portal/PORTAL-F1-01-B-MODELO-ORGANIZACOES-MEMBERSHIPS-PERMISSOES-PROPOSTA-v0.1-2026-11-05.md).
- [DEC-01 B-A](https://github.com/rafhael100/imagine-portal/blob/48c6e8a223f4ff96a2fc364fb09b7a9a2c31c759/docs/portal/PORTAL-F1-01-B-DEC-01-APROVACAO-PROGRAMACAO-CONTROLADA-2026-11-05.md) e [DEC-02 integração em dev](https://github.com/rafhael100/imagine-portal/blob/48c6e8a223f4ff96a2fc364fb09b7a9a2c31c759/docs/portal/PORTAL-F1-01-B-A-DEC-02-MERGE-CONTROLADO-2026-11-06.md).
- [RV01 segurança](https://github.com/rafhael100/imagine-portal/blob/48c6e8a223f4ff96a2fc364fb09b7a9a2c31c759/docs/portal/PORTAL-F1-01-B-A-RV01-PARECER-TECNICO-SEGURANCA-PR14-2026-11-06.md) e [RV02](https://github.com/rafhael100/imagine-portal/blob/48c6e8a223f4ff96a2fc364fb09b7a9a2c31c759/docs/portal/PORTAL-F1-01-B-A-RV02-CORRECOES-PR15-2026-11-06.md).
- [D02 / SEC01–06](https://github.com/rafhael100/imagine-portal/blob/48c6e8a223f4ff96a2fc364fb09b7a9a2c31c759/docs/portal/PORTAL-TEC-D02-LOGINS-INDEPENDENTES-PORTAL-CRM-DECISAO-2026-11-05.md).
- [D03 proposta](https://github.com/rafhael100/imagine-portal/blob/48c6e8a223f4ff96a2fc364fb09b7a9a2c31c759/docs/portal/PORTAL-TEC-D03-MODELO-DADOS-LEGADO-MIGRACAO-PROPOSTA-v0.1-2026-11-05.md), [ratificação lógica](https://github.com/rafhael100/imagine-portal/blob/48c6e8a223f4ff96a2fc364fb09b7a9a2c31c759/docs/portal/PORTAL-TEC-D03-APROVACAO-D03-MIG-B-MODELO-LOGICO-2026-11-05.md) e [D03-R02](https://github.com/rafhael100/imagine-portal/blob/48c6e8a223f4ff96a2fc364fb09b7a9a2c31c759/docs/portal/PORTAL-TEC-D03-R02-LEGADO-PROTOTIPO-FLUXOS-NEGOCIO-DADOS-TESTE-2026-11-05.md).
- [F1-00 / gates](https://github.com/rafhael100/imagine-portal/blob/48c6e8a223f4ff96a2fc364fb09b7a9a2c31c759/docs/portal/PORTAL-F1-00-PREPARACAO-DESENVOLVIMENTO-CONTROLADO-v0.1-2026-11-05.md) e [P0-DOC01–14 / DOC06](https://github.com/rafhael100/imagine-portal/blob/48c6e8a223f4ff96a2fc364fb09b7a9a2c31c759/docs/imagine-sites/P0-FECHAMENTO-DOCUMENTAL-01.md).
- [RV-CT01 B](https://github.com/rafhael100/imagine-portal/blob/48c6e8a223f4ff96a2fc364fb09b7a9a2c31c759/docs/imagine-sites/P0-DOC03-RV-CT01-B-APROVADA-2026-11-04.md) e [RV-CT02 B](https://github.com/rafhael100/imagine-portal/blob/48c6e8a223f4ff96a2fc364fb09b7a9a2c31c759/docs/imagine-sites/P0-DOC03-RV-CT02-B-APROVADA-2026-11-04.md).

**Resultado para deliberação:** proposta física e de contratos preparada; decisões BB01–BB08 e gates permanecem pendentes. Não iniciar F1-01-B-B funcional sem aprovação específica.
