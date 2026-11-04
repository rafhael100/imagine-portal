# P0-DOC03 — AUT-P07: comprovação de descarte em APIs, filas, réplicas e backups

**Estado: PROPOSTA PARA DELIBERAÇÃO — NÃO RATIFICADA.**  
**Data:** 04/11/2026. **Fase:** apenas planejamento Imagine Sites/Portal/CRM.  
**Precedências:** AUT-P01 a AUT-P06, alternativas B aprovadas; IN127-R01 a R06 ratificadas; IN-124/128/147/148/149 nos aspectos compatíveis.  
**Pendências:** AUT-P08 a AUT-P10 sem aprovação; P0-DOC03 e RF-PEND-01 abertos; RF-PEND-04 sem homologação técnica. **Proibidos programação, testes, implantação, contratação, homologação e execução de descarte real. AGI-11-RB01 desabilitado.**

## 1. Delimitação — o que já está aprovado

- **Lead não entregue:** a partir de **T0+432h** (72h iniciais + 360h de falhas), encerrar as tentativas e providenciar descarte do **payload pessoal completo** nas estruturas **ativas e temporárias** da Imagine, incluindo filas, mensagens, cópias auxiliares e réplicas. T0 é a primeira persistência durável confirmada, sem reinício por retentativas.
- **Lead já confirmado no CRM:** após a confirmação oficial de persistência, limpar cópias temporárias da integração **24h depois**, preservando o registro CRM e sua política própria (IN-128). A expiração de 432h não é regra para eliminar leads registrados no CRM.
- **Backups protegidos:** seguem políticas próprias, retenção legítima e limitações técnicas comprováveis. Isso **não cria** autorização para retenção ilimitada, recuperação informal ou nova fila depois das 432h. Recuperar backup não pode reativar payload já descartado.
- **Auditoria operacional:** conservar somente evidências mínimas necessárias por **até 90 períodos consecutivos de 24h a partir do evento terminal** aplicável, sem payload, telefone, e-mail ou nome. A correção posterior não reinicia esse prazo.
- **Alerta:** para não entregues, aviso 72h antes do vencimento (**T0+360h**) e alerta crítico em **T0+432h**. Nenhum evento ou prova pode inventar entrega ao CRM; resultados incertos respeitam IN127-R05.
- **Exceções legítimas:** obrigação legal de guarda, ordem de preservação ou impedimento de integridade são restritas, documentadas, auditadas e segregadas. Não equivalem à reabertura de processamento.

## 2. Problema AUT-P07

Apagar uma mensagem da fila principal não comprova que o payload desapareceu da API secundária, fila de erros, armazenamento temporário, réplicas, snapshots ou backup. Também seria incorreto declarar que **todos os backups foram fisicamente apagados** apenas porque houve exclusão lógica na operação ativa.

A AUT-P07 define a **governança da prova de eliminação e prevenção de ressurreição**, distinguindo execução solicitada, exclusão confirmada, locais técnicos sob retenção controlada, cópias inacessíveis por evidência indisponível e exceções legítimas — sem alterar a janela nem a política de retenção já aprovadas.

## 3. Alternativas submetidas à aprovação

### A — Considerar excluído após limpeza da fila principal

Uma confirmação de remoção na fila de processamento bastaria para marcar o lead como descartado em toda a integração.

**Não recomendada.** Não demonstra retirada da contingência, réplicas, arquivos temporários, workers ou cópias auxiliares e pode induzir falsa declaração de descarte integral.

### B — Inventário de cópias + execução verificável por superfície + proteção de restaurações (**RECOMENDADA, AINDA NÃO RATIFICADA**)

1. **Inventário lógico:** cada submissão tem referências técnicas mínimas às superfícies em que possa existir payload na ingestão principal, ingestão de contingência, filas normais/de falhas, armazenamento temporário, réplicas, derivados e backups técnicos pertinentes. Não replicar o payload para fins de inventário.
2. **Gatilho oficial:** após confirmação efetiva de entrega CRM, programar limpeza temporária em **24h**; para não entregues, expiração em **T0+432h**, interrompendo imediatamente novas transmissões. Os critérios de corrida e reconciliação vêm de IN127-R03/R05.
3. **Orquestração idempotente:** comandar descarte para cada superfície ativa/temporária aplicável; tentativas repetidas de limpeza devem convergir para o mesmo resultado sem recriar conteúdo ou atingir outro tenant.
4. **Verificação por superfície:** cada camada emite resultado distinguindo `solicitado`, `em_execucao`, `confirmado`, `falhou`, `inconclusivo` ou `excecao_legitima` (nomes ilustrativos). Não declarar “exclusão concluída” se houver camada crítica ainda inconclusiva.
5. **Barreira contra ressurreição:** recuperação de backup, reprocessamento de mensagem antiga, failback e replicação atrasada devem verificar terminalidade e prazo antes de reintroduzir material no fluxo ativo; payload descartado não pode ser colocado novamente em fila nem reenviado depois de 432h.
6. **Backups separados:** manter **inventário de classes de cópias protegidas e políticas próprias** de retenção, acesso, expiração, substituição e restauração. Se existir cópia protegida legitimamente retida, a prova deve dizer **“eliminado do ativo/temporário; cópia protegida sob retenção controlada até sua expiração prevista”**, sem alegar eliminação física integral.
7. **Restauração segura:** antes de disponibilizar dados restaurados aos serviços ativos, reconciliar estado terminal e política de cada submissão (ou mecanismo equivalente seguro e viável), evitando reprocessar vencidos/entregues. A forma técnica e retenção mínima desse controle dependem de especificação posterior e não autorizam retenção pseudonimizada indefinida.
8. **Evidência mínima proporcional:** registrar referência técnica protegida da submissão, empresa/site, T0/instante terminal, classe da superfície, evento/comando de eliminação, resultado verificável, responsável técnico, exceção documentada e instante de comprovação, sem copiar payload ou segredos para o log.
9. **Conclusão honesta:** status consolidado distingue **descarte comprovado nas camadas ativas/temporárias**, **descarte parcial**, **não comprovado**, **exceção legítima** e **backups protegidos ainda sujeitos à política própria**. Nunca usar “destruído em todas as mídias” sem prova dessa afirmação.
10. **Falhas e incidentes:** falha de limpeza abre incidente com acesso restrito e retentativas de **descarte** controladas; não reabre a fila de **entrega**, não concede quarentena operacional adicional nem reinicia T0 ou prazo de auditoria. Não substituir eliminação por mero alerta.
11. **Segregação:** preservar tenants, minimização LGPD e permissões; uma decisão de descarte da integração não exclui leads, contatos, backups e históricos oficiais do CRM nem dados de outra conta.
12. **Controle de evidência:** o registro de auditoria também tem ciclo de vida, proteção contra alteração, acesso limitado e eliminação no prazo aplicável; não usar IDs pseudonimizados como desculpa para armazenamento pessoal sem limite.

**Benefícios:** verificação realista, transparência sobre backups, restauração segura, menor risco de ressurgimento de filas e evidência proporcional sem prometer apagamento impossível. **Complexidades:** catálogo de cópias, reconciliador de estados, limpeza distribuída, governança dos backups e custo da prova; sujeitos à aprovação técnica posterior.

### C — Manter payload completo até confirmação manual de destruição dos backups

Reter operacionalmente todos os dados após o limite para esperar confirmação manual de apagamento físico em cada backup.

**Não recomendada/incompatível** com IN127-R03/R05: prolongaria a janela de fila e permitiria retenção operacional informal, sem autorização.

## 4. Matriz de evidências proposta pela alternativa B

| Superfície | Resultado que deve ser provado | Limite |
|---|---|---|
| API principal e armazenamento primário | Ausência de payload ativo após descarte elegível, com confirmação por mecanismo validado | Não basta retirar da lista visível |
| API de contingência e armazenamento independente | Confirmação própria, sem depender de sucesso presumido da principal | Falhas parciais não são sucesso |
| Filas normais, erros, retries e mensagens pendentes | Tarefa inativada, payload eliminado ou tornado inacessível conforme mecanismo homologado; nenhuma reentrega | Não reiniciar 432h |
| Réplicas, temporários, arquivos/derivados e caches sob controle Imagine | Identificação, limpeza e comprovação em cada classe aplicável | Não declarar exclusão física sem prova |
| Backup protegido | Estado de retenção técnica e política de expiração/restauração documentados | Não confundir backup protegido com fila ativa |
| Registro principal CRM entregue | **Preservado**, sujeito à política própria do CRM | IN127 não autoriza apagar CRM |
| Auditoria mínima | Evidência de resultado e exceções, protegida e minimizada | Até 90×24h desde evento terminal, salvo fundamento específico |

## 5. Casos críticos que ainda precisam de contrato e futura homologação

- CRM confirma entrega **antes de T0+432h**, mas resposta se perde: distinguir confirmação oficial consultada da hipótese inconclusiva; limpeza do payload de integração não apaga o registro CRM.
- Confirmação e descarte iniciam simultaneamente: controle de concorrência e estado oficial, sem concluir com base em snapshot desatualizado.
- API secundária indisponível durante descarte: registrar **parcial/inconclusivo**, impedir retomada indevida da fila e executar reparo de limpeza autorizado quando possível; não reabrir prazo de envio.
- Recuperação de backup anterior à eliminação: provar que submissões já terminais não são reprocessadas nem apresentadas como novos leads.
- Backup protegido sob obrigação legal: preservar somente na finalidade, acesso e prazo autorizados; nunca utilizar como nova fila.
- Provedor de armazenamento não comprova apagamento imediato: registrar limite da evidência e política de ciclo de vida, não afirmar garantia inexistente.
- Logs contêm identificadores pseudonimizados potencialmente pessoais: aplicar retenção, controle de acesso e eliminação conforme IN127-R04.

## 6. Contratos candidatos (NÃO HOMOLOGADOS)

Candidatos de referência: `submission_ref`, `tenant_ref`, `t0`, `crm_delivery_confirmation_ref`, `terminal_event_at`, `expiry_at`, `copy_class`, `deletion_operation_ref`, `deletion_state`, `verification_evidence_ref`, `protected_backup_policy_ref`, `restore_guard_ref`, `exception_reason_class`, `audit_expiration_at`.

**Pendente:** local e escopo exatos das cópias, APIs e formatos de confirmação, exclusão física/lógica/criptográfica quando aplicável, ciclos de backup, assinaturas, integridade, controle de concorrência, mecanismo de barreira de restauração, tratamento de fornecedores, custos, relatórios, exceções legais, testes de borda e critérios de homologação. Não foi aprovada tecnologia, prazo universal para backup ou retenção adicional.

## 7. Fontes confrontadas

- [IN127-R01 a R06 — ratificações e precedência](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-127-R01-R06-ratificacoes-2026-11-03.md).
- [IN-128 — limpeza de entregues e auditoria](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-128-limpeza-segura-auditoria-filas-leads.md).
- [IN-147 — exclusão após 18 dias](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-147-exclusao-leads-nao-entregues-apos-18-dias.md).
- [IN-148 — reconciliação e prevenção de ressurreição](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-148-reconciliacao-inteligente-leads.md).
- [IN-149 — protocolo de confirmação CRM](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-149-protocolo-entrega-confirmacao-leads.md).
- [AUT-P05 — confirmação do CRM](AUT-P05-aprovacao-alternativa-B-2026-11-04.md).
- [AUT-P06 — autorização do backlog](AUT-P06-B-aprovada-2026-11-04.md).

## 8. Pedido de deliberação — somente AUT-P07

**Recomendação:** alternativa **B**, inventário das cópias e descarte verificável por API/fila/réplica, com tratamento separado e transparente de backups protegidos, barreira de restauração e auditoria mínima. Sem eliminação presumida e sem ampliação de 432h ou do prazo de 90×24h.

**Status final:** AUT-P01 a AUT-P06 B aprovadas; **AUT-P07 ainda pendente**; AUT-P08 a AUT-P10 pendentes. P0-DOC03/RF-PEND-01 abertos, RF-PEND-04 não homologada, AGI-11-RB01 desabilitado. Sem execução real.
