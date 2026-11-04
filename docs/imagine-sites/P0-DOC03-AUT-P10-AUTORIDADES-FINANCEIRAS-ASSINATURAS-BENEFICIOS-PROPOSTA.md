# P0-DOC03 / AUT-P10 — Autoridades financeiras, assinatura de site, benefício CRM e liberação de serviços

> **DECISÃO POSTERIOR (04/11/2026):** a alternativa **B** foi aprovada. Consultar [ratificação normativa AUT-P10](AUT-P10-B-aprovada-2026-11-04.md). Esta minuta preserva alternativas e trechos marcados "pendente/não ratificada" como **histórico anterior** à decisão. Contratos técnicos ainda não homologados; P0-DOC03/RF-PEND-01 permanecem abertos.


**ESTADO: PROPOSTA PARA DELIBERAÇÃO — AUT-P10 NÃO RATIFICADA.**  
**Data:** 04/11/2026. **Escopo:** Portal Imagine / Imagine Sites / CRM Imagine, orquestração dos agentes e integrações financeiras.  
**Precedências:** AUT-P01 a AUT-P09, alternativas B aprovadas; PORTAL-D01/D02/D04, COM-06/07/08/09, política posterior Pix 5%/cartão 3x; IN-119–122; AUT-P02, AUT-P03, AUT-P08 e AUT-P09; demais decisões comerciais efetivamente aprovadas.  
**Fronteiras:** AUT-P10 em deliberação; P0-DOC03 e RF-PEND-01 **ABERTOS**; RF-PEND-04 **NÃO HOMOLOGADA**. Nada de programação, teste de provedor, emissão financeira, contratação, deploy, alteração de assinaturas, crédito ou homologação. **AGI-11-RB01 DESABILITADO.**

## 1. Questão e risco

O fato de o cliente clicar em “pagar”, o PSP devolver um HTTP 200/202, o banco aceitar uma instrução, um webhook chegar ou o n8n concluir um workflow **não comprova isoladamente que um pagamento devido foi efetivamente liquidado, reconhecido e conciliado**. Da mesma forma, a simples existência de um site em produção não significa que toda conta CRM vinculada esteja apta à cortesia.

A AUT-P10 deve estabelecer **quem atesta o fato financeiro**, **quem decide o estado comercial do contrato do site**, **quem decide a cortesia do CRM Básico**, **quem controla assinatura CRM paga** e **qual conjunto de provas permite liberar produção, suspender/reativar serviços ou executar orçamentos**.

## 2. Regras vigentes — não colocar em nova votação

1. **Contratos independentes:** o Portal Imagine administra contrato e cobrança do site. O CRM Imagine administra sua assinatura paga e upgrades; inadimplência/cancelamento do site **não** cancela automaticamente um CRM pago vigente (D04, COM-08, AUT-P02).
2. **Implantação/compra — política posterior:** Pix **à vista com 5% de desconto** ou cartão de crédito em **até 3x sem desconto Pix**. Não oferecer Pix parcelado como modalidade Imagine (PORTAL-COM-POLITICA-VIGENTE).
3. **Mensalidades do site:** Pix Automático **5% de desconto** sobre mensalidade elegível, cartão recorrente sem esse desconto. O mandato bancário do Pix Automático é diferente do aceite contratual; cada ciclo precisa ser confirmado/conciliado. Não resgatar históricos de 10%/20%/4x como regra vigente.
4. **Primeira mensalidade:** padrão **90 dias após contratação**, administrável por serviço/campanha e vinculada à oferta aceita; promoções por N mensalidades contam do primeiro ciclo devido, e reajuste anual tem relógio separado após 12 meses (COM-06). Nenhuma cobrança antes do marco contratado.
5. **Vencimento em dia não útil:** tentar a cobrança na data contratada se meio e PSP permitirem; se não liquidar em dia não útil, permitir regularizar **até o próximo dia útil bancário aplicável sem juros/multa**. Vencimento contratual e data-limite sem encargos são fatos distintos, sem mudança do dia de referência dos ciclos posteriores (COM-07).
6. **Tolerância por inadimplência do site:** política aprovada de **7 dias corridos antes de suspensão efetiva** (COM-08), com marco preciso de contagem após eventual tolerância de dia não útil ainda a consolidar. **Não suspender** por webhook ausente/duplicado, simples comprovante ainda em análise, status inconclusivo de banco ou liquidação em curso sem reconciliação.
7. **Cortesia Básico:** uma cortesia por conta CRM, desde que sustentada por ao menos um site/contrato vigente e elegível, conforme produto/campanha/termos. Diversos sites na mesma conta não acumulam benefícios; contas CRM diferentes podem ter elegibilidade própria. Reavaliar todas as ligações afetadas após alteração, suspensão ou cancelamento (IN-119/122).
8. **Site efetivamente suspenso por inadimplência:** se for o **único** fundamento da cortesia Básico, suspender acesso operacional desse CRM cortesia, preservando dados e acesso restrito pertinente; **outro site elegível** sustenta a cortesia; **assinatura CRM paga** permanece independente (COM-08).
9. **Cancelamento do último site elegível e transição:** por cancelamento **comum**, CRM cortesia segue operacional em 30 dias; se foi **suspenso por inadimplência e depois formalmente cancelado**, segue **30 dias restrito**, com exportação/contratação paga possíveis. A transição começa no cancelamento formal do último vínculo, não no aviso nem na suspensão (COM-09).
10. **Término da cortesia:** não converter em assinatura CRM paga sem contratação e aceite expressos, nem gerar cobrança automática; preservar portabilidade. Retenção de 120 dias se aplica após **cancelamento formal da própria conta CRM**, não após mera suspensão do site (IN-119/120, COM-09).
11. **Orçamentos adicionais:** até R$ 270 à vista; acima, opção à vista ou até 3x **somente em cartão**. Execução após aceite válido e recebimento integral à vista ou confirmação financeira adequada da primeira parcela, conforme regras vigentes de D02 e da adquirente; não criar ordem só porque a promessa de pagamento foi enviada.
12. **Entrega/publicação diferentes da liberação comercial:** AUT-P04/AUT-P08 e COM-13 exigem versão aprovada, publicação verificada e checklist para ENTREGUE. Pagamento/contrato elegível pode ser condição para liberar a produção conforme a oferta, **mas comprovar pagamento sozinho não substitui aceite de versão, segurança, DNS, LGPD ou o evento ENTREGUE**.
13. **CRM e captação:** contrato de site financeiramente regular não concede por si destino CRM autorizado ou direito de receber formulários; respeitar AUT-P01–P06 e IN127-R06. Site estático elegível pode ser publicado sem CRM mantendo captação bloqueada.
14. **Sem automatização irrestrita de agentes:** AUT-P09 exige operações tipadas, autorizações e uma única aprovação humana para intervenções críticas quando necessária. Nenhuma IA inventa quitação, concede descontos não contratados ou muda unilateralmente contratos.

## 3. Alternativas AUT-P10

### A — Webhook ou agente como verdade financeira

Considerar “pago” qualquer retorno positivo do PSP, evento webhook ou conclusão de uma automação; liberar site/benefício por essa marcação direta.

**Não recomendada:** resposta intermediária, duplicidade de eventos, atraso, estorno, pagamento para outro ciclo e falhas de conciliação podem produzir liberação/ suspensão indevida.

### B — Fonte de liquidação do PSP/banco + razão financeira conciliada + decisões separadas por produto (RECOMENDADA; NÃO RATIFICADA)

**Modelo de autoridades:**

| Fato ou decisão | Autoridade que deve comprovar/decidir | O que NÃO constitui prova |
|---|---|---|
| Pagamento, liquidação, estorno, chargeback, devolução | Banco/PSP/adquirente como fonte do fato de pagamento, **conciliado pelo financeiro oficial da Imagine** ao título/ciclo/contrato correto | Só webhook, captura de tela, envio de comprovante, execução n8n ou mandato Pix |
| Preço, desconto, cobrança, vencimento, condições de implantação/site | Versão de oferta/contrato aceita + registro financeiro/contratual oficial do **Portal Imagine** | Preço histórico de outro produto, promoção alterada retroativamente, inferência de IA |
| Direito de iniciar produção de site/adicional | Portão comercial do Portal, a partir de contratação aceita e prova de pagamento **quando exigida pela oferta/serviço**; demais portões técnicos e jurídicos são independentes | Pagamento iniciado, inscrição de cobrança, evento financeiro sem validação |
| Suspensão/reativação do site | Estado oficial do contrato de site no Portal, com pagamento conciliado, tolerância aplicável e condições de notificação/segurança | Inadimplência presumida por falha temporária de provedor |
| Benefício CRM Básico cortesia | Autoridade de elegibilidade ligada a contratos/sites do Portal, propagada e **validada pelo CRM** para a conta correta | Um site isolado sem reavaliar outros vínculos; evento antigo/duplicado |
| Assinatura CRM paga | Contrato/assinatura e razão financeira **próprios do CRM**, com prova financeira aplicável | Status de pagamento, suspensão ou cancelamento de um site |
| Aceite de orçamento, versão de site, publicação e entrega | Sistemas titulares da decisão comercial do cliente, Portal/versionamento e validador COM-13 | Pagamento só por si, conclusão do agente, callback de publicador |

**Regras de desenho da B:**

1. **Contrato e cobrança identificados:** cada instalação, orçamento, assinatura e competência mensal tem identificador estável e versão das condições aceitas; pagamentos e reversões são reconciliados ao título correto, evitando cruzar cliente, produto, tenant, site ou mês.
2. **Estados financeiros distintos:** “cobrança criada”, “autorização recorrente válida”, “pagamento solicitado”, “em processamento”, “liquidado/conciliado”, “falhou”, “contestação”, “estornado/devolvido” e “inconclusivo” não são sinônimos (rótulos candidatos, não enums ratificados). Resultado autoritativo não nasce de um evento técnico isolado.
3. **Conciliação idempotente:** webhook duplicado, fora de ordem ou atrasado, mudança de meio, retries, cancelamento de Pix Automático e retorno inconsistente não criam dupla quitação/cobrança, nem alteram contrato/cortesia por engano. Estado inconclusivo demanda consulta e reconciliação à fonte oficial antes de efeito desfavorável.
4. **Gates por operação:** a implantação e os adicionais seguem seus requisitos contratuais de pagamento e aceite; só então pode ser autorizada a ordem de serviço. **Produzir** ≠ **publicar** ≠ **entregar**, mantendo AUT-P08. Para implantação, critérios de parcela e marco exatos devem ser especificados pelo contrato vigente; **não inventar nesta AUT-P10** liberação por mera autorização de cartão.
5. **Pagamento em dia não útil:** data de vencimento e prazo permitido de regularização são mantidos separadamente. Até o próximo dia útil aplicável após falha de liquidação, não classificar inadimplência punitiva nem iniciar suspensão; depois aplicar a tolerância própria do site, com marco a ratificar tecnicamente.
6. **Tolerância 7 dias:** avisos e elegibilidade de suspensão derivam do estado financeiro/contratual oficialmente reconciliado; não executar suspensão por resposta perdida, demora bancária ou cronograma de notificação não cumprido.
7. **Cortesia multi-site:** recalcular por **todas** as fontes elegíveis da mesma conta, produto/campanha/contrato e revisão do vínculo. Manter uma cortesia por conta, sem créditos duplicados. Troca de vínculo segue chamado AUT-P03; não transferir benefícios sem validações.
8. **Bloqueio da cortesia:** suspender só quando houver evento contratual válido no site e nenhuma outra elegibilidade ativa, preservando conta e dados. Um CRM pago válido continua independente.
9. **Duas transições de 30 dias:** no cancelamento comum do último site, operação normal por 30 dias; no cancelamento por inadimplência precedido de suspensão, acesso restrito nos 30 dias. Não aplicar automaticamente a segunda modalidade ao cancelamento comum.
10. **Retorno ao benefício:** reativar a cortesia somente com restauração comprovada do contrato/site e elegibilidade, após conciliar pagamentos e verificação de bloqueios; evitar dupla mudança de estado por callbacks tardios. Reativação do site cancelado possui contrato, taxa e condições próprias ainda pendentes.
11. **Contratação paga expressa:** converter CRM cortesia em pago **somente após escolha/aceite do cliente e aprovação financeira própria**, sem cobrança automática pelo fim do benefício. Preservar exportação/portabilidade e retenção corretas da conta.
12. **Estornos e disputas:** fatos posteriores devem gerar reavaliação de direitos conforme contrato, sem apagar o histórico de liquidação nem realizar suspensão retroativa automática, com revisão da classificação, notificações e alçadas apropriadas.
13. **Falha de sincronização:** se Portal e CRM discordarem, suspender **novos efeitos baseados em informação não confiável**, consultar estados oficiais e reconciliar; não aplicar indevidamente multa, cancelamento, perda de cortesia ou bloqueio de CRM pago.
14. **Separação de autorização financeira:** agente IA/n8n pode propor avisos, diagnósticos e conciliação sob políticas homologadas, mas não autoriza sozinho novas despesas, emissão extraordinária, descontos fora do contrato, mudança de PSP ou contratação. Uma aprovação humana quando a intervenção crítica a exigir (AUT-P09).
15. **Segurança e auditoria:** registrar origem/proveniência dos eventos, identidade do pagador/cliente sem expor dados sensíveis, contrato/competência, versão de condições, referência oficial da transação, estado conciliado, revisões, avisos, suspensão/reativação e responsável; proteção de segredo bancário e isolamento entre empresas.
16. **Sem permissão por aproximação:** o Portal pode ter pagamentos liquidados, mas isso não substitui o destino CRM autorizado para formulários; CRM pode estar pago, mas isso não isenta o site de suspensão contratual. INT-09/10 de orquestração respeitam autoridades de domínio.

**Vantagens:** reduz dupla cobrança, bloqueio indevido, concessão equivocada de benefício e publicação com pagamento não confirmado; mantém contrato independente de cada serviço e decisões anteriores.
**Desafios:** contratos financeiros tipados, reconciliador entre PSP, Portal e CRM, idempotência, reversões e alçadas; nenhuma tecnologia, PSP ou infraestrutura escolhida nesta decisão.

### C — Um estado comercial único para todo o ecossistema

Usar uma marca “cliente regular” para comandar todos os sites, a cortesia, o CRM pago e a produção, sem contratos independentes.

**Não recomendada / incompatível** com PORTAL-D04, IN-119/122 e AUT-P02: o cliente pode ter site suspenso e CRM pago ativo, ou vários sites sustentando a mesma cortesia.

## 4. Cenários que a alternativa B deve resolver

| Ocorrência | Decisão fundamentada, não inferida |
|---|---|
| Cliente conclui checkout, webhook ainda não confirmado | Estado de pagamento pendente/inconclusivo; validar fonte oficial antes de liberar o efeito dependente |
| Um webhook chega duas vezes | Uma única conciliação no mesmo título/ciclo, sem duplicar cobrança |
| Cartão autorizado mas pagamento não comprovado no marco contratual | Não declarar “quitado” nem liberar efeito que exige recebimento sem prova apropriada |
| Vencimento no sábado, cobrança falha | Respeitar regularização até próximo dia útil bancário sem penalidade conforme COM-07 |
| Site tem atraso dentro dos 7 dias de tolerância | Avisar e acompanhar; não suspender antecipadamente por essa pendência |
| Um dos dois sites elegíveis da mesma conta é suspenso | Se outro sustenta cortesia, CRM Básico não é suspenso por perda desse vínculo |
| Último site elegível é suspenso por inadimplência | Suspensão operacional da cortesia conforme COM-08, sem excluir dados |
| Último site é cancelado normalmente | Transição de 30 dias com CRM Básico operacional |
| Último site suspenso por inadimplência é cancelado formalmente | Transição de 30 dias com acesso CRM restrito e portabilidade/adesão paga |
| Cliente paga CRM Essencial, mas deixa site inadimplente | Plano CRM pago permanece conforme seu próprio contrato; site segue política própria |
| Cliente quer continuar no Básico pago após fim de cortesia | Exigir contratação/aceite expresso; não cobrar automaticamente |
| Pagamento de mensalidade chega fora de ordem ou é estornado | Reconciliar fato financeiro e contrato antes de reativar/suspender novamente |
| Agente conclui publicação técnica mas checklist falhou | Não registrar ENTREGUE; pagamento não dispensa gate técnico/jurídico |
| Site estático sem CRM contratado | Pode publicar quando condições permitirem; formulário continua bloqueado conforme IN127-R06 |

## 5. Candidatos de contrato AUT-P10-CT01 — NÃO HOMOLOGADOS

`company_id`, `site_contract_id`, `site_id`, `crm_account_id`, `offer_version`, `campaign_version`, `payment_title_id`, `billing_cycle_id`, `payment_operation_ref`, `psp_settlement_ref`, `payment_state`, `bank_mandate_ref`, `effective_paid_at`, `due_at`, `grace_until`, `site_status_revision`, `benefit_eligibility_revision`, `benefit_transition_kind`, `crm_paid_subscription_ref`, `incident_ref`, `audit_ref`.

**Não aprovados:** schemas, fontes financeiras concretas, PSP/adquirente, tokens/assinaturas, APIs e frequência de conciliação, limites de atraso/estorno, exact-once, prazos por feriado, disparo de cobrança, configuração de meios manuais, NFS-e, tributação, preços finais, datas exatas de suspensão/cancelamento, homologação e testes. Não iniciar contratação de provedor nem cobrança real.

## 6. Fontes confrontadas e precedências

- [PORTAL-D04 — cobranças recorrentes de sites](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-D04-cobranca-recorrente-sites.md).
- [COM-06 — primeira mensalidade administrável](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-COM-06-inicio-mensalidade-configuravel-por-campanha.md).
- [COM-07 — dias não úteis e vencimentos](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-COM-07-vencimentos-recorrentes-configuraveis.md).
- [COM-08 — suspensão e cortesia](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-COM-08-inadimplencia-site-cortesia-crm-configuravel.md).
- [COM-09 — distinção das transições](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-COM-09-transicao-cortesia-cancelamento-inadimplencia.md).
- [Política posterior 03/11 — Pix 5% e cartão até 3x](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-COM-POLITICA-VIGENTE-PIX-05-CARTAO-3X-2026-11-03.md).
- [IN-119 — benefício Básico](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-119-gestao-cortesia-crm-basico.md).
- [IN-120 — fim da cortesia](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-120-encerramento-cortesia-continuidade-conta.md).
- [IN-122 — múltiplos sites e contas](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-122-multiplos-sites-contas-crm-cortesias.md).
- [PORTAL-D02 — adicionais, versão posterior](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-D02-pagamento-servicos-adicionais.md).
- [AUT-P02 — captação x contratos](AUT-P02-aprovacao-alternativa-B-2026-11-04.md), [AUT-P08 — entrega](AUT-P08-B-aprovada-2026-11-04.md), [AUT-P09 — autonomia](AUT-P09-B-aprovada-2026-11-04.md).

## 7. Pedido de deliberação — somente AUT-P10

**Recomendação:** alternativa B, com **confirmação financeira por fonte de pagamento e conciliação oficial**, decisões do Portal por contrato/site/ciclo, cálculo auditável do benefício Básico por conta CRM e vínculo elegível, assinatura CRM paga independente, tratamentos distintos de suspensão e transição, e gates próprios de produção, publicação e entrega.

**Status:** AUT-P01 a AUT-P09 aprovadas B. **AUT-P10 PENDENTE DE RATIFICAÇÃO**. **P0-DOC03 / RF-PEND-01 ABERTOS; RF-PEND-04 NÃO HOMOLOGADA; AGI-11-RB01 DESABILITADO.** Apenas documentação; nenhuma cobrança, teste, programação ou implantação.
