# F1-424 — revisão conservadora da competência pós-paga

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY / NO-REAL-MONEY / NO-INVOICE.**

A F423 prepara somente **ativação R$490 por canal** no Asaas
sandbox fictício, mantendo o código Laravel com HMAC de
proprietário, RLS e `SandboxCheckoutIntent` sem faturar gestão.

Para chegar ao fechamento da **gestão mensal pós-paga** é necessário
distinguir competência encerrada, gastos efetivamente validados,
aceite do cliente, primeiro período parcial e data de vencimento.
A F424 adiciona um **contrato puro de revisão**, não um emissor de faturas.

## Regras aplicadas ao candidato

`PerformanceMonthlyCloseCandidate::inspect` só produz um candidato
informativo quando:

1. A oferta foi calculada no servidor e carrega exatamente a política
   `performance-postpaid-170-per-channel-2026-11-14` (não legado).
2. A gestão por mês completo é **máximo entre 20% do gasto comprovável
   de cada canal e R$170**, calculada e somada canal a canal, sem mídia
   nem ativação no total mensal.
3. O relatório de laboratório é sintético `SIM-...`, a competência
   é a mesma da oferta e a decisão do cliente foi **acknowledged**.
4. A competência está encerrada na data local de São Paulo. A data
   proposta de vencimento fica depois do mês fechado e não no passado.
5. Existe uma **data de ativação verificada pela futura integração**.
   Não é inferida da contratação nem do primeiro pagamento.
6. Uma ativação no meio do primeiro mês **bloqueia** o candidato,
   porque ainda não está definida a política de mínimo proporcional.
7. Mês com mídia zerada por canal fica retido para revisão do estado
   do serviço (suspensão/ausência de extrato), sem sugerir faturamento
   com base em suposições.
8. Canais, médias, pisos, versões, total, cliente e demais valores
   não podem ser substituídos por argumentos da página.

Uma competência aprovada produz apenas `charge_key_candidate`,
valor **informativo**, referência da política e flags de recusa:
`real_invoice_authorized=false`,
`sandbox_psp_write_authorized=false`,
`fiscal_document_authorized=false` e
`production_authorized=false`.

## O que ainda falta

Esta classe **não** prova origem dos extratos oficiais de mídia,
contrato/aceite legal, autorização real de tenant, data de ativação,
identidade do provedor ou rastreabilidade financeira. A `SIM-...`
é **fictícia** e impede qualquer inferência sobre gasto verdadeiro.

Próximo recorte antes de um sandbox de fatura: persistência com
`UNIQUE(tenant,order,period)` e owner scope HMAC/RLS; estado de
serviço e política de primeiro mês; revisão do cliente e operador,
vencimento administrativamente aprovado, nota fiscal, concorrência,
idempotência e worker de conciliação específico. Não basta uma
função pura retornando `charge_key_candidate` para gerar dinheiro.

Executar:
`php apps/portal/tools/test_performance_monthly_close_f424.php`

CI em PHP 8.3 e 8.4, sem PostgreSQL, APIs ou servidores externos.
**Não há novas rotas, migrations, cron ou PSP write.**
