# RV-CT02 — Alternativa B aprovada: recepção durável, idempotência e confirmação CRM

**Ratificação:** em 04/11/2026, o responsável respondeu "ok" à pergunta direta sobre aprovar a RV-CT02 pela alternativa B. A decisão ratifica **a diretriz documental**, e não um contrato de integração implementado ou tecnicamente homologado.

**Objeto:** formulários de sites Imagine, API principal, API de contingência, persistência temporária independente, filas e confirmação de entrega pela autoridade CRM.

## Decisão aprovada: alternativa B

Adotar **recepção durável independente nas duas APIs, identidade estável por submissão lógica, idempotência contextual no destino e reconciliação consultiva autoritativa**. A integração deve separar claramente a confirmação de recebimento pela API da confirmação de efetiva persistência no CRM.

1. **Autorização antes de receber:** cada API comprova direito de captação e destino CRM específico (RV-CT01 B). Sem prova válida, não confirmar recebimento, não iniciar T0 e não inventar armazenamento ou canal alternativo.
2. **Duas rotas realmente independentes:** APIs principal e contingência mantêm condições de receber/persistir duravelmente de forma independente. Falha ou indisponibilidade de uma não autoriza a outra a ignorar segurança.
3. **Identidade estável ponta a ponta:** a mesma submissão lógica mantém referência de empresa, site, formulário, versão, autorização, destino original e chave idempotente em todas as tentativas, rotas, filas e comandos ao CRM. Submissões comerciais distintas com dados iguais não são fundidas por heurística.
4. **T0 autoritativo:** T0 é a primeira **recepção durável oficialmente confirmada pelo backend** para a submissão lógica, conforme IN127-R01. Resposta perdida não cria um novo relógio, e duas cópias independentes devem ser reconciliadas. É pendente o mecanismo distribuído de correlação e prova do primeiro registro.
5. **Confirmações distintas:** o visitante só recebe confirmação de **recebimento** após persistência comprovada em uma das APIs. O estado **entregue ao CRM** exige comprovação de criação ou associação final do registro correto pela autoridade CRM, não apenas HTTP 2xx/202, fila concluída ou n8n.
6. **Estado inconclusivo:** diante de resposta perdida, timeout, confirmação intermediária, execução concorrente ou divergência, consultar o resultado oficial do CRM pelo contexto/idempotência. Não assumir sucesso, falha ou ausência do registro apenas pelo timeout.
7. **Repetição segura:** um novo comando de entrega somente é possível se destino originalmente autorizado, idempotência efetiva, controle de concorrência e janela temporal ainda válida estiverem demonstrados. Na ambiguidade, bloquear repetição automática e escalar. Proibida promessa de "exactly once" sem mecanismo e evidência homologados.
8. **Destino congelado:** alterações posteriores de vínculo Site × CRM não redirecionam leads antigos. Antes de cada operação relevante de backlog, revalidar autorização específica de entrega ao **destino original**, conforme AUT-P06; indisponibilidade técnica não é revogação.
9. **Prazo contínuo IN127-R02 alternativa A:** T0+72 horas é o limite da janela inicial; T0+432 horas encerra a janela total (72h+360h). Falhas, failover, consulta, reenvio, revisão de vínculo e recuperação não pausam ou reiniciam o prazo. Alertar preventivamente em T0+360h, conforme regras complementares.
10. **Expiração e limpeza:** após T0+432h, não emitir novo comando de entrega; encerrar a operação e cumprir IN127-R03 e AUT-P07 quanto ao descarte do payload ativo/temporário não entregue, sem criar quarentena extra. Após o limite, somente reconciliação **consultiva** com metadados mínimos legitimamente disponíveis e sem recuperação de payload descartado.
11. **Confirmação tardia:** uma prova CRM de persistência efetiva anterior ao prazo pode corrigir classificação auditável, sem novo envio. Se o efeito real ocorreu após o limite, tratar como incidente; não reabrir prazo nem inventar nova autorização.
12. **Lead entregue:** limpar cópias temporárias de integração 24 horas após confirmação oficial do CRM, preservando o registro do CRM. Proteção de backups, outras superfícies e antirressurreição seguem AUT-P07 e o futuro RV-CT03.
13. **Auditoria e agentes:** manter apenas metadados mínimos de integração por **até 90 períodos de 24h** após evento terminal aplicável, sem reinício do relógio por correção tardia. Agentes podem diagnosticar e operar somente dentro de permissões/playbooks homologados; não certificam por si a entrega oficial.

**Não adotadas:** alternativa A — depender de único armazenamento central compartilhado como única condição de recepção; alternativa C — usar confirmação intermediária HTTP/fluxo e reenvio cego.

## Limites da decisão

Permanecem sem definição/homologação: geração e autenticidade do ID, chave idempotente e sua retenção; garantias de consistência entre bases independentes; ordem e concorrência entre recepção/entrega/expiração/descarte; endpoints e schemas; autoridade de consulta CRM; métricas, latências, limites, autenticação, filas, recuperação, armazenamento, custos e cenários de teste. Estados lógicos da proposta são **candidatos**, não enums finais.

**Status:** AUT-P01 a AUT-P10 aprovadas B; **RV-CT01 e RV-CT02 alternativas B aprovadas exclusivamente como diretrizes documentais**, ambas com contratos técnicos definitivos ainda abertos. **RV-CT03 a RV-CT08 pendentes**. **P0-DOC03 e RF-PEND-01 ABERTOS; RF-PEND-04 NÃO HOMOLOGADA; AGI-11-RB01 DESABILITADO.** Não foram autorizados programação, testes, infraestrutura, compras, implantação nem operação.

Referências: [proposta RV-CT02](P0-DOC03-RV-CT02-RECEPCAO-IDEMPOTENCIA-CONFIRMACAO-CRM-PROPOSTA-2026-11-04.md); [RV-CT01 B](P0-DOC03-RV-CT01-B-APROVADA-2026-11-04.md); [AUT-P05 B](AUT-P05-aprovacao-alternativa-B-2026-11-04.md); [AUT-P06 B](AUT-P06-B-aprovada-2026-11-04.md); [IN127 R01–R06](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-127-R01-R06-ratificacoes-2026-11-03.md); [IN-148](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-148-reconciliacao-inteligente-leads.md); [IN-149](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-149-protocolo-entrega-confirmacao-leads.md).
