# F1-408 — Nova oferta Performance, sem migração do modelo legado

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY / NO-CHARGE.**

O titular aprovou em 14/11/2026: ativação **R$490 por canal**; mídia paga
diretamente pelo cliente à plataforma; gestão mensal **20% sobre o gasto
efetivo**, apurada após o mês civil. O primeiro período começa na data de
ativação, não em um início fictício de ciclo de 30 dias.

## Contrato candidato no código

`ActivationPostpaidPreview` é uma classe pura, **não injetada** em
`SandboxCheckoutIntent`, `PerformanceSettlement`, catálogos v4,
Asaas, webhooks, NFS-e, autenticação ou cron.

- `activationQuote` conta 1–3 canais canônicos, valida elegibilidade
  **fornecida exclusivamente pelo servidor**, conta Meta uma única vez,
  multiplica R$490 pelo número de canais e descreve os mínimos e recomendações
  de mídia da proposta v5, sem gerar contratação.
- ChatGPT Ads pode existir no catálogo, mas só é selecionável se estiver
  explicitamente elegível; o fixture de teste mantém o canal bloqueado.
- `monthlyReview` exige mês civil, data de ativação válida e todas as
  medições sintéticas por canal. Calcula o maior entre **20% da mídia real e R$170 para cada canal** em meses completos, somente para revisão, não boleto.
- A política aprovada foi reconciliada em F419 com o cálculo sandbox F757: `minimum_management_fee_cents=17000` **por canal e mês completo**, versionado como `performance-postpaid-170-per-channel-2026-11-14`. `real_invoice_authorized=false` continua obrigatório.
- O primeiro mês inclui apenas a janela ativação→último dia do mês. Não
  presumir gastos anteriores nem pró-rata de honorário mínimo.
- A referência `SIM-...` é **evidência fictícia de teste**, nunca prova
  de gasto externo nem autorização financeira.

## Isolamento e evolução

1. Guardar snapshot da contratação nova (versão, tenant, canais, preço
   de ativação, aceite e gateway) antes de qualquer pagamento.
2. Não alterar cobrança, assinatura ou pedido antigo. O fluxo v4 de
   antecipação + acerto permanece **legado**, não é convertido neste PR.
3. Homologar contrato, IAM/MFA, fornecedor e canal, consentimentos, LGPD,
   e origem dos valores reais (extratos oficiais de Google/Meta/ChatGPT).
4. O mínimo de R$170 por canal está definido para **mês completo**. Validar tratamento contratual de primeiro mês parcial, vencimento, corte de dados indisponíveis e prazo de contestação.
5. Construir etapa financeira assinada, apuração idempotente, decisão
   humana auditável, NFS-e e cobrança por gateway com conciliação e rollback.
6. Testes reais sandbox, segurança e lançamento liberado formalmente.

Não transformar um resultado com CI verde em autorização de produção,
contratação ou cobrança. As provas são PHP puro e fixtures fictícios.
