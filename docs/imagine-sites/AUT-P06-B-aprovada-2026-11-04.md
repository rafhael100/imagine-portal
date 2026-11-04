# AUT-P06 — Alternativa B aprovada

**Aprovação:** resposta "okok" à pergunta direta sobre AUT-P06, alternativa B, em 04/11/2026. É aprovação normativa do planejamento, não implementação.

A AUT-P06 estabelece que cada lead já recebido conserva o **T0 e o CRM de destino originais**. Antes de nova operação de entrega, a Imagine deve verificar autorização oficial específica para concluir aquele envio anterior. É proibido usar autorização incerta ou revogada como fundamento para transmitir.

**Troca do CRM vinculado:** leads novos usam o destino novo após ativação válida; pendências antigas não são enviadas automaticamente à conta nova. O backlog só poderá ser entregue à conta original enquanto a autorização específica permanecer válida.

**Suspensão, cancelamento ou revogação:** bloquear novas captações conforme AUT-P01 e AUT-P02; avaliar separadamente o direito de concluir submissões antigas. CRM pago independente não deve ser bloqueado apenas por suspensão do site. Falha técnica do CRM não equivale a revogação.

**Respostas inconclusivas:** consultar o estado oficial do CRM antes de qualquer tentativa idempotente; evitar duplicações conforme AUT-P05.

**Prazos:** manter integralmente IN127-R01 a R06: T0 da recepção durável confirmada; 72h + 360h = 432h contínuas sem reinício; sem novas tentativas após vencimento; descarte de payload pessoal não entregue nas estruturas ativas/temporárias, respeitando guarda legítima; auditoria mínima até 90 períodos de 24h desde evento terminal; consulta sem reenvio após o limite. Nunca migrar leads por inferência.

A alternativa A (autorização permanente em T0) e a C (redirecionamento automático ao novo CRM) não foram escolhidas.

**Parâmetros ainda pendentes:** autoridade por tipo de revogação, protocolo de autorização de backlog, contratos de API, assinaturas e versões, consistência das duas filas, concorrência, retenção em backups e alçadas; nenhum deles foi homologado por esta aprovação.

**AUT-P01 a AUT-P06:** B aprovadas. **AUT-P07 a AUT-P10:** pendentes. **P0-DOC03 e RF-PEND-01:** abertos. **RF-PEND-04:** não homologada. **AGI-11-RB01:** desabilitado. Nenhuma programação, teste, implantação, contratação ou homologação autorizada.

Fonte: [proposta AUT-P06](AUT-P06-backlog-autorizacao-destino-proposta.md) e [IN127-R01 a R06](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-127-R01-R06-ratificacoes-2026-11-03.md).
