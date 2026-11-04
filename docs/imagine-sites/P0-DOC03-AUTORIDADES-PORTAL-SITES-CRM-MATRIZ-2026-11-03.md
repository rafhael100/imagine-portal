# P0-DOC03 — Matriz de autoridades oficiais entre Portal, Sites, Captação, CRM e agentes

**Estado:** LEVANTAMENTO DOCUMENTAL PARA RECONCILIAÇÃO, **NÃO APROVAÇÃO DE NOVOS CONTRATOS**. P0-DOC03 permanece ABERTO.  
**Escopo:** primeira entrega Imagine Sites, Portal Imagine, CRM Imagine, API de captação, filas/n8n e agentes.  
**Fontes:** documentação em `rafhael100/imagine-crm/docs/planejamento/`, confrontada com ratificações IN127-R01–R06.  
**Proibição:** nenhuma programação, testes, infraestrutura, homologação ou habilitação de rollback AGI-11-RB01 (DESABILITADO).

## 1. Autoridades previstas/identificadas nas fontes

| Fato de negócio ou técnico | Fonte a ser tratada como autoridade conforme documentos | Emissores/consumidores sem autoridade própria | Evidência de origem | Grau de fechamento |
|---|---|---|---|---|
| Contratação, oferta e aceites comerciais de site | **Portal/contrato de site** segundo módulos comerciais | CRM/n8n/IA não podem afirmar pagamento ou contratação pelo simples workflow | `PORTAL-D01`, `PORTAL-01`, `PORTAL-D04` | Princípio definido; contrato/estados finais pendentes |
| Escolha de CRM ao adquirir novo site | **Escolha autorizada do cliente registrada no Portal**, com confirmação da integração efetiva | Agente/n8n não criam associação por inferência | `IN-122`, `IN-123` | Funcional aprovado; assinatura técnica pendente |
| Alteração posterior de CRM vinculado | **Chamado formal e ação autorizada da equipe Imagine** | Portal não fornece troca instantânea pelo cliente; IA não altera autonomamente | `IN-122`, `IN-123`, `IN-124` | Regra aprovada; transações e evidências pendentes |
| Elegibilidade comercial à cortesia CRM Básico | **Contrato/site elegível + política oficial de benefício**, aplicada à conta CRM | Número de sites não multiplica cortesia em uma mesma conta | `IN-122`, `IN-119`, `IN-123` | Regra aprovada; contrato de sincronização pendente |
| Destino para formulários públicos | **Vínculo e autorização oficiais vigentes**, verificadas pelo backend antes de cada recepção | Página/cache/parametro enviado pelo visitante não autorizam conta de destino | `IN-124`, `IN-149`, `IN127-R06` | Política ratificada; autoridade consultiva/interface pendente |
| Recepção durável (T0) | **Backend oficial de Captação**, principal ou contingência, com persistência confirmada | Clique/frontend/n8n não são fonte de T0 | `IN-124`, `IN-149`, `IN127-R01` | Política ratificada; contrato e concorrência pendentes |
| Prazo e expiração de lead pendente | **Evento T0 imutável + política 72h+360h**, calculada de modo consistente | Retentativas e filas não podem reiniciar prazo | `IN-127`, `IN-147`, `IN127-R02/R03` | Política ratificada; relógios/execução pendentes |
| Entrega efetiva de lead | **CRM Imagine** deve comprovar gravação/criação/associação do registro autorizado | HTTP 2xx intermediário, fila e n8n não declaram sucesso comercial | `IN-149`, `IN-148`, `IN127-R05` | Política ratificada; endpoint oficial pendente |
| Estado inconclusivo, reconciliação e correção | **Resultado oficial consultado ao CRM + auditoria da integração** | Agente apenas coordena/diagnostica dentro de alçadas | `IN-148`, `IN-149`, `IN127-R05` | Política ratificada; schema e garantias pendentes |
| Aceite de versão do site | **Decisão expressa de cliente autenticado/autorizado no Portal**, vinculada ao artefato exato | IA e silêncio não aprovam versão | `PORTAL-D01`, `PORTAL-COM-13` | Funcional aprovado; assinatura de versão/evidências pendentes |
| Publicação e entrega formal de site | **Sistema competente de publicação e estado oficial de entrega**, sob gate determinístico; evento final no Portal | Sucesso do agente ou deploy isolado não constitui entrega formal | `PORTAL-COM-13`, `PORTAL-D01` | Fluxo funcional aprovado; fronteiras e estados oficiais pendentes |
| Mudanças técnicas críticas | **Uma aprovação humana específica**, conferida no sistema central de aprovações | IA não se autoaprova; aprovação não vale para outros recursos | `IN-137`, `IN-138` | Regra aprovada; alçadas por operação pendentes |
| Pagamentos, estornos e liquidações | **Módulo financeiro / fonte financeira oficialmente reconciliada** | Workflow, IA e INT não certificam pagamento por retorno intermediário | `integracoes/INT-P0-autoridades-contratos.md`, `PORTAL-D04` | Autoridade por classe definida; contratos exatos pendentes |
| Logs e evidências de descarte | **Serviço de captação/entrega e seu registro minimizado de auditoria**, separado do armazenamento CRM | Não guardar payload inteiro como prova de descarte | `IN-128`, `IN-147`, `IN127-R03/R04` | Política ratificada; execução/backups pendentes |

**Nota de precisão:** os nomes em negrito são **atribuições lógicas identificadas nas decisões**, não um sistema de registro definitivo homologado, schema concreto, endpoint escolhido ou descrição de infraestrutura implantada.

## 2. Distinções obrigatórias de estados

1. **Pedido criado ≠ contrato aceito ≠ pagamento recebido.**
2. **Prévia gerada ≠ aceite do cliente ≠ publicação ≠ entrega oficial.**
3. **Formulário recebido duravelmente ≠ lead entregue ao CRM.**
4. **Resposta HTTP de aceitação ≠ confirmação de armazenamento no CRM.**
5. **Chamado aberto ≠ mudança de vínculo executada.**
6. **Integração CRM temporariamente indisponível ≠ ausência/revogação do destino.**
7. **Aprovação humana de intervenção crítica ≠ autorização administrativa ampla e permanente.**
8. **Comando de agente/n8n ≠ confirmação oficial pelo sistema titular.**

**AUT-P01 — RATIFICADA (B):** [registro oficial da decisão](P0-DOC03-AUT-P01-RATIFICACAO-ALTERNATIVA-B-2026-11-03.md) com vínculo versionado, validação autenticada por ambas APIs, proteção contra estados antigos, barreira de revogação e fail-closed; contratos detalhados ainda pendentes. [AUT-P02 — proposta PENDENTE](P0-DOC03-AUT-P02-MATRIZ-ELEGIBILIDADE-CAPTACAO-CORTESIA-SUSPENSAO-PROPOSTA.md) foi apresentada separadamente, sem ratificação.

**ATUALIZAÇÃO 04/11/2026:** [AUT-P02 — alternativa B aprovada](AUT-P02-aprovacao-alternativa-B-2026-11-04.md) — separar direito de captação, cortesia Básico, assinatura CRM paga, situação contratual do site e conectividade, com autorização específica versionada. A linha histórica AUT-P02 abaixo é anterior a essa aprovação. **AUT-P03 a AUT-P10 permanecem pendentes**, assim como contratos técnicos e P0-DOC03.

**AUT-P03 — minuta para deliberação:** [troca controlada do vínculo Site × CRM](P0-DOC03-AUT-P03-TROCA-CONTROLADA-VINCULO-CRM-PROPOSTA.md), com validação por chamado, barreira nas duas APIs, revisão e verificação final. A minuta não configura ratificação. AUT-P01 B e AUT-P02 B preservadas; AUT-P04 a AUT-P10 pendentes. 

**AUT-P03 — RATIFICADA B (04/11/2026):** [registro normativo da troca controlada Site × CRM](P0-DOC03-AUT-P03-RATIFICACAO-ALTERNATIVA-B-2026-11-04.md). A aprovação exige chamado e equipe Imagine, validação de titularidade e direitos, barreira segura das duas APIs, nova revisão versionada, verificação do destino e nenhuma migração/redirecionamento automático. **AUT-P04 a AUT-P10 permanecem PENDENTES**; P0-DOC03 segue aberto. As linhas e observações históricas abaixo refletem a data anterior da matriz.

**AUT-P04 — proposta histórica (anterior à aprovação B):** [alternativas e matriz de prova de versão/aceite/publicação](P0-DOC03-AUT-P04-IDENTIDADE-VERSAO-ACEITE-ARTEFATO-PROPOSTA.md). A alternativa B foi **aprovada posteriormente**; isso ainda não determina mecanismo jurídico ou tecnológico nem fecha P0-DOC03. AUT-P01/02/03 B aprovadas; AUT-P05 a AUT-P10 pendentes.

**AUT-P04 — APROVADA alternativa B (04/11/2026):** [registro de aprovação](AUT-P04-aprovacao-alternativa-B-2026-11-04.md). Versão do site imutável e identificável, aceite expresso vinculado e verificação contra artefato publicado/entregue. **AUT-P05 a AUT-P10 permanecem pendentes**; a aprovação funcional não fecha contratos técnicos nem P0-DOC03. Menções anteriores a AUT-P04 pendente são históricas.

**AUT-P05 — proposta histórica (anterior à aprovação B):** [contrato lógico de confirmação CRM e reconciliação](P0-DOC03-AUT-P05-CONFIRMACAO-OFICIAL-LEADS-CRM-RECONCILIACAO-PROPOSTA.md); a alternativa B foi aprovada posteriormente, mas contratos de API e critérios de homologação seguem pendentes. AUT-P01 a AUT-P04 B aprovadas; AUT-P06 a AUT-P10 ainda pendentes.

**AUT-P05 — APROVADA alternativa B (04/11/2026):** [decisão normativa](AUT-P05-aprovacao-alternativa-B-2026-11-04.md). Somente o CRM confirma persistência efetiva; respostas intermediárias exigem consulta ao estado oficial; novas tentativas somente quando idempotentes e anteriores a T0+432h. **AUT-P06 a AUT-P10 permanecem pendentes**; contratos técnicos não homologados e P0-DOC03 aberto.

AUT-P06 foi aprovada pela alternativa B em 04/11/2026: [registro](AUT-P06-B-aprovada-2026-11-04.md). AUT-P07 a AUT-P10 pendentes.

**AUT-P07 — PROPOSTA PENDENTE:** [matriz de evidências de descarte das APIs/filas/réplicas e tratamento distinto de backups](P0-DOC03-AUT-P07-COMPROVACAO-DESCARTE-APIS-FILAS-BACKUPS-PROPOSTA.md); alternativa B recomendada, não ratificada. AUT-P08–P10 pendentes. P0-DOC03 não encerrado.

**AUT-P07 — B APROVADA (04/11/2026):** [registro normativo](AUT-P07-B-aprovada-2026-11-04.md). Prova de descarte por superfície ativa/temporária, backups protegidos com retenção própria, barreira contra restauração indevida e auditoria minimizada. **AUT-P08 a AUT-P10 pendentes**; P0-DOC03 e homologação técnica abertos.

## 3. Pendências P0 concretas (não resolvidas por esta matriz)

| ID | Pergunta de fechamento técnico/documental | Evidência futura necessária |
|---|---|---|
| AUT-P01 | **RATIFICADA alternativa B**: registro oficial versionado e autorização segura para ambas APIs | **Pendente detalhamento** de protocolos, frescor, sincronização, barreira de revogação e contratos (não é homologação) |
| AUT-P02 | **PROPOSTA PENDENTE**: como distinguir autorização de captação, cortesia e estado contratual/suspensão? | [Alternativas para deliberação](P0-DOC03-AUT-P02-MATRIZ-ELEGIBILIDADE-CAPTACAO-CORTESIA-SUSPENSAO-PROPOSTA.md), nenhuma ratificada |
| AUT-P03 | Quais são os comandos, eventos e confirmações oficiais de alteração de CRM vinculado? | Transações, idempotência, autorização humana, auditoria e falhas parciais |
| AUT-P04 | Quem assina/atesta identidade e integridade da versão do site, do aceite e do artefato implantado? | Contrato de evidência, verificação do gate e revogação/correção |
| AUT-P05 | Qual API do CRM é autoridade para confirmar persistência e reconciliar resultados inconclusivos sem criar duplicidade? | Semântica de comando, resultado e endpoint consultivo, idempotência por tenant |
| AUT-P06 | Como os registros recebidos antes de suspensão/revogação serão tratados sem migrar ou revelar dados à nova conta? | Política de transição, bloqueio de novos dados e reconciliação sem ultrapassar IN-127 |
| AUT-P07 | Como é comprovado o descarte nas estruturas principal/contingência e backups sem autorizar retenção informal? | Contrato de descarte, verificações e evidências mínimas |
| AUT-P08 | Que estados de publicação, falha, recuperação e entrega serão oficiais do Portal/Sites? | Contrato de estados, verificador técnico e provas do evento de entrega |
| AUT-P09 | Quais ações de agentes podem operar autonomamente e quais requerem a aprovação humana única? | Matriz de risco por operação/ambiente e critérios de homologação |
| AUT-P10 | Qual autoridade de pagamento/assinatura fundamentará benefícios de CRM e liberação de produção sem estado financeiro presumido? | Contrato de eventos financeiros, reconciliação e regras de tolerância |

As perguntas são **itens de especificação pendentes**, não aprovação de soluções para elas.

## 4. Fontes lidas e seus blob SHAs

| Fonte CRM `main` | Blob SHA verificado |
|---|---|
| [IN-122](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-122-multiplos-sites-contas-crm-cortesias.md) | `0da1107aa505b4983a992b9acfaf96524685cdeb` |
| [IN-123](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-123-painel-integracoes-sites-crm.md) | `17e5597845a59776676f782609ef2f389244a4bf` |
| [IN-124](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-124-integracao-inteligente-formularios-leads.md) | `e66f3d065f25efac373c86d23e5c410712b12fd8` |
| [IN-149](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-149-protocolo-entrega-confirmacao-leads.md) | `f1161ac2c1140d70bc9ad74e8494afd0bd969761` |
| [PORTAL-D01](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-D01-aceite-eletronico-versoes-orcamentos.md) | `f4259757e95592c75e6806098c7085deb5d06be6` |
| [PORTAL-COM-13](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-COM-13-entrega-automatica-checklist-qualidade-agente-ia.md) | `cc4f3a0a108f4818811de460c0756c2683e203d7` |
| [IN-137](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-137-central-aprovacoes-tecnicas.md) | `124d3d9c7d3fc6c6a6e0883e4ac23b294fe85ffd` |
| [INT-P0 — autoridades](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/integracoes/INT-P0-autoridades-contratos.md) | `7f7ca2d30c49cfee24672ddc90f658b54b4e4880` |

Também consideradas as ratificações [IN127-R01–R06](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-127-R01-R06-ratificacoes-2026-11-03.md).

## 5. Status e encaminhamento

- **Conferência de autoridades:** parcial, baseada em documentos localizados.
- **Aprovação individual:** **AUT-P01 alternativa B RATIFICADA**; **AUT-P02 proposta pendente**; **AUT-P03 a AUT-P10 pendentes**. P0-DOC03 continua aberto.
- **P0-DOC03:** **ABERTO**.
- **RF-PEND-01:** **ABERTA**, especialmente séries AGI sem texto canônico.
- **RF-PEND-04:** normativa IN-127 ratificada, homologação técnica pendente.
- **Desenvolvimento:** **NÃO autorizado**.
- **AGI-11-RB01:** rollback automático **DESABILITADO**.

A matriz não substitui aceites futuros de contratos, não cria APIs nem autoriza qualquer operação real.
