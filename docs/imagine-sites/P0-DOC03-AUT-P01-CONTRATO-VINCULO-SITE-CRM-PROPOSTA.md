# P0-DOC03 / AUT-P01 — Contrato de autoridade do vínculo Site × CRM

> **DECISÃO POSTERIOR RATIFICADA:** o responsável aprovou expressamente a **Alternativa B**. A ratificação vigente está em [AUT-P01 — Ratificação da alternativa B](P0-DOC03-AUT-P01-RATIFICACAO-ALTERNATIVA-B-2026-11-03.md). O conteúdo abaixo preserva a proposta histórica e sua comparação A/B/C; eventuais expressões "não ratificada" abaixo referem-se ao **estado anterior desta proposta**, não ao estado atual da AUT-P01. Parâmetros detalhados e homologação seguem pendentes.


**STATUS:** PROPOSTA DOCUMENTAL PARA DELIBERAÇÃO, **NÃO RATIFICADA**.  
**Ramo de planejamento:** Imagine Sites — Portal Imagine — CRM Imagine — Captação principal e de contingência.  
**P0-DOC03:** ABERTO. **RF-PEND-01:** ABERTA. **RF-PEND-04:** homologação técnica PENDENTE.  
**Fase:** planejamento apenas. **Sem programação, testes, alterações operacionais, homologação de fornecedores ou implantação. AGI-11-RB01 DESABILITADO.**

## 1. Problema concreto

O cliente pode vincular um site à conta CRM já autorizada ou solicitar a criação de outra conta. Alterações posteriores de vínculo exigem chamado e execução autorizada pela Imagine. O backend de captação, inclusive sua rota de contingência, deve rejeitar submissões de formulários **sem vínculo/destino CRM válido e autorizado**, mesmo quando frontend/cache contém versão antiga. Porém indisponibilidade temporária do CRM que **permanece autorizado** não deve impedir, por si só, a recepção durável e fila de entrega conforme IN127-R01–R05.

Falta decidir **qual serviço mantém o estado oficial do vínculo** e como as duas APIs o consultam com segurança, sem confundir autorização, elegibilidade comercial da cortesia e disponibilidade do CRM.

## 2. Regras existentes confirmadas (NÃO são novas escolhas)

- **IN-122:** cliente escolhe conta nova ou existente; múltiplos sites podem usar uma conta ou contas diferentes; troca de vínculo via chamado e ação Imagine gratuita; migração de dados não está incluída; reavaliar benefícios de ambas as contas.
- **IN-123:** Portal exibe vínculo e estado da integração, abre chamado e acompanha histórico; autorizações e informações de outras empresas são restritas.
- **IN-124:** API principal e contingência persistem submissões duravelmente e entregam ao CRM; vinculação/identidade imutável por submissão e sem redirecionamento silencioso para outra conta.
- **IN-119/120/COM-08:** elegibilidade da cortesia Básico, acesso operacional ao CRM, situação da assinatura paga, suspensão financeira de site e autorização de captação **não devem ser colapsados em um booleano único**.
- **IN127-R01–R06:** T0 começa na recepção durável; 432h contínuas, descarte e reconciliação controlados; **sem destino oficialmente autorizado, nenhuma recepção de formulário é permitida**; site estático pode continuar publicado.
- **IN-149:** CRM é a autoridade sobre persistência efetiva da entrega de lead; mero retorno técnico do orquestrador não é confirmação comercial.

## 3. Escolha ainda pendente: como a Captação obtém a autorização vigente

### Alternativa A — Consulta online autoritativa para toda submissão

A API principal e a API de contingência **consultam sempre** a autoridade do vínculo no momento de aceitar cada formulário. Ausência ou incerteza de resposta = **falha fechada**, sem receber payload como lead.

**Vantagens:** revogação mais direta, modelo de consistência simples.  
**Desvantagens:** adiciona dependência de disponibilidade e latência da autoridade a cada envio, prejudicando a resiliência de duas APIs independentes.

### Alternativa B — Registro oficial versionado + decisão local segura em ambas as APIs (**RECOMENDADA, SUJEITA À RATIFICAÇÃO**)

A autoridade de vínculo/site no Portal registra **estados versionados e assinados/autenticados**; os serviços de Captação mantêm réplica **limitada aos dados de autorização** e verificam localmente cada aceitação.

A atualização, troca e revogação de vínculo são propagadas por mecanismo autenticado, com ordenação, revisão de versão e **barreira de segurança na revogação**:

1. O comando de revogar/bloquear entra em estado **EM_REVOGAÇÃO**; as duas rotas precisam impedir novas aceitações para o vínculo afetado de modo comprovável.
2. Se uma rota não puder receber/confirmar o bloqueio, ela deverá ser **retirada do atendimento daquele vínculo** ou negar novas aceitações até a reconciliação. **Não** declarar revogação plenamente aplicada enquanto alguma rota ainda puder aceitar usando autorização anterior.
3. Nenhuma réplica ou snapshot vencido, sem integridade, sem versão ou contraditório poderá fundamentar uma nova recepção.
4. Indisponibilidade do **CRM de destino** é diferente de indisponibilidade da **autoridade do vínculo**. Quando a autorização local **continua válida e verificável**, a Captação pode armazenar duravelmente na fila IN-127 mesmo se o CRM estiver temporariamente fora do ar.
5. Ausência de evidência de revogação **não é prova universal** de autorização vigente: o critério de expiração/frescor, proteção contra perda de eventos e reconciliação de réplicas devem ser fechados antes de implementação. Se não for possível comprovar esses requisitos, a rota afetada deverá falhar fechada.
6. A mudança de vínculo é atômica no plano de autorização: não pode haver janela em que o mesmo site/formulário envie novos leads para conta errada; a confirmação de ativação da nova conta depende de validação formal.
7. Não criar canal alternativo implícito para e-mail, Portal ou outra conta CRM. Restringir a versão do vínculo à empresa/site/formulário e ao destino correto.

**Vantagens:** resiliência operacional com separação entre frontend do Portal e APIs de captação; maior controle de revogação/versão.  
**Riscos/custos:** exige protocolo seguro de sincronização, barreira de revogação e observabilidade; ainda requer critérios concretos de consistência, limite de frescor, recuperação e prova de bloqueio. **Não prometer revogação instantânea sem implementação que a comprove.**

### Alternativa C — Vínculo informado por formulário/frontend

O site envia o `crm_account_id` e a API o utiliza como destino.

**Não recomendada / incompatível com IN127-R06:** dados de frontend e páginas antigas não são autoridade de autorização; criaria risco de envio entre empresas.

## 4. Autoridades de domínio — proposta de separação (a confirmar em ratificação)

| Fato | Titular lógico proposto | Não pode fazê-lo unilateralmente |
|---|---|---|
| Contrato, produto e situação de site | Portal / domínio de contratação de Sites | n8n ou CRM por evento intermediário |
| Solicitação de vínculo/troca | Cliente/colaborador com permissão, registrado no Portal e chamado | Interface pública ou agente autônomo |
| Identidade oficial de vínculo site–CRM | Registro versionado sob governança Portal, em coordenação com CRM | Página estática, cache do navegador ou n8n |
| Existência e titularidade da conta CRM | CRM Imagine (serviço oficial) | Portal por simples digitação de ID |
| Situação operacional de acesso CRM | CRM Imagine (serviço oficial) | Portal por mera inferência financeira |
| Cortesia Básico | Regra comercial por contrato elegível + confirmação do estado aplicável CRM | Captação deduzir autorização só por existência da cortesia |
| Aceitação durável T0 | Backend de Captação (principal ou contingência) | Portal ou workflow |
| Confirmação da entrega | CRM Imagine | HTTP 202, n8n e worker de transporte |

Esta tabela **não aprova** criação de um novo banco central, implantação física ou API em produção; apenas propõe a separação de domínio.

## 5. Contrato lógico AUT-P01-CT01 — candidato, NÃO implementado

| Campo/classe | Propósito | Regra obrigatória proposta |
|---|---|---|
| `site_id` | Identificar site | ID persistente, não domínio público |
| `empresa_id` | Proteger tenancy | Isolamento e autorização por tenant |
| `form_id` + `form_version` | Identificar formulário e versão | Não aceitar rota/formulário não habilitado |
| `crm_account_id` | Destinatário autorizado | Conta oficial validada pelo CRM |
| `link_revision` | Versão monotônica do vínculo | Rejeitar estados antigos/reordenados |
| `authorization_ref` | Evidência de autorização | Escopo e responsável rastreáveis |
| `capture_permission_state` | Elegibilidade específica de captação | Distinta de cortesia e disponibilidade CRM |
| `effective_at` / `revoked_at` | Ciclo de vida | Aplicar transições sem janela indevida |
| `integrity_ref` | Integridade/autenticidade da decisão | Proteger contra falsificação/replay |
| `confirmation_refs` | Comprovação das duas rotas | Exigir confirmação de bloqueio ou fail-closed |
| `policy_version` | Rastreabilidade normativa | Política ratificada vinculada à decisão |

Os nomes dos campos são **proposta documental**, não schema ou endpoints aprovados.

## 6. Casos-limite obrigatórios para a especificação

| Situação | Tratamento esperado pelas normas já aprovadas | Detalhe ainda pendente |
|---|---|---|
| Site publicado sem CRM configurado | Site estático pode existir, formulário não recebe | UX/estados e comunicação da indisponibilidade |
| Cliente opta por CRM existente | Validar autorização e conta correta antes de ativar | Provas e contrato de vínculo |
| Duas rotas recebem mesma submissão após failover | Receber uma vez logicamente; T0 único | Chave/concorrência/consistência |
| Revogação de vínculo enquanto rota está isolada | Rota sem prova segura não pode aceitar novos dados | Protocolo de barreira/reconciliação |
| CRM está temporariamente indisponível mas vínculo válido | Captar duravelmente, respeitando 432h IN-127 | Condição de frescor da autorização |
| Mudança de CRM após submissões pendentes | Não reenviar automaticamente à conta nova; preservar escopo original | Aprovação de exceções e tratamento de revogação |
| Site bloqueado por inadimplência; CRM pago permanece ativo | Não bloquear CRM pago apenas pelo site; rever captação conforme estado autorizado | Matriz de políticas financeiras e captação |
| Site antigo em cache tenta formulário revogado | Rejeitar no backend, sem T0 nem sucesso falso | API/resposta/segurança |

## 7. Dependências ainda abertas

- **AUT-P01:** escolher A, B ou C; se B, aprovar condicionantes de fail-closed, barreira de revogação e estado de sincronização.
- **AUT-P02:** regras independentes de autorização de captação, cortesia Básico, assinatura paga, suspensão e estado contratual.
- **AUT-P03:** transações de troca e efeitos da alteração simultânea.
- **AUT-P05:** consulta de confirmação oficial do CRM e idempotência.
- **AUT-P06:** submissões anteriormente aceitas quando houver perda de autorização.
- **AUT-P07:** retenção/descarte e backups.
- **RF-PEND-01:** corpus de aprovação AGI ainda incompleto, fontes históricas em branches divergentes.
- **RF-PEND-04:** normas da IN-127 ratificadas, homologação técnica futura.

## 8. Pedido de deliberação — AUT-P01

**Recomendação para decisão expressa:** **Alternativa B**, com registro oficial versionado e autenticação forte, decisão segura local em cada API, propagação/reconciliação, e bloqueio comprovado ou fail-closed em caso de revogação ou incerteza de autorização. O prazo concreto de validade das autorizações, protocolo de sincronização e componentes físicos **não devem ser presumidos como já ratificados**; serão detalhados após a escolha.

**Status ao fim:** proposta documentada, nenhuma alternativa ainda ratificada. Documento foi salvo com autorização geral de **documentar decisões aprovadas e suas pendências**, mas não é decisão implementável.

**Segurança/execução:** nenhum código, teste, implantação ou contratação autorizado; AGI-11-RB01 **DESABILITADO**.
