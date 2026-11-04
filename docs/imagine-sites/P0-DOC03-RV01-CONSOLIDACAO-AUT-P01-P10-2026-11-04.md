# P0-DOC03 / RV01 — Revisão de consistência das AUT-P01 a AUT-P10

**Natureza:** RELATÓRIO DE CONFERÊNCIA E PLANO DE SANEAMENTO — **não é novo contrato ratificado nem homologação técnica**.  
**Data da revisão:** 04/11/2026.  
**Repositórios conferidos:** `rafhael100/imagine-portal` (`main`), `rafhael100/imagine-crm` (`main`), fontes selecionadas; **não** revisão exaustiva de todos os commits, branches, propostas e itens AGI.  
**Autorizações:** o responsável aprovou avançar na revisão/consolidação após confirmar AUT-P10. **Não** autorizou nova opção arquitetural, programação, testes, compras, implantação, publicação, execução operacional ou homologação.

## 1. Resultado executivo verificável

**DEZ DECISÕES NORMATIVAS LOCALIZADAS:** AUT-P01 a AUT-P10, todas **alternativa B aprovada**, com arquivos próprios lidos no GitHub. **Isso não fecha P0-DOC03**: continuam sem contrato técnico homologado de autoridade, transições, eventos, reconciliação e segurança.

**P0-DOC03:** ABERTO. **RF-PEND-01:** ABERTA (corpus AGI incompleto). **RF-PEND-04:** NÃO HOMOLOGADA tecnicamente. **AGI-11-RB01:** rollback automático DESABILITADO.

**Importante sobre IN-127:** IN127-R01=B, **R02=A**, R03=B, R04=B, R05=B, R06=B. O ciclo R02 **72h+360h=432h** continua ratificado; não confundir as alternativas das decisões IN127 com as dez alternativas B das AUT.

## 2. Inventário das dez ratificações verificadas

| Decisão | Documento normativo | Blob SHA lido | Escopo aprovado |
|---|---|---|---|
| AUT-P01 | [B aprovada](P0-DOC03-AUT-P01-RATIFICACAO-ALTERNATIVA-B-2026-11-03.md) | `26e4ad197325f6fd08e629ce240511a3f11cf5bc` | Vínculo oficial Site×CRM; autorização versionada para captação principal/contingência |
| AUT-P02 | [B aprovada](AUT-P02-aprovacao-alternativa-B-2026-11-04.md) | `6a80eb1e5efb950a147331f771db6ad0f7f9d9af` | Captação distinta do contrato, cortesia, plano pago e disponibilidade técnica |
| AUT-P03 | [B aprovada](P0-DOC03-AUT-P03-RATIFICACAO-ALTERNATIVA-B-2026-11-04.md) | `3d29976614c60cadd14b27760a4cd6c908916491` | Troca de conta CRM por chamado e equipe Imagine; sem migração/redirecionamento automático |
| AUT-P04 | [B aprovada](AUT-P04-aprovacao-alternativa-B-2026-11-04.md) | `db9bfa7ff320e7a9bc5dc47514294792cda91d95` | Manifesto da versão/aceite expresso e identidade do artefato publicado |
| AUT-P05 | [B aprovada](AUT-P05-aprovacao-alternativa-B-2026-11-04.md) | `7c5ddbc47e7e3a9eb6aa415698c58492f64e25fb` | Entrega ao CRM só após prova oficial de persistência; idempotência |
| AUT-P06 | [B aprovada](AUT-P06-B-aprovada-2026-11-04.md) | `fadc04b5006e14abc3f5d9f60d3097f3c8ebc1e4` | Backlog preserva T0/CRM original; direito de entrega revalidado |
| AUT-P07 | [B aprovada](AUT-P07-B-aprovada-2026-11-04.md) | `0ba728f65bb6303bfa9118f3488332ac84ff4f55` | Descarte de dados temporários verificável por camada; backups e restauração protegidos |
| AUT-P08 | [B aprovada](AUT-P08-B-aprovada-2026-11-04.md) | `72566462c833d150f180c342a950a35c4c8c9d75` | Estados distintos de publicação, funcionamento, recuperação e entrega oficial |
| AUT-P09 | [B aprovada](AUT-P09-B-aprovada-2026-11-04.md) | `b80fa424b8c9db315b8b1bd03d734129b96833ca` | Autonomia por risco/ação; uma aprovação humana para operações críticas |
| AUT-P10 | [B aprovada](AUT-P10-B-aprovada-2026-11-04.md) | `904cf8d1a1758f569e30e21dd44ddcf6e3b7bb23` | Pagamento oficial conciliado; contratos Site/CRM e cortesia independentes |

**Regra de precedência:** decisões posteriores e registros de ratificação têm precedência no objeto expressamente alterado; propostas antigas permanecem históricas. Uma menção histórica a "pendente" em documento anterior NÃO revoga uma aprovação posterior. Cada aprovação é normativa/documental, não autorização técnica nem prova de execução.

## 3. Autoridades lógicas consolidadas — sem impor mecanismo de implementação

| Estado/fato de negócio | Fonte de verdade lógica indicada pelas ratificações | Não pode afirmar esse fato |
|---|---|---|
| Contrato/aceite comercial do site | Portal/contrato vigente, com decisão válida do cliente | n8n, conclusão da IA, webhook sem comprovação |
| Versão do site aceita | Cliente autenticado/autorizado no Portal e manifesto preciso | Leitura da URL, silêncio, agente de IA |
| Publicação efetiva | Publicador/validador técnico e evidência da versão observada no destino | Deploy solicitado, HTTP 2xx intermediário |
| Entrega oficial do projeto | Portal, após checklist COM-13 completo e evento idempotente | Simples publicação, aceite isolado, conclusão de workflow |
| Operação/recuperação | Monitoramento e verificação técnica demonstrada; incidentes preservados | Reboot/restore concluído sem verificação |
| Vínculo Site×CRM e autorização de formulários | Registro oficial versionado; checagem pelo backend nas duas APIs | Frontend, cache, URL ou parâmetro do visitante |
| Recepção durável T0 | API captadora oficial, principal ou contingência, ao persistir de forma confirmada | Clique do usuário ou entrada em n8n |
| Entrega/persistência de lead | CRM de destino autorizado, por resultado/consulta oficial | Fila, HTTP 202 ou aceitação intermediária |
| Backlog anterior a troca/revogação | Autoridade específica de entrega ao **CRM original** para cada operação | Autorização antiga congelada ou migração automática para CRM novo |
| Eliminação de payload operacional | Serviço de integração + prova por cada superfície aplicável; backups à parte | Limpeza da fila principal isoladamente |
| Autonomia e mudanças críticas | Portão autorizador independente do LLM + Central de Aprovações com humano habilitado quando necessário | IA, conteúdo de ticket, reclassificação unilateral de risco |
| Liquidação/estorno | Banco/PSP/adquirente + conciliação financeira oficial por contrato/título | Webhook isolado, comprovante não validado, n8n |
| Benefício cortesia CRM Básico | Elegibilidade dos contratos/site no Portal, vinculada e validada para a conta CRM exata | Existência de qualquer site na mesma empresa sem reavaliar vínculos |
| Assinatura CRM paga | CRM, contrato e razão financeira próprios | Situação de um site contratado separadamente |

**Nenhum nome de microsserviço, schema, endpoint, banco, biblioteca, PSP ou fluxo técnico específico é homologado por esta matriz.**

## 4. Divergências e lacunas observadas — com recomendação de saneamento, NÃO nova aprovação

| Código | Classe | Evidência/documentos confrontados | Saneamento documental proposto | Prioridade |
|---|---|---|---|---|
| D03-01 | Status histórico desatualizado | Índice mestre e matriz de autoridades ainda contêm linhas anteriores descrevendo AUT-P02..P10 como pendentes; linhas posteriores já registram ratificações | Construir quadro **ATUAL VIGENTE** no topo de ambos e rotular a cronologia abaixo como histórica; não apagar documentos prévios | P1 editorial/rastreabilidade |
| D03-02 | Política comercial supersedida | COM-02/03/D04 incluem 20% Pix, 10% Pix Automático e 4x; política posterior de 03/11 é 5%, 5% e cartão até 3x | Indexar precedência expressa, sem reativar percentuais anteriores; preços finais e cumulatividade ainda pendentes | P0 se afetar contratos |
| D03-03 | Histórico de Pix parcelado | PORTAL-D02 seção 7 considera Pix parcelado, mas seção 8 posterior revoga; política mais recente não oferece Pix parcelado Imagine | Tornar explícita a aplicação da seção posterior para checkout e orçamento, preservando a proposta antiga como registro | P0 se afetar meios de pagamento |
| D03-04 | Política de privacidade anterior | COM-15 seções 8–13 descrevem aceite individual; **seção 14 posterior** dispensa clique específico para política padrão de site simples elegível, com revisão/modelo homologado | Referenciar seção 14 como regra vigente; manter **aceite da versão do site** separado, bem como requisitos de consentimento quando aplicáveis | P0 jurídico/entrega |
| D03-05 | Semântica temporal IN-127 dispersa | IN-127/128/147/148/149 históricos trazem 3+15 dias e 90 dias sem todos os marcos ratificados | Vincular **T0 primeira persistência durável**, **72+360=432h contínuas**, descarte operacional, auditoria **até 90×24h do evento terminal**, sem reenvio pós-limite | P0 dados/segurança |
| D03-06 | API e cache | AUT-P01/02/03 + IN127-R06 permitem site estático sem CRM, mas proíbem formulário sem destino autorizado, inclusive página antiga/cache | Contrato único de autorização por operação nas **duas APIs**, com revisão e barreira de revogação | P0 |
| D03-07 | Confirmação e backlog | AUT-P03/05/06 e IN127-R05 exigem originalidade do destino, confirmação CRM e consulta oficial antes de reenvio | Contrato de operação idempotente, estados incertos, autorização do backlog e recuperação concorrente | P0 |
| D03-08 | Backups x descarte | IN127-R03/R04 + AUT-P07 distinguem ativo/temporário de backups protegidos; restauração não pode reintroduzir expirado | Inventário por classe, prova de descarte, política legítima de backup, barreira de restauração, sem prazo adicional de fila | P0 |
| D03-09 | Publicação ≠ entrega | PORTAL-COM-13, D01, REL-01 e AUT-P04/08: aceite, publicação verificada e ENTREGUE são eventos distintos | Transições e provas por revisão; evento de entrega único para Dia 0 da REL-01; falha posterior preserva histórico | P0 |
| D03-10 | Alçada duplicada | IN-137/138 recusam dupla aprovação; IN-155 permite reusar playbook seguro; AUT-P09 mantém uma autorização humana por execução crítica | Exigir portão determinístico externo ao agente e uma só aprovação por intervenção crítica quando necessária | P0 segurança |
| D03-11 | Cortesia: suspensão ≠ cancelamento | COM-08/09 e IN-119–122 distinguem suspensão por 7 dias, cancelamento comum com 30 dias operacional, cancelamento após inadimplência com 30 dias restrito e CRM pago independente | Contrato de transições separado por motivo, conta CRM e fonte de elegibilidade; não suspender plano pago | P0 |
| D03-12 | Pagamento pendente ≠ quitação | AUT-P10, COM-07/08, D04: tentativa bancária, webhook e mandato Pix não são pagamento conciliado; dia não útil tem regularização até próximo útil | Evento financeiro autoritativo + razão conciliada + calendário contratual + idempotência + contestação/estorno | P0 |
| D03-13 | AGI canônico incompleto | RF-PEND-01 manifesta que parte de AGI-RF01-R01..R07 / AGI-P09..P18 não teve texto literal integral localizado/confrontado | Continuar busca e confronto, registrar lacunas; **não inventar texto ou considerar RF-PEND-01 encerrada** | P0-DOC01/02 |
| D03-14 | Homologação/arquitetura | P0-FECHAMENTO-DOCUMENTAL-01 aponta P0-DOC03 a DOC14 abertos/parciais | Exigir critérios documentais e alçadas, sem confundir projeto de testes com testes realizados ou implantação | Gate de prontidão |

**Classificação:** os D03 acima não representam quatorze novas deliberações aprovadas. São achados de revisão, alguns históricos sem conflito normativo atual, outros requisitos de contrato pendentes. O tipo de prioridade depende de impacto no lançamento; não interpretar P1 editorial como licença para ignorar um bloqueio de segurança.

## 5. Contratos e evidências a preparar — propostas de trabalho, NÃO homologadas

Usar códigos **RV-CT** somente para agrupar o trabalho desta revisão. Não substituem nem renumeram contratos `CT-03/CT-04` da arquitetura INT.

| Pacote candidato | AUT e dependências | Deve especificar antes de fechar P0-DOC03 |
|---|---|---|
| RV-CT01 — Identidade/autorização | P01/P02/P03/P06, IN-124, IN127-R06 | Titularidade de vínculo, tenant/site/CRM, estados, escopo, revisão, TTL/frescor, consulta das duas APIs, revogação, bloqueio seguro, cutover e backlog antigo |
| RV-CT02 — Recepção e entrega CRM | P05/P06, IN127-R01/R02/R05, IN-148/149 | T0 durável, ID estável, resultado CRM final, estados inconclusivos, idempotência contextual, concorrência, retry e proibição pós-432h |
| RV-CT03 — Descarte e restauração | P07, IN127-R03/R04, IN-128/147 | Inventário de cópias, provas por superfície, backup protegido, expiração, ordem de restauração/antiressurreição, auditoria 90×24h |
| RV-CT04 — Identidade de versão e entrega | P04/P08, D01, COM-13/15, REL-01 | Manifesto, aceite do cliente, publicação verificada, estados de disponibilidade, checklist por produto, prova ENTREGUE, idempotência e incidentes posteriores |
| RV-CT05 — Permissões e agentes | P09, IN-131/137/138/155/166 | Ação tipada, agente/versão/ferramenta, ambiente/tenant, risco, playbook homologado, uma aprovação humana quando necessária, negação por padrão, suspensão |
| RV-CT06 — Financeiro/benefícios | P02/P10, COM-06/07/08/09, IN-119/122, D02/D04 | PSP/contrato/título/ciclo, fatos financeiros oficiais, cobrança/estorno, períodos, tolerância 7 dias, férias/feriados, cortesia por múltiplos sites, CRM pago independente |
| RV-CT07 — Incidentes e recuperação | P07/P08/P09, IN-143/156, IN-101 | Autoridade de incidentes, estados de recuperação verificados, limites, controle de mudanças, backups separados, procedimento sem ativar AGI-11-RB01 |
| RV-CT08 — Segurança e observabilidade transversal | P01–P10, P0-DOC06/12 | RBAC/MFA, autenticação entre serviços, isolamento multiempresa, assinatura/proveniência de eventos, logs minimizados, proteção de segredos, falhas/alertas e evidências |

**Mínimos transversais para cada futuro contrato:** proprietário do estado; comando vs evento vs resultado oficial; pré/pós-condições; escopo tenant/ambiente; identidade e autorização; revisão do objeto; identificador de correlação/idempotência; resposta tardia/timeout; concorrência; compensação permitida; trilha de auditoria; classificação de falha; retenção; impactos em outros produtos; critérios de revisão/teste futuros; fonte canônica e aceite do responsável.

## 6. Invariantes de segurança e produto a manter

1. Não receber formulário quando o backend não comprovar destino CRM vigente e autorizado, inclusive se a página foi publicada antes de revogação.
2. Site estático sem CRM pode ser elegível para publicação/entrega no escopo contratado; formulário permanece bloqueado.
3. Submissão recebida conserva `T0`, destino original e identidade mesmo durante failover/retry; troca de CRM não migra backlog.
4. Nenhuma nova entrega após `T0+432h`, sem reiniciar relógio por nova tentativa, queda ou processamento.
5. HTTP 202, mensagem n8n, webhook e resposta de publicador não substituem confirmações de seus sistemas titulares.
6. Eliminar payload de integração ativo/temporário sem apagar registro CRM confirmado; não reintroduzir expirado por backup.
7. Entrega oficial ocorre depois de aceite aplicável, prova de publicação e checklist COM-13; Dia 0 REL-01 não duplica.
8. Política padrão COM-15 seção 14 sem aceite individual adicional para sites simples elegíveis; exceções jurídicas e consentimentos próprios mantidos.
9. Não suspender CRM pago por site inadimplente, nem cobrar CRM automaticamente ao terminar cortesia.
10. Uma única aprovação humana específica para intervenção crítica quando necessária; sem autoelevação dos agentes.
11. Sem transferência de responsabilidade de DNS para Imagine por padrão; Cloudflare DNS é preferência opcional do cliente.
12. GA4 apenas integração voluntária, sem importação/apresentação das métricas na Imagine segundo escopo vigente.
13. O rollback automático AGI-11-RB01 permanece DESABILITADO.
14. Não converter documentação, proposta ou aprovação normativa em autorização de execução.

## 7. Dependências e critérios de encerramento

| Gate | Estado após esta revisão | Evidência necessária para considerar encerrado |
|---|---|---|
| **AUT-P01 a AUT-P10** | **ENCERRADAS na esfera normativa**, todas B; fontes acima verificadas | Não exigir reaprovação das mesmas alternativas; somente nova decisão se houver conflito novo real |
| **P0-DOC03** | **ABERTO — revisão RV01 preparada, contratos técnicos faltantes** | Fontes e precedências consistentes, autoridades por evento definidas, contratos dos RV-CT relevantes revisados, cross-check com CRM/Portal, aceite expresso da consolidação |
| **RF-PEND-01 / P0-DOC01** | **ABERTO** | Corpus, branches/commits/redaçōes originais e decisões do escopo conferidos, divergências saneadas, parecer aprovado |
| **P0-DOC02** | **ABERTO** | Textos AGI exigidos para primeira entrega confrontados; lacunas não inventadas |
| **RF-PEND-04 / P0-DOC05** | **NÃO HOMOLOGADA** | Contratos técnicos, plano de testes e homologação em fase apropriada; não executar testes nesta etapa |
| **P0-DOC04/06/08/10/12** | **ABERTOS/PARCIAIS** | Contratos operacionais, segurança, publicação, backups e financeiro alinhados ao que foi aprovado |
| **P0-DOC13** | **A DEFINIR** | Recorte exato da primeira entrega e critérios documentais |
| **P0-DOC14** | **NÃO INICIADO** | Pacote global versionado, congelado, aceito e autorização de programação separada |

**Método recomendado para a sequência:** transformar RV-CT01 e RV-CT02 em propostas técnicas verificáveis primeiro, pela criticidade do direito de captar e entregar leads; em seguida RV-CT03..RV-CT08. Cada decisão nova será submetida individualmente, sem ratificação implícita por este relatório. Reconciliar pendências cruzadas sem reabrir as dez AUT já aprovadas.

**Não executar:** código, testes, automações, alteração de DNS/Cloudflare, publicações reais, mudanças de CRM, cobrança, contratação de PSP/VPS, rollback, deleção operacional ou alteração de credenciais. Este relatório é documento de trabalho, não parecer final de fechamento P0-DOC03.

## 8. Outras fontes efetivamente consultadas neste ciclo

- [Índice mestre](INDEX-MESTRE-PLANEJAMENTO-2026-11-03.md), blob lido antes da atualização deste relatório: `53f76dc7755c3c987bc21ad1d6924970ebeff3cb`.
- [Matriz de autoridades P0-DOC03](P0-DOC03-AUTORIDADES-PORTAL-SITES-CRM-MATRIZ-2026-11-03.md), blob lido antes da atualização deste relatório: `8f05944c93e17ab59580332fa53c4aad2066d08e`.
- [P0-FECHAMENTO-DOCUMENTAL-01](P0-FECHAMENTO-DOCUMENTAL-01.md) `7e80c0cf16bf55adad267b413cf3db28991a4ebe`.
- [RF-PEND-01 matriz parcial](RF-PEND-01-matriz-conferencia-parcial-2026-11-03.md) `b7d42bc39308145c389f41103462416b4815ea21`.
- [RF-PEND-01 manifesto AGI](RF-PEND-01-MANIFESTO-EVIDENCIAS-AGI-2026-11-03.md) `e561079305fef392d6c81ff78d944a4757fe3079`.
- [IN127-R01–R06](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-127-R01-R06-ratificacoes-2026-11-03.md), blob `2549386f94844f19f08addaccd282e1fdcec83c5`.
- [PORTAL-COM-15](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-COM-15-central-documentos-legais-consentimentos.md), seção 14.
- [PORTAL-COM-POLITICA-VIGENTE-PIX-05](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-COM-POLITICA-VIGENTE-PIX-05-CARTAO-3X-2026-11-03.md).
- [PORTAL-D02](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-D02-pagamento-servicos-adicionais.md), seção 8 posterior.
- [PORTAL-COM-08](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-COM-08-inadimplencia-site-cortesia-crm-configuravel.md), [COM-09](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-COM-09-transicao-cortesia-cancelamento-inadimplencia.md), [IN-119](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-119-gestao-cortesia-crm-basico.md), [IN-120](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-120-encerramento-cortesia-continuidade-conta.md), [IN-122](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-122-multiplos-sites-contas-crm-cortesias.md).
- [IN-128](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-128-limpeza-segura-auditoria-filas-leads.md), [IN-147](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-147-exclusao-leads-nao-entregues-apos-18-dias.md), [IN-148](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-148-reconciliacao-inteligente-leads.md), [IN-149](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-149-protocolo-entrega-confirmacao-leads.md).
- [IN-131](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-131-niveis-autonomia-agentes-tecnicos.md), [IN-137](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-137-central-aprovacoes-tecnicas.md), [IN-138](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-138-matriz-riscos-aprovacoes-agentes.md), [IN-155](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-155-homologacao-inteligente-procedimentos-agentes.md), [IN-166](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-166-gestao-inteligente-ferramentas-permissoes-agentes.md).

**Veredicto RV01:** **as dez AUT estão ratificadas; P0-DOC03 ainda não está pronto para encerramento**. O avanço correto é preparar e decidir os contratos técnicos faltantes, mantendo rastreabilidade e sem começar implementação.
