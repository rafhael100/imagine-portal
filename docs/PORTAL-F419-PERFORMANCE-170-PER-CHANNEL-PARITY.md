# F1-419 — Convergência do mínimo mensal aprovado entre Portal, checkout e Performance

**Revisão isolada. DRAFT / NO-GO / NO-MERGE / NO-DEPLOY / NO-REAL-MONEY.**

## Decisão comercial, já aprovada

A configuração sandbox do checkout [PR #757](https://github.com/rafhael100/imagine-portal/pull/757)
implementa `PerformancePostpaidPolicy::VERSION =
performance-postpaid-170-per-channel-2026-11-14` e:
- Ativação única **R$490 por canal**.
- Gestão mensal pós-paga = **máximo entre 20% da mídia efetivamente investida
  e R$170, calculado separadamente para cada canal contratado em mês completo**.
- A verba de mídia pertence à plataforma de anúncios e **não** é cobrada
  pela Imagine como verba de mídia.
- A primeira competência começa na data de ativação; para mês parcial,
  contrato e financeiro devem confirmar o tratamento aplicável antes da fatura.
- Meta/Facebook+Instagram contam como um canal. Google e ChatGPT são
  canais distintos; elegibilidade para ChatGPT Ads não é presumida.

A linha independente F408–F418 manteve equivocadamente
`minimum_management_fee_cents=null`, apesar da aprovação comercial.
Esta PR aproxima-a da mesma versão do sandbox #757 e **não altera a
cobrança de contratos legados**.

## Exemplos verificáveis

| Contrato | Mídia efetiva no mês completo | 20% | Mínimo | Gestão calculada |
|---|---:|---:|---:|---:|
| Meta | R$350 | R$70 | R$170 | **R$170** |
| Google | R$4.000 | R$800 | R$170 | **R$800** |
| Meta + Google (mesmo contrato) | R$4.350 | R$870 | R$340 total de pisos | **R$970** |
| Um canal ativo sem gasto medido | R$0 | R$0 | R$170 | **R$170**, apenas candidato a revisão |

O piso **não** é aplicado uma vez ao total agregado: a cobrança
indicativa de dois canais é a soma `max(20%×Meta, R$170) +
max(20%×Google, R$170)`. O valor real exige declaração oficial de
mídia, aceite e regras de inatividade/competência no contrato.

## Escopo do código e travas

- `ActivationPostpaidPreview`: versão explícita da política e revisão
  sintética dos gastos por canal; calcula o candidato de mês completo,
  preservando `real_invoice_authorized=false`.
- `ActivationGatewayReviewLab`: carrega piso em centavos e versão
  para o contrato sintético; somente a ativação ingressa no PSP.
- `PerformanceChannelReviewEnvelope`: a assinatura de revisão vincula
  também o piso e versão para não aceitar uma política antiga `null`.
- Simulador JS: mostra `R$170/canal` e a soma das estimativas por
  canal para o mês completo; deixa claro que a previsão não é boleto.
- PHPUnit, smoke PHP, Node e Chromium desktop/mobile verificam regressões.
- O primeiro mês parcial é sinalizado como exigindo revisão contratual
  e financeira; **nenhum rateio, isenção ou faturamento é inferido**.
- Não há alteração de tenant real, login, cobrança, API, banco, chave
  do PSP, contrato operacional, NFS-e ou servidor Leaseweb.

## Gates restantes

Mesmo com CI aprovada, a liberação operacional ainda precisa de contrato
por empresa, extratos oficiais/consentimento, recurso de contestação,
fiscal, revisão IAM/DBA/AppSec/LGPD, integração sandbox PSP, backups e
rollback. A aprovação do mínimo não concede autorização de emitir faturas.
