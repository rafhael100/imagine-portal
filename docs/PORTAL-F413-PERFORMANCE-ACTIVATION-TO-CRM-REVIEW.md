# F1-413 — Performance: ativação → seleção de PSP → revisão CRM (offline)

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY / NO-CHARGE / NO-PII.**

Este recorte confirma em código que o novo pacote comercial de Performance,
com ativação aprovada de **R$ 490 por canal**, pode ser encaminhado a uma
política versionada de Asaas/C6 **somente como cotação financeira informativa**,
e ao contrato assinado de **revisão** do CRM.

## Invariantes

1. A seleção Meta, Google e ChatGPT passa pelo catálogo aprovado/eligibilidade
   explícita do servidor. Meta inclui Facebook e Instagram como um canal.
2. Apenas o valor de ativação vai à simulação de pagamento; mídia do cliente
   **nunca** é cobrada por Asaas/C6 no boleto da Imagine. Honorários mensais
   são 20% do gasto comprovado após fechamento por mês civil.
3. A configuração de gateway tem revisão imutável e deve passar por
   `GatewayPolicyReview::validate`; o número assinado ao CRM precisa
   corresponder exatamente à revisão usada para calcular a taxa.
4. `GatewayRoutingPreview::quote` calcula um PSP elegível para a modalidade,
   sem chave bancária nem API. C6 não é habilitado automaticamente.
5. `PortalGatewayReviewExporter::export` assina somente um **envelope de
   revisão offline**, com `can_charge=false` e `can_activate=false`.
6. A demonstração não contém contato ou identificação de consumidor;
   não cria contrato, invoice, evento, outbox, idempotency key operacional,
   webhook, boleto, cobrança ou transferência CRM.
7. O mínimo mensal de gestão está pendente de uma regra comercial
   versionada. É representado por `null`, não por um valor suposto.

## Antes de homologação real

- Identidade autenticada, RBAC/MFA, autorização por tenant e vínculo de pedido.
- Evidência de contrato/consentimento assinado, trilha de revisões e privacidade.
- Tarifas efetivamente contratadas, documentação C6, credenciais isoladas
  por ambiente e teste com provedor, com conciliação financeira independente.
- Persistência atômica do método, PSP, versão, montante e deduplicação,
  outbox e idempotência antes de qualquer `POST` de pagamento.
- Extratos oficiais de mídia, revisão/contestação, NFS-e e mínimo de gestão.
- Ensaios backup/restauração/rollback em staging Leaseweb e auditoria independente.

Apenas após esses gates deve existir um endpoint autenticado de contratação.
Passar CI significa compatibilidade de contratos, não liberação comercial.
