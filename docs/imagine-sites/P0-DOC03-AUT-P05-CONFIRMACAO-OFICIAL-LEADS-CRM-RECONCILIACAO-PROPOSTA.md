# P0-DOC03 / AUT-P05 — Confirmação oficial da entrega ao CRM e reconciliação segura

**Status:** PROPOSTA PARA DELIBERAÇÃO — **NÃO RATIFICADA**.  
**Data de preparação:** 04/11/2026.  
**Escopo:** integração Imagine Sites / APIs principal e contingência / filas e agentes / CRM Imagine.  
**Precedências ratificadas:** IN127-R01 a R06; AUT-P01, AUT-P02, AUT-P03 e AUT-P04, todas alternativa B; IN-124, IN-148, IN-149, IN-128 e limites de IN-147 quando compatíveis com ratificações posteriores.  
**Fronteira:** AUT-P06 a AUT-P10 permanecem PENDENTES; P0-DOC03 e RF-PEND-01 ABERTOS; RF-PEND-04 ainda não homologada. Nenhum código, teste, deploy, infraestrutura, homologação ou contratação. **AGI-11-RB01 DESABILITADO.**

## 1. O problema

Um envio pode ser gravado duravelmente pela API principal ou de contingência (T0), entrar na fila, ser transportado ao CRM e receber resposta intermediária/timeout. Sem uma **autoridade exclusiva para atestar o resultado de negócio**, as integrações correm risco de marcar um lead como entregue cedo demais, criar registros duplicados ao retentar ou descartar material ainda em trânsito.

Já está aprovado que **recepção durável na Imagine não é entrega ao CRM**; o CRM confirma seu próprio registro. AUT-P05 deve especificar a fronteira entre **comando**, **resposta técnica**, **consulta de estado** e **confirmação final**, sem modificar o limite de 432 horas.

## 2. Regras vigentes comprovadas, não em deliberação

1. IN127-R01: T0 nasce somente na **primeira persistência durável confirmada** da submissão válida no backend Imagine, principal ou contingência; reenvio/failover não altera T0.
2. IN127-R02: **72h iniciais + 360h de falhas = 432h contínuas desde T0**; nenhuma retentativa ou processo reinicia prazo.
3. IN127-R03: ao vencer 432h sem entrega oficialmente confirmada, **encerrar processamento, impedir novas tentativas, descartar payload pessoal das estruturas ativas/temporárias** com ressalvas de backups/retenção legítima; não eliminar lead já entregue ao CRM.
4. IN127-R04: conservar **somente metadados mínimos** de auditoria por até **90 períodos de 24h** contados do evento terminal aplicável, sem reinício por correção posterior.
5. IN127-R05: timeout e 2xx/202 intermediários são **inconclusivos**, não sucesso nem falha comprovada. Antes de retentar, consultar **resultado oficial do CRM**. Retentativas somente dentro de 432h, com idempotência efetiva, prevenção de concorrência e autorização. Após o prazo, **consulta apenas**, sem reenvio nem reconstrução do payload. Confirmação tardia pode corrigir classificação auditável de entrega efetivada antes do vencimento.
6. IN127-R06 e AUT-P01–03: destino Site × CRM precisa estar oficialmente autorizado, com versão válida, barreiras de revogação e estado de captação pertinente. Troca de CRM **não redireciona submissões anteriores**.
7. IN-148/149: ID lógico estável por submissão, confirmação após persistência no CRM e comparação segura entre APIs, filas e CRM. Campos de contato iguais em envios distintos **não** permitem mesclagem automática.
8. IN-128: cópia temporária de **lead entregue e confirmado** é limpa **24h depois da confirmação**, preservando registro CRM.

## 3. Alternativas para decisão AUT-P05

### A — Marcar como entregue no retorno técnico de transporte

Considerar entregue quando o worker recebe HTTP 2xx ou 202 do CRM ou quando o n8n termina uma execução.

**Não recomendada / incompatível** com IN127-R05 e IN-149. Uma aceitação técnica pode ser apenas enfileiramento, sem registro persistido no CRM.

### B — Comando idempotente + confirmação autoritativa do CRM + consulta e reconciliação (RECOMENDADA, **NÃO RATIFICADA**)

1. **Comando identificado:** cada submissão lógica tem identidade estável e contexto imutável de empresa, site, formulário, vínculo CRM autorizado, destino e T0; cada tentativa recebe identidade própria para auditoria, preservando a submissão original.
2. **Idempotência do destino:** o CRM deve reconhecer a chave/contexto da mesma submissão e impedir que tentativas concorrentes, contingência e reenvios criem mais de um resultado de negócio para aquela mesma submissão; envios distintos, ainda que tenham nome/telefone iguais, **não** são fundidos por essa regra.
3. **Resposta inicial diferenciada:** `recebido_tecnicamente`, `aceito_para_processamento`, `pendente` e `inconclusivo` não equivalem a `entregue_confirmado_crm`. Os nomes são ilustrativos, não enums aprovados.
4. **Confirmação oficial:** a operação atesta entrega somente após o **CRM demonstrar persistência/criação/associação efetiva** ao registro correto do tenant e conta autorizados, com referência verificável de destino e instante de efetivação. Retorno técnico só comprova entrega se o próprio contrato do CRM garantir essa semântica e houver prova verificável.
5. **Consulta autoritativa:** para resposta perdida, intermediária, tardia ou inconsistente, usar comando de consulta pelo identificador estável/contexto na **fonte oficial CRM**, evitando repetição cega. Agente, worker, fila, API de captação e Portal podem observar e reconciliar, mas não inventam resultado.
6. **Concorrência:** impedir que duas rotas/consumidores produzam ao mesmo tempo operações conflitantes para a mesma submissão; exigir serialização, chave idempotente e controle de versão/estado conforme contrato posterior. A garantia real depende de posterior teste/homologação.
7. **Retry seguro antes de 432h:** somente quando a consulta oficial e o estado permitirem, com idempotência efetiva e autorização comprovadas. Persistindo ambiguidade sem garantia, **não repetir automaticamente**, registrar incidente e escalar à equipe autorizada.
8. **Prazo e descarte:** a partir de **T0+432h**, não emitir novos comandos de entrega e não reiniciar o prazo; encerrar estado pendente, tratar descarte das estruturas ativas e temporárias conforme R03 e conservar apenas metadados mínimos conforme R04. Resultado inconclusivo **não** é justificativa de quarentena operacional extra.
9. **Confirmação tardia:** consultar somente por referências mínimas após o vencimento; se CRM comprovar que a entrega realmente se efetivou **antes** do vencimento, corrigir classificação auditável sem criar novo lead; se comprovar entrega efetiva **após** o prazo, registrar incidente de violação do limite e não reabrir a janela.
10. **Fronteiras:** a alteração do vínculo CRM por AUT-P03 não autoriza migração de dados nem mudar destino de pendências antigas; suspensão/revogação é tratada conforme AUT-P01/02 e posteriormente AUT-P06.
11. **Prova e auditoria:** correlacionar `submission_id`, empresa, site, formulário, autorização/revisão, T0, destino original, operações/tentativas, estado, referências oficiais e momentos de observação/confirmação, sem reter payload completo na auditoria de 90×24h.
12. **Falha fechada na conclusão:** ausência de prova do CRM implica estado pendente/inconclusivo, nunca sucesso presumido. HTTP 202 não é prova de entrega, e a conclusão do agente não muda essa autoridade.

**Vantagens:** confirmação de negócio separada da infraestrutura; resistência a duplicidade por retries e failover; tratamento de respostas perdidas; proteção do prazo e da segregação multiempresa.  
**Complexidade:** exige contrato real de comandos/consulta no CRM, idempotência persistente e gestão de estados e concorrência; alternativas físicas e critérios de homologação seguem pendentes.

### C — Conclusão inferida pela IA ou logs do orquestrador

Agente IA conclui que houve entrega ao interpretar logs, mensagem de fila ou execução completa sem consultar o CRM.

**Não recomendada / incompatível** com a autoridade oficial do CRM, mesmo com logs aparentemente completos.

## 4. Exemplos de decisão operacional proposta pela alternativa B

| Cenário | Classificação correta / ação |
|---|---|
| API persistiu e respondeu ao visitante | **Recebido duravelmente**, não entregue CRM |
| CRM retornou HTTP 202 para processamento assíncrono | **Pendente de confirmação final**, consultar |
| CRM persistiu lead; resposta se perdeu | Consulta CRM comprova mesmo `submission_id`; **confirmar**, sem reenvio duplicado |
| Principal e contingência processam mesmo ID | CRM idempotente evita duplicidade; reconciliar estados |
| Dois clientes diferentes informam o mesmo telefone | **Submissões distintas**, nenhuma fusão automática por conteúdo |
| CRM nega operação com falha comprovada dentro de 432h | Retentar apenas nas condições seguras e autorizadas |
| Timeout sem estado oficial confiável | **Inconclusivo**, evitar reenvio cego; escalar |
| Pedido de troca do CRM entre recepção e entrega | Destino da submissão original preservado; nova conta não recebe implicitamente |
| Prazo 432h esgotado sem confirmação | **Encerrar envio**, descartar payload sob R03 e somente consulta minimizada após vencimento |
| Confirmação oficial tardia de entrega efetiva anterior a 432h | Corrigir classificação auditável sem novo envio, sem ampliar retenção |
| Entrega efetiva tardia após 432h | Registrar incidente de prazo, sem reabrir processamento |
| Entregue oficialmente antes do limite | Limpeza da cópia temporária da integração após 24h da confirmação, preservando CRM |

## 5. Classes de contrato candidato AUT-P05-CT01 (NÃO HOMOLOGADO)

| Classe | Atributos candidatos | Observação |
|---|---|---|
| Identidade | `empresa_id`, `site_id`, `form_id`, `submission_id`, `destination_crm_account_id` | Chave contextual anti-cross-tenant |
| Origem e autorização | `authorization_revision`, `form_version`, `T0` | Sem destino reatribuído por retry |
| Comando | `delivery_operation_id`, `attempt_id`, `idempotency_key`, `expected_state` | Comandos tipados e validação de contexto |
| Resposta | `technical_acceptance`, `authoritative_outcome`, `crm_record_ref` | Aceitação técnica distinta de persistência |
| Consulta | `query_by_submission_context`, `crm_effective_at`, `observed_at` | Sem criar registro durante consulta |
| Histórico | `deadline_at`, `terminal_event_at`, `reconciliation_ref`, `audit_ref` | Prazo e auditoria conforme IN-127 |

Os nomes e formatos são **candidatos**, não APIs, schemas, SLAs ou enumerações já aprovados. Segurança, assinatura entre serviços, RPO, retentativas, retenção, transporte e concorrência exigem especificação e aprovação próprias.

## 6. Critérios de aceitação documental e futuras provas (sem executar testes agora)

- Confirmar fonte oficial CRM, direitos do destino, semântica de persistência/associação e referência persistente por submissão.
- Validar idempotência por empresa/conta/submissão e distinção entre envios genuinamente diferentes.
- Especificar consulta de status sem efeitos colaterais, falhas parciais, respostas perdidas, 202, ordem divergente e estados inconclusivos.
- Conciliar expiração e descarte distribuído em T0+432h com correções consultivas tardias e retenção mínima auditável.
- Não duplicar nem alterar leads históricos por mudanças Site × CRM, suspensões ou eventos financeiros.
- Documentar permissões, segurança, observabilidade, evidências e alçadas para ações técnicas críticas. Testes de homologação ocorrerão somente após aprovação do desenvolvimento, em etapa apropriada.

## 7. Fontes conferidas

- [IN127-R01 a R06 — ratificação vigente](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-127-R01-R06-ratificacoes-2026-11-03.md).
- [IN-149 — protocolo de confirmação](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-149-protocolo-entrega-confirmacao-leads.md).
- [IN-148 — reconciliação](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-148-reconciliacao-inteligente-leads.md).
- [IN-124 — duas rotas de captação](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-124-integracao-inteligente-formularios-leads.md).
- [IN-128 — limpeza de entregues](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-128-limpeza-segura-auditoria-filas-leads.md).
- [INT-P0 — autoridades de negócios](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/integracoes/INT-P0-autoridades-contratos.md).

## 8. Pedido de deliberação — somente AUT-P05

**Recomendação:** **alternativa B**, com comando idempotente e confirmação oficial verificável do CRM, consulta segura para estados inconclusivos, reenvio somente sob idempotência comprovada e dentro de 432h, e reconciliação consultiva após o prazo sem reconstruir payload. Nenhuma IA ou sistema de transporte substitui o CRM como autoridade do fato de entrega.

**Status final:** AUT-P01/02/03/04 B aprovadas; **AUT-P05 pendente de decisão**; AUT-P06 a AUT-P10 pendentes. **P0-DOC03 / RF-PEND-01 abertos; RF-PEND-04 não homologada; AGI-11-RB01 desabilitado.**
