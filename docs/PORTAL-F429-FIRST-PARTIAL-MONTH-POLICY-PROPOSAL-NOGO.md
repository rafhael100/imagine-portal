# F429 — primeiro mês parcial: cálculo proposto, **sem faturamento**

## Regra comercial proposta (sujeita a aceite contratual)

Para cada canal ativado durante a competência (não no primeiro dia), calcular o
honorário informativo como **o maior entre 20% da mídia efetivamente comprovada
e o piso proporcional de gestão**:

- Piso proporcional do canal = **R$170,00 × dias corridos ativos / dias corridos do mês**;
- Dia da ativação conta como ativo, desde que a ativação esteja comprovada;
- Cada canal é calculado independentemente, em centavos, com arredondamento
  **metade para cima**. A soma dos canais é a proposta mensal;
- Mês civil de São Paulo: 28, 29, 30 ou 31 dias, sem cálculo pela duração em horas;
- **Ativação única de R$490/canal** é cobrada em rubrica separada e não entra
  no cálculo da gestão pós-paga.

### Exemplo de simulação (novembro, 30 dias)

| Canal | Dias ativos | Mídia informada | 20% | Piso proporcional | Gestão proposta |
|---|---:|---:|---:|---:|---:|
| Google Ads | 15 | R$400,00 | R$80,00 | R$85,00 | **R$85,00** |
| Meta Ads | 10 | R$1.000,00 | R$200,00 | R$56,67 | **R$200,00** |
| **Total** | | | | | **R$285,00** |

Este exemplo é **sintético e não é uma cobrança nem oferta contratual final**.

## Decisões que ainda exigem ratificação/assinatura

1. Aceite contratual explícito da proporcionalidade do piso no mês inicial e
   a definição do comprovante de data de ativação **por canal**;
2. Regras para suspensão técnica/voluntária, campanhas sem veiculação ou gasto
   zero, falha de extratos, reembolsos, créditos e contestação: sem prova, não
   há cobrança automática;
3. Vencimento, forma de notificação, janelas de contestação, NFS-e e critérios
   de reprocessamento financeiro;
4. Reconciliação com fontes oficiais autenticadas Google/Meta, não com fixtures;
5. Revisão financeira, jurídica, AppSec/DBA/LGPD e GO da VPS com rollback.

### Fronteiras técnicas

A rotina `PerformanceFirstPartialMonthProposal::inspect` usa oferta congelada
server-side e retorno **fictício** de F428; rejeita mês em andamento, canal
suspenso, datas inválidas, gasto zero, divergência e campo de cobrança
habilitado. `contract_policy_ratified=false`,
`official_media_evidence_verified=false`, `can_charge=false`,
`real_invoice_authorized=false`, `can_write_psp=false` permanecem fixos.

**DRAFT/NO-MERGE/NO-DEPLOY/NO-CHARGE**. Contrato e gateways reais permanecem
bloqueados. A etapa não modifica a F424 que continua recusando primeiro mês
parcial sem regra formalmente aprovada.
