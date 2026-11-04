# AUT-P05 — Aprovação da alternativa B: confirmação oficial de entrega no CRM

**Estado:** APROVADA no planejamento em 04/11/2026, pela resposta contextual **“ok”** à pergunta direta “Você aprova a AUT-P05 pela alternativa B?”. Esta aprovação se refere à alternativa B apresentada na [proposta AUT-P05](P0-DOC03-AUT-P05-CONFIRMACAO-OFICIAL-LEADS-CRM-RECONCILIACAO-PROPOSTA.md), sem presumir homologação técnica de cada campo candidato.

**Escopo:** somente decisão documental da integração Imagine Sites / APIs de captação / filas / CRM Imagine. Não autoriza código, testes, implantação, contratação ou homologação.

## 1. Decisão ratificada — alternativa B

**Comando idempotente, confirmação autoritativa do CRM e consulta/reconciliação antes de repetir resultados inconclusivos.**

1. Distinguir **recepção durável na API (T0)**, aceitação técnica, processamento, confirmação oficial de persistência no CRM e resultado inconclusivo. HTTP 2xx/202 intermediário, conclusão de fila/n8n ou inferência de agente não comprovam entrega.
2. Manter **identidade estável e contextual por submissão lógica**, destino CRM originalmente autorizado, T0 único e chave idempotente preservada em todas as tentativas, inclusive alternância entre APIs. Tentativas diferentes do mesmo envio não criam registros duplicados; envios comerciais distintos com conteúdos iguais não devem ser mesclados.
3. Somente o **CRM Imagine**, como autoridade da operação de criação/associação de seu registro, pode comprovar entrega efetiva, com referência identificável e verificável ao resultado persistido e à empresa/conta correta.
4. Diante de timeout, resposta perdida, intermediária, tardia ou divergente, consultar o **estado oficial CRM** por contexto/idempotência antes de qualquer novo comando. Resultado não comprovado é **inconclusivo**, não sucesso presumido nem falha presumida.
5. Retentativas somente **antes de T0+432h**, quando autorização do destino, idempotência efetiva e controle de concorrência estiverem demonstrados. Na ambiguidade sem garantias, bloquear reenvio automático e escalar à equipe competente.
6. Em **T0+432h**, encerrar entrega e aplicar exclusão do payload pessoal pendente nas estruturas ativas/temporárias Imagine conforme **IN127-R03**, respeitando exceções legítimas e não afetando registros já persistidos no CRM. Depois do prazo, **somente reconciliação consultiva** com metadados mínimos, sem reenvio ou restauração do payload.
7. Confirmação tardia de efetivação **anterior** ao vencimento pode corrigir classificação auditável sem criar novo lead. Efetivação **posterior** ao limite é incidente, sem reiniciar prazo.
8. Manter auditoria minimizada conforme **IN127-R04: até 90 períodos de 24h** após evento terminal aplicável, sem reinício por correção posterior; cópia temporária de lead entregue é limpa **24h após confirmação**, conforme IN-128.
9. Preservar as decisões **AUT-P01 a AUT-P04 B** e **IN127-R01 a R06**, inclusive bloqueio de captação sem CRM autorizado e proibição de redirecionamento automático de leads por mudança de vínculo.

## 2. Alternativas rejeitadas nesta decisão

- **A:** declarar entrega com base exclusiva em resposta técnica HTTP 2xx/202 ou término de workflow.
- **C:** permitir que IA/logs do orquestrador atestem sozinhos a persistência oficial do lead.

## 3. Detalhamento ainda pendente

Os contratos de comando, consulta e confirmação, APIs/rotas exatas, autenticação, esquemas e enums, implementação da idempotência, mecanismo de serialização e prova técnica de segurança contra duplicidade **não foram aprovados nem homologados** por esta decisão.

**AUT-P01 a AUT-P05:** alternativas B aprovadas normativamente. **AUT-P06 a AUT-P10:** pendentes. **P0-DOC03 e RF-PEND-01:** abertos. **RF-PEND-04:** sem homologação. **AGI-11-RB01:** rollback automático DESABILITADO.

## 4. Referências

- [AUT-P05 — proposta histórica](P0-DOC03-AUT-P05-CONFIRMACAO-OFICIAL-LEADS-CRM-RECONCILIACAO-PROPOSTA.md).
- [IN127-R01–R06 — ratificações](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-127-R01-R06-ratificacoes-2026-11-03.md).
- [IN-149 — confirmação](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-149-protocolo-entrega-confirmacao-leads.md).
- [IN-148 — reconciliação](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-148-reconciliacao-inteligente-leads.md).

**Esta é aprovação exclusivamente documental, não início do desenvolvimento.**
