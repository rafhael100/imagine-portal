# F1-422 — catálogo comercial V5 e apuração pós-paga na linha integrada

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY / NO-REAL-MONEY.**

## Por que este recorte foi necessário

A integração F419–F421 já corrigiu a política de gestão em classes e
simuladores, mas o catálogo usado por `CustomerPreviewStore` ainda
cotava novas contratações de Performance como **honorários adiantados de
20% da mídia planejada**. Isso conflita com a regra aprovada e testada
no checkout sandbox [PR #757](https://github.com/rafhael100/imagine-portal/pull/757).

A F422 reconcilia o catálogo ativo **sem** transplantar 33 arquivos
conflitantes de checkout sandbox que substituiriam controles recentes
de proprietário, HMAC, RLS e pagamentos.

## Política V5 — novas cotações

- **Ativação única R$ 490 por canal contratado** no resumo comercial.
- Gestão mensal **pós-paga**, sobre gasto real comprovado:
  `max(20% da mídia por canal, R$170)` por mês completo e por canal.
- Mídia paga diretamente aos provedores, não à Imagine.
- Mídia mínima informada ao cliente: **Meta R$ 700**, **Google R$ 1.000**
  mensais. O ChatGPT Ads permanece inabilitado no checkout atual,
  sujeito a homologação de oferta e provedor.
- Mês parcial, vencimento e contestação precisam de política contratual
  final e validação financeira; **nenhuma estimativa é uma fatura**.
- A simulação não habilita PSP, C6, criação de cobranças ou anúncios.

O catálogo JSON evolui para `schema_version=5`. A versão
`performance-postpaid-170-per-channel-2026-11-14` acompanha a proposta
no snapshot assinado pelo servidor. **Onepage e Landing Page mantêm
suas condições anteriores.**

## Compatibilidade histórica

O método `ApprovedOfferCatalog::reconcilePerformance` permanece para
**snapshots legados sem `pricing_policy`**: a antecipação e o acerto
antigos não são reescritos.

`PerformanceSettlement::calculate` reconhece o contrato V5 e gera
somente **revisão** por competência, calculando o piso individual. Rejeita
falsificação de valores de ativação, estimativa e versão. O ciclo
`submit/respond` preserva confirmação/contestação em laboratório;
não foi criada operação de emissão de cobrança. A interface distingue
explicitamente os dois formatos.

O `SandboxCheckoutIntent` existente continua autorizando somente
a Onepage no laboratório; Performance e Landing Page **não** ganham
checkout Asaas por esta revisão. O formulário deixa de mostrar botão
que não seria autorizado pelo servidor.

## Matriz de teste

- Google Ads R$1.000 de orçamento + Meta R$700: ativação R$980;
  gestão ilustrativa mês completo R$370.
- Gasto efetivo Google R$4.000 e Meta R$350: gestão R$970.
- Gasto efetivo zero nos dois canais ativos: mínimo de R$340, para
  revisão financeira, sem emissão de fatura.
- Novo Google R$700 é recusado; Meta abaixo de R$700 é recusada.
- Pedidos históricos de antecipação R$140 com gasto R$1.000 continuam
  gerando apenas o ajuste histórico de R$60, sem novo piso.
- Fraude de versão, orçamento, valor da ativação ou consentimento
  sintético não deve abrir cobrança.
- A saída retorna `real_charge_enabled=false` e
  `real_postpaid_invoice_authorized=false`.

## Próximos gates operacionais

Para um checkout sandbox de Performance verdadeiramente navegável e
auditado na infraestrutura Imagine, portar a parte operacional da PR757
**preservando as transações por proprietário da linha atual**,
migrations aditivas e antifraude. Executar ensaios de contestação,
competência, suspensão, faturas e webhook, com rollback. Antes de
produção: contrato, titularidade das contas de anúncio, evidência de
gasto, IAM/MFA, LGPD, NFS-e, C6/Asaas reais e aprovação de release.

Esta PR não muda `main`, a VPS Leaseweb, segredos ou pagamentos.
