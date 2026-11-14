# Portal Performance V7 — plano por canal e competência assinado, candidato de laboratório

**14/11/2026. Somente CI / revisão em rascunho, sem aceite real, pagamentos, operações Ads ou deploy.**

## Lacuna identificada

O F419 já emite envelope HMAC de **revisão de ativação**, que protege o valor de R$490 por canal, seleção, PSP/CRM, política de gestão de R$170/canal em mês completo e taxa de 20%. Entretanto, **não assina orçamento de mídia por canal, empresa contratante ou competência mensal**; o valor pode ser exibido como estimativa de compra, mas não autoriza uma apuração posterior. É também de curto prazo, sem controle de uso único de uma contratação real.

## Novo código

`PortalPerformanceV7AgreementPlanLabIssuer` exige que **a revisão F419 real seja verificada novamente** por `PerformanceChannelReviewEnvelope::verify`, usando a organização, pedido, política e revisão CRM conhecidos no Portal. Emite, com **outra chave HMAC e domínio distinto**, envelope novo de revisão sobre:

- `tenant_id`, `agreement_id`, mês civil `YYYY-MM`, organização/pedido/revisão;
- seleção de canais ordenada e mapa exato de orçamento por canal, com pisos planejados: Meta R$700, Google R$1.000, ChatGPT R$1.200;
- ativação R$490 por canal, `activation_review_mac` da revisão F419 de origem;
- taxa de gestão de 20% por canal, piso de honorários R$170 por canal em mês completo, versão `performance-postpaid-170-per-channel-2026-11-14`, e estimativa agregada;
- vínculo `key_id`, `nonce`, emissão e validade **máxima de 300 segundos**;
- proibições assinadas explícitas: **sem contrato aceito, IAM real, prova de gasto, cobrança, ativação ou alteração de anúncios**.

A chave F419 e a chave V7 devem ser diferentes; nenhuma é armazenada no envelope. O programa nunca chama o Asaas, C6, Meta, Google, OpenAI, CRM, banco externo ou servidor. Testes usam apenas dados fictícios.

## Próximo corte de integração

O Imagine Performance precisa implementar um **verificador independente** dessa versão (sem chamar o emissor Portal como verificador), rejeitando valores e contextos divergentes. Para homologação operacional, os nonces devem ser consumidos por armazenamento durável com escopo da empresa e acordo, e **aceite real do contrato** deve ser uma autorização separada, auditável e revogável. O envelope aqui é exclusivamente uma simulação de plano negociado, jamais cobrança.

A homologação financeira depende de política explícita para meses parciais, pausas/suspensão e cancelamentos, extrato oficial de mídia, conferência de ajustes, PSP sandbox e fiscal/NFS-e, além de SEG/DBA/LGPD/Financeiro e GO explícito.
