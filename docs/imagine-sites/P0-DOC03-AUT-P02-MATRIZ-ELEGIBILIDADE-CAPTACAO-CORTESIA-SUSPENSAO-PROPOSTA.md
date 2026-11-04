# P0-DOC03 / AUT-P02 — Autorização de captação × cortesia CRM × assinatura paga × suspensão

**STATUS:** **PROPOSTA PARA DELIBERAÇÃO**, não ratificada; não adotar alternativa sugerida por inferência.  
**Estado precedente:** **AUT-P01 alternativa B RATIFICADA**; vínculo Site × CRM com registro versionado, autenticação nas duas APIs, proteção contra estado obsoleto, barreira de revogação e falha fechada.  
**Decisões preservadas:** IN127-R01 a R06; IN-119/120/122, PORTAL-COM-08, IN-124/149 e demais contratos vigentes.  
**AUT-P03 a AUT-P10:** continuam PENDENTES. **P0-DOC03/RF-PEND-01 abertos; RF-PEND-04 não homologada.**  
**Execução:** SOMENTE DOCUMENTAÇÃO; não programar, testar, implantar, homologar, contratar ou ativar AGI-11-RB01.

## 1. Problema específico

A conta CRM pode continuar paga e ativa mesmo que determinado site esteja suspenso; o CRM Básico pode ser cortesia quando sustentado por site elegível; a cortesia de uma conta pode ser sustentada por mais de um site; e o CRM pode estar temporariamente indisponível por falha técnica sem ter perdido autorização. Uma conta pode ter acesso restrito após suspensão ou cancelamento por motivos diferentes. Por isso, **não é seguro concluir o direito de um formulário receber leads somente pelo rótulo "CRM ativo", "site ativo", "plano Básico" ou "cortesia sim"**.

A AUT-P02 definirá a relação normativa entre os estados sem reabrir políticas financeiras. A decisão AUT-P01 já exige que **cada backend comprove a autorização de captação vigente**.

## 2. Regras existentes, já aprovadas (não propostas novas)

1. **IN-122:** cada novo site pode vincular conta CRM própria ou existente autorizada; múltiplos sites na mesma conta concedem **no máximo uma** cortesia de Básico; outros sites elegíveis mantêm benefício se um for cancelado.
2. **IN-119/120/COM-08:** cortesia Básico depende do contrato elegível e de sua condição; perda do último vínculo elegível tem transição específica de 30 dias; casos de cancelamento comum e cancelamento posterior à inadimplência possuem regime operacional diferente. Não converter fim de cortesia em CRM pago sem contratação expressa.
3. **COM-08:** após tolerância de **7 dias corridos** e suspensão efetiva por inadimplência do site, se ele for o único fundamento da cortesia, **bloquear acesso operacional ao CRM Básico cortesia**; não bloquear CRM com assinatura paga própria ou com outro site elegível.
4. **IN127-R06:** site estático pode existir sem CRM; formulário público sem destino vinculado e autorizado **não** pode receber payload. CRM autorizado temporariamente indisponível pode usar recepção durável, sob R01-R05.
5. **AUT-P01 B:** o resultado autorizado deve ser versionado, autenticado, consistente em ambas as APIs e impedir novas aceitações com estado antigo/revogado.
6. **IN-124/149:** a entrega efetiva tem confirmação oficial do CRM, não do workflow; os dados já aceitos mantêm referência ao destino original, sem redirecionamento automático.

## 3. Alternativas submetidas à aprovação

### Alternativa A — Estado único de habilitação comercial

Um indicador único `crm_habilitado` resumiria contrato do site, pagamento, cortesia, disponibilidade do CRM, vínculo e permissão de captação.

**Não recomendada:** conflita com a autonomia do CRM pago e confunde indisponibilidade técnica com revogação/legitimidade.

### Alternativa B — Matriz de estados independentes e decisão de captação versionada (**RECOMENDADA, AINDA NÃO RATIFICADA**)

Registrar e avaliar separadamente, a partir de fontes titulares e dados oficiais:

| Dimensão | Pergunta obrigatória | Proprietário lógico a confirmar no contrato final |
|---|---|---|
| `site_contract_state` | O contrato do site permite a funcionalidade do formulário agora? | Portal / contrato de Sites |
| `site_operational_state` | O site está público, suspenso, cancelado ou em transição? | Sistema competente de Sites/Portal |
| `form_activation_state` | A versão atual do formulário está autorizada, publicada e apta à captação? | Sites + políticas do Portal |
| `crm_link_state` | Qual conta CRM está vinculada e com autorização/versão válida? | Registro AUT-P01 validado com CRM |
| `crm_account_rights_state` | A conta CRM permite receber novos registros oriundos desta integração? | CRM Imagine / contrato aplicável |
| `crm_paid_subscription_state` | Há assinatura paga independente e vigente? | CRM/financeiro oficial |
| `basic_courtesy_state` | Existe benefício Básico concedido e qual site o sustenta? | Contratos elegíveis Portal + CRM |
| `crm_operational_connectivity` | O CRM está tecnicamente acessível agora? | Telemetria operacional; **não concede direitos** |
| `capture_authorization_state` | As condições cumulativas permitem aceitar esta submissão neste site/formulário? | Decisão autorizada e versionada sob AUT-P01, com fontes oficiais verificáveis |

**Modelo proposto:** a permissão de captação exige contrato/formulário aptos, vínculo oficial à conta CRM, autorização vigente para o destino, integridade/versão comprováveis e ausência de bloqueio aplicável. **Não exige cortesia do Básico** se houver CRM contratado e autorizado por outra modalidade. A conectividade momentânea do CRM **não decide** a autorização; indisponibilidade temporária permite fila IN-127 quando os demais direitos se mantêm.

**Garantias da alternativa B:**
- Disponibilidade do serviço e **direito comercial/contratual de recebimento** são estados distintos.
- Alteração de estados contratuais, bloqueio, cancelamento ou revogação que retire a autorização deve produzir nova revisão da decisão AUT-P01, inclusive bloqueio seguro da API contingente.
- **Não permitir** que evento financeiro não conciliado, webhook isolado ou simples atraso técnico produza suspensão indevida; utilizar estados oficiais.
- **Não conceder captação indevida** apenas porque um CRM pago, cortesia ou conta existe.
- Nas duas APIs, usar `capture_authorization_state` com referência e revisão oficial; não derivar a decisão localmente de informação enviada pelo navegador.
- **Não apagar** leads já entregues ao CRM, não alterar T0 de submissões já aceitas e não redirecionar pendências após troca de vínculo.
- Sem alteração automática de contrato, plano, benefício ou cobrança pelo backend de captação.

### Alternativa C — Captar sempre que a conta CRM existir e não tiver sido excluída

Mesmo site suspenso ou contrato inativo poderia continuar recebendo dados enquanto a conta CRM ainda existir.

**Não recomendada:** conta existente não comprova autorização de novo recebimento; risco de finalidade, contrato e segregação.

## 4. Cenários e tratamentos de referência

Os itens abaixo distinguem **regra já aprovada** e **consequência normativa proposta**. Todos pressupõem autorização válida e ausência de outro bloqueio relevante.

| Cenário | Regra que permanece aprovada | Resultado de captação sugerido pela B (PENDENTE AUT-P02) |
|---|---|---|
| Site regular, CRM Básico cortesia vigente e integração válida | Cortesia permitida, uma por conta | **Permitir** |
| Site regular, CRM Básico pago ou plano superior ativo | Contratação CRM é independente da cortesia | **Permitir**, sem exigir cortesia |
| Site regular em campanha **sem cortesia**, CRM pago próprio válido | Não criar benefício fictício | **Permitir** se contratado/autorizado |
| Site regular, **sem** qualquer CRM de destino | R06 autoriza publicar estático, não receber formulário | **Bloquear**, sem payload nem T0 |
| Site em atraso, ainda dentro dos 7 dias e **não suspenso** | COM-08 preserva operação do site no prazo de tolerância | **Preservar captação** enquanto demais condições oficiais permanecerem válidas |
| Site **efetivamente suspenso** por inadimplência; CRM Básico cortesia **exclusivamente** sustentado por ele | Acesso operacional ao CRM cortesia deve ficar suspenso | **Bloquear novas captações por esse site** e respeitar suspensão CRM |
| Site suspenso; CRM pago próprio da conta continua ativo | Não suspender CRM pago por atraso do site | **Não aceitar formulário do site suspenso**, sem afetar o CRM pago ou integrações de outros sites autorizados |
| Um entre vários sites da mesma conta foi suspenso; outro continua elegível | Outro site pode manter a cortesia na conta | **Bloquear somente novos formulários do site suspenso**; preservar os do(s) site(s) válidos |
| Cancelamento comum do último site elegível, cortesia em transição de 30 dias | Condições de funcionamento CRM seguem IN-119/120 | **Não reativar formulário do site cancelado**; transição CRM não restaura automaticamente captação do site |
| Cancelamento do último site após suspensão por inadimplência | Acesso CRM cortesia permanece restrito durante a transição específica | **Bloquear captação do site cancelado**; preservar portabilidade e dados conforme contratos |
| Conta CRM autorizada temporariamente **fora do ar**, site/formulário e direito de receber vigentes | IN127-R01–R06 aprovadas | **Permitir recepção durável e fila** respeitando 432h e autorização AUT-P01 |
| Autorização revogada, conta não pertencente ao titular, sincronização incerta | IN127-R06 e AUT-P01 B | **Bloquear**, sem T0; exigir prova vigente |
| CRM conta suspensa/restrita por motivo próprio não financeiro (segurança/abuso) | Não presumir permissão de receber novos registros | **Bloquear até autoridade oficial confirmar direito**, independentemente de conectividade |

**Importante:** o marco exato em que um status de site suspenso/cancelado retira autorização de captação e os detalhes de transição de dados já captados pertencem a AUT-P02/AUT-P06; não converter esta proposta de cenários em implementação sem ratificação.

## 5. Contrato lógico proposto AUT-P02-CT01 (não aprovado)

Cada decisão de autorização a ser transmitida ao mecanismo AUT-P01 deve ser auditável, vinculada a `empresa_id`, `site_id`, `form_id/form_version` e `crm_account_id`, e incluir:

- `site_contract_ref` / `contract_revision`: evidência oficial de função contratada.
- `crm_link_revision`: versão da autorização AUT-P01.
- `crm_receiving_rights_ref`: comprovação oficial do direito de receber dados.
- `basic_courtesy_ref` (quando aplicável): benefício com fonte e contrato(s) sustentadores.
- `crm_paid_subscription_ref` (quando aplicável): direito pago independente.
- `capture_authorization_state`: `AUTHORIZED`, `DENIED`, `UNCERTAIN` (códigos candidatos).
- `denial_reason_code`: classe minimizada; não expor dados pessoais nos logs.
- `revision`, `issued_at`, `valid_until`, `integrity_ref`, `source_evidence_refs`: proteção contra decisão obsoleta/falsificada.
- `revocation_barrier_ref`: evidência de aplicação de bloqueio/fail-closed em ambas as rotas.

**Atenção:** enums, TTL, tempos máximos e formato criptográfico não estão aprovados. `UNCERTAIN` sempre implica **não aceitar novo formulário** (já estabelecido pelo AUT-P01 B).

## 6. Pendências remanescentes não aprovadas por inferência

1. Quais estados financeiros exatos impedem captação e quando ocorre a **suspensão oficial** do site, respeitando a tolerância e reconciliação de pagamento.
2. Se e como os contratos de sites diferenciam recursos públicos, captação e site estático durante bloqueios contratuais.
3. Contrato para CRM atestar direito de receber novos leads durante suspensão restrita, portabilidade e eventual transição; não presumir que `login permitido` seja `receber_leads permitido`.
4. Sincronização dos eventos dos domínios financeiros e Portal/CRM, sem desbloqueio por webhook isolado.
5. AUT-P03: procedimento de mudança de vínculo. AUT-P05: confirmação oficial CRM. AUT-P06: submissões já captadas quando autorização muda.
6. Limites e critérios de frescor, barreira de revogação, topologia e segurança AUT-P01 ainda a especificar e aprovar.

## 7. Pedido de ratificação — somente AUT-P02

**Recomendação:** aprovar a **alternativa B**, com **matriz de estados independentes e autorização de captação própria, cumulativa, versionada e verificável**, preservando a independência do CRM pago, a cortesia por elegibilidade, o acesso restrito em suspensão e a diferenciação entre indisponibilidade técnica e perda de direito.

**Importante:** o resultado de captação sugerido na tabela de cenários faz parte da proposta B e **não será tratado como aprovado sem ratificação expressa**. AUT-P03 a AUT-P10 permanecem pendentes.

**Status final:** AUT-P01 B RATIFICADA; AUT-P02 APRESENTADA E PENDENTE; P0-DOC03/RF-PEND-01 ABERTOS; AGI-11-RB01 DESABILITADO.
