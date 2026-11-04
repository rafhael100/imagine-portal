# AUT-P07 — Alternativa B aprovada

**Situação:** aprovada em 04/11/2026 pela resposta "ok" à pergunta direta sobre a alternativa B da AUT-P07. Decisão normativa e documental, sem homologação técnica.

## Decisão

Aprovar **inventário das cópias, descarte verificável por superfície e proteção contra recuperação indevida a partir de backups**, nos termos da proposta AUT-P07.

- Inventariar as possíveis cópias do lead na API principal, API de contingência, filas, mensagens, réplicas, armazenamento temporário e backups pertinentes, sem duplicar payload no inventário.
- Comprovar a exclusão das estruturas ativas/temporárias individualmente; excluir da fila principal não demonstra exclusão nas demais camadas. Registrar resultados confirmados, falhas, estados inconclusivos e exceções legítimas.
- **Lead não entregue:** em T0+432h contínuas, encerrar novas tentativas e descartar o payload pessoal ativo/temporário conforme IN127-R01 a R06, sem nova quarentena.
- **Lead entregue:** eliminar cópia temporária de integração 24h após confirmação oficial do CRM; preservar os dados oficiais do CRM.
- **Backups protegidos:** retenção, acesso, expiração e restauração obedecem a política técnica/jurídica própria ainda a definir; não afirmar destruição física de todas as mídias sem prova.
- **Restauração segura:** impedir que um backup, réplica ou mensagem antiga reintroduza lead já entregue/expirado no processamento ativo ou gere nova tentativa de entrega após 432h.
- **Auditoria:** metadados mínimos, sem dados integrais do formulário, por até 90 períodos contínuos de 24h desde evento terminal aplicável, salvo conservação legítima autorizada em política própria.
- **Falha no descarte:** abrir incidente e permitir recuperação controlada da rotina de eliminação, sem reiniciar prazo de lead nem reabrir a entrega. Preservar segregação multiempresa e idempotência.

Alternativa A (presumir exclusão após limpeza da fila principal) e alternativa C (reter payload operacional até destruição de todos os backups) **não foram escolhidas**.

**Permanece pendente:** contratos de APIs, evidências de exclusão, método técnico de proteção da restauração, prazos específicos de backup, alçadas, algoritmos, armazenamento, homologação e implementação.

**AUT-P01 a AUT-P07:** B aprovadas. **AUT-P08 a AUT-P10:** pendentes. **P0-DOC03/RF-PEND-01:** abertos. **RF-PEND-04:** não homologada. **AGI-11-RB01:** DESABILITADO. **Nenhuma programação, teste, implantação, homologação, contratação ou exclusão real autorizada.**

Fontes: [AUT-P07 — proposta](P0-DOC03-AUT-P07-COMPROVACAO-DESCARTE-APIS-FILAS-BACKUPS-PROPOSTA.md); [IN127-R01–R06](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-127-R01-R06-ratificacoes-2026-11-03.md).
