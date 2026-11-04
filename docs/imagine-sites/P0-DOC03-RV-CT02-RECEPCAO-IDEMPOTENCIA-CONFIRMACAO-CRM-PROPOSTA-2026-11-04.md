# P0-DOC03 — RV-CT02: recepção durável e entrega confirmada ao CRM

> **DECISÃO POSTERIOR (04/11/2026):** a alternativa B foi aprovada como diretriz técnica documental. Consultar [ratificação RV-CT02 B](P0-DOC03-RV-CT02-B-APROVADA-2026-11-04.md). Esta minuta conserva as alternativas e referências a "pendente" como histórico anterior; o contrato definitivo e a homologação permanecem pendentes.

**Estado: PROPOSTA, NÃO APROVADA OU HOMOLOGADA.** Data: 04/11/2026.
**Escopo:** formulários Imagine Sites, API principal, API de contingência, armazenamento/fila de integração, CRM Imagine e reconciliação.
**Precedências aprovadas:** AUT-P05 e P06 B; RV-CT01 B como diretriz; IN-124, IN-148, IN-149, IN127-R01 a R06 (**R02=A**, 72h+360h=432h), IN-128 e IN-147. AUT-P07 delimita descarte. Não criar novas decisões comerciais.
**Gate:** P0-DOC03 e RF-PEND-01 abertos; RF-PEND-04 não homologada; AGI-11-RB01 desabilitado. Somente planejamento, sem programação, testes, compras ou implantação.

## 1. Alternativas para deliberação

**A — Armazenamento central compartilhado como condição de recepção:** as duas APIs dependem de um mesmo repositório/serviço de controle para cada gravação. Facilita deduplicação, mas a indisponibilidade desse componente pode comprometer a independência de recepção já aprovada na IN-124. Não recomendada como solução única.

**B — Ingestão durável independente + identidade estável ponta a ponta + idempotência no CRM + reconciliação autoritativa (RECOMENDADA, NÃO RATIFICADA EM RV-CT02):** cada API persiste autonomamente somente após validação de autorização, conserva a identidade da submissão, reconcilia cópias/tentativas e pede ao CRM operações idempotentes. O CRM comprova persistência ou informa estado oficial consultável. Resultado incerto não autoriza reenvio cego. **A prevenção de duplicidades exige mecanismo técnico demonstrável; não afirmar “exactly once” sem provas.**

**C — Sucesso do HTTP 2xx/202 e repetição até funcionar:** fila ou n8n tratam ACK intermediário como entrega final e repetem sem consulta. Incompatível com AUT-P05, IN127-R05 e IN-149. Não recomendada.

## 2. Duas confirmações com autoridades distintas — diretriz B

| Marco | Autoridade e prova | Consequência |
|---|---|---|
| Formulário autorizado | RV-CT01 / verificação em cada API | Sem autorização comprovada, não recebe nem cria T0 |
| Recepção durável | API principal **ou** contingência, prova de persistência confirmada | Define **T0 original** da submissão; visitante pode saber que foi recebida, não que já chegou ao CRM |
| Processamento/fila | Trabalhador de integração com identidade preservada | Não equivale a entrega ao CRM |
| Persistência final no CRM | **CRM Imagine**, com resultado oficial por conta/tenant/operação | Só então marcar **ENTREGUE_CONFIRMADO_CRM** |
| Resposta perdida/inconclusiva | Consulta/reconciliação oficial no CRM | Não converter em falha definitiva ou sucesso presumido |
| Encerramento temporal | T0+432h contínuas; política IN127 | Proíbe novo envio após vencimento; descarte conforme R03/AUT-P07 |

## 3. Invariantes da alternativa B

1. **Um envio lógico, uma identidade estável:** manter identificador em retries de browser, API principal, contingência, filas, CRM e consulta. Dois envios legítimos, ainda que tenham mesmos nome/telefone/conteúdo, **não** são automaticamente o mesmo lead.
2. **Independência real:** APIs principal e contingência possuem persistência durável separada, com capacidade de funcionar quando outra rota falha. A indisponibilidade do painel Portal não impede recepção segura.
3. **Não inventar T0:** T0 é a **primeira recepção durável oficialmente confirmada pelo backend** para aquele envio lógico. Resposta HTTP perdida não cria outro T0 quando uma segunda rota recebe a mesma identidade; múltiplas cópias exigem reconciliação dos fatos comprovados.
4. **Envelope de identidade:** tenant, site, formulário, versão, idempotency reference, autorização/revisão do vínculo, **CRM original** e T0 seguem com a operação. O destino não pode ser reescrito por parâmetros do frontend nem pelo novo vínculo do site.
5. **Autorização por operação:** validar captação de novos leads no ingresso (RV-CT01) e autorização específica de entrega do backlog a cada operação relevante (AUT-P06); indisponibilidade técnica de CRM válido não equivale a revogação.
6. **Idempotência efetiva no destino:** CRM deve oferecer contrato de comando e consulta capaz de reconhecer tentativa da **mesma submissão contextual**, mesmo após troca de API, retries e resposta perdida. O mecanismo e o período de retenção de chaves ainda não estão homologados.
7. **Resultados distintos:** aceito para processamento, gravado no CRM, inexistente, falha demonstrada, pendente e inconclusivo são eventos diferentes. Um HTTP 200/202 não prova o registro CRM criado/associado.
8. **Antes do retry:** em timeout, resposta ambígua ou evento fora de ordem, consultar estado CRM e correlacionar por tenant, envio e destino. Só reenviar com autorização, idempotência e exclusão segura de concorrência, **antes** de T0+432h.
9. **Relógio imutável:** janela inicial T0+72h, fila complementar até T0+432h, sem reinício por failover, reconciliação, queda, troca de CRM, reprocessamento ou incidente. Aviso preventivo 72h antes do término (T0+360h), alerta crítico no vencimento.
10. **Depois do limite:** interromper comandos de entrega, encerrar processamento e aplicar IN127-R03 à carga pessoal ativa/temporária; **apenas consulta pós-limite sem reenvio**, usando metadados minimizados legalmente disponíveis. Confirmação tardia de entrega efetiva antes do limite pode corrigir o histórico; não reconstituir payload descartado.
11. **Cópia entregue:** liberar limpeza técnica das cópias temporárias **24h após confirmação** da persistência do CRM, preservando registro oficial CRM. Descarte, réplicas e backups têm contrato próprio RV-CT03/AUT-P07.
12. **Auditoria:** até 90×24h de metadados mínimos da integração após evento terminal aplicável, sem reinício por evento tardio; não guardar payload integral, contatos completos ou segredos como “prova”.
13. **Agentes:** monitorar, consultar, recomendar e executar somente playbooks seguros homologados; ação crítica requer uma aprovação humana específica. Nenhum agente declara resultado oficial do CRM por inferência.

## 4. Estados lógicos candidatos — NÃO homologados

`RECEBIDO_DURAVEL`, `PENDENTE_ENTREGA`, `PROCESSANDO`, `CONFIRMACAO_INCONCLUSIVA`, `FALHA_REPROCESSAVEL`, `ENTREGUE_CONFIRMADO_CRM`, `EXPIRADO_SEM_ENTREGA`, `DESCARTE_PENDENTE`, `PAYLOAD_DESCARTADO`.

Estados e tentativas precisam de **transições monotônicas controladas**, transação/concorrência protegida e separação do estado de autorização Site×CRM. Confirmação oficial tardia não reabre fila de entrega nem reinicia T0; expiração não é licença para apagar dados já persistidos no CRM.

## 5. Cenários mínimos de confronto

| Situação | Comportamento obrigatório da proposta B |
|---|---|
| API principal gravou, resposta ao navegador se perdeu | A contingência usa mesma identidade; não duplica envio ou T0 |
| Contingência gravou enquanto principal estava fora | Entrega/reconciliação posterior preserva identidade e prazo original |
| CRM persistiu lead, mas resposta se perdeu | Consultar resultado CRM por operação/idempotência antes de retry |
| CRM responde só “aceito para processamento” | Não marcar como entregue até prova de persistência |
| Duas tentativas concorrentes em rotas distintas | Comando CRM idempotente e conciliação; sem promessa de exatamente uma gravação sem mecanismo comprovado |
| Dois contatos comerciais diferentes com dados iguais | Preservar duas submissões legítimas distintas |
| Vínculo muda durante espera | Não redirecionar backlog para novo CRM; revalidar autorização do destino original |
| A confirmação chega perto de T0+432h | Verificar momento real de efeito no CRM, transições e concorrência com expiração |
| Expirou sem entrega confirmada | Parar entrega e descartar temporários sob IN127-R03/RV-CT03, sem quarentena adicional |
| Restauração reaparece com mensagem antiga | Não reativar comando vencido/entregue nem reiniciar T0 |

## 6. Campos e contratos candidatos — não definitivos

`tenant_ref`, `site_ref`, `form_ref`, `submission_ref`, `idempotency_ref`, `ingest_route_ref`, `first_durable_receipt_ref`, `accepted_at_T0`, `original_crm_ref`, `authorization_revision`, `delivery_operation_ref`, `crm_official_result_ref`, `crm_persisted_at`, `delivery_deadline_ref`, `terminal_event_ref`, `audit_ref`.

**Pendências técnicas:** criação e integridade de ID, prevenção de abuso/replay, consulta de estado CRM, escopo e durabilidade da chave idempotente, coordenação principal/contingência, ordenação, relógios, ponto atômico de expiração, modo de reconciliação, segurança multiempresa, evidência de execução, retenções, SLAs e testes futuros. Todos exigem especificação/aceite posterior.

## 7. Pedido de deliberação

**Recomendada alternativa B** como diretriz documental para completar o futuro contrato RV-CT02. A escolha não homologa API, banco, mecanismos distribuídos nem fecha P0-DOC03.

**Estado:** AUT-P01–P10 aprovadas B; RV-CT01 aprovada B apenas como diretriz, contrato técnico ainda aberto; **RV-CT02 PROPOSTA NÃO APROVADA**, RV-CT03–08 pendentes; P0-DOC03 e RF-PEND-01 abertos, RF-PEND-04 não homologada, AGI-11-RB01 desabilitado. Sem programação ou testes.

## Fontes

[RV-CT01 aprovada](P0-DOC03-RV-CT01-B-APROVADA-2026-11-04.md), [AUT-P05](AUT-P05-aprovacao-alternativa-B-2026-11-04.md), [AUT-P06](AUT-P06-B-aprovada-2026-11-04.md), [AUT-P07](AUT-P07-B-aprovada-2026-11-04.md), [RV01](P0-DOC03-RV01-CONSOLIDACAO-AUT-P01-P10-2026-11-04.md).
[IN-124](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-124-integracao-inteligente-formularios-leads.md), [IN-127 ratificações](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-127-R01-R06-ratificacoes-2026-11-03.md), [IN-128](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-128-limpeza-segura-auditoria-filas-leads.md), [IN-147](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-147-exclusao-leads-nao-entregues-apos-18-dias.md), [IN-148](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-148-reconciliacao-inteligente-leads.md), [IN-149](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-149-protocolo-entrega-confirmacao-leads.md).
