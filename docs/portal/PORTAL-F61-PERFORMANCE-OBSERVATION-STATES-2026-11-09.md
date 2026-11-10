# PORTAL F61 — vocabulário tipado de estados observacionais Performance

Data-base: 09/11/2026. Continuação dos PRs Portal F59 #193 e F60 #196.

## Implementação

Foram adicionados os enums internos **somente para apresentação**:
- `PerformanceCampaignObservationState`: `draft_demo`, `paused_demo`, `archived_demo`;
- `PerformanceReviewObservationState`: `pending_human_demo`, `approved_demo_no_execution`, `rejected_demo`.

O consumidor `OfflinePerformanceWorkbenchPreview` do Portal F59 agora valida os estados usando esses enums tipados, em vez de listas duplicadas. O renderizador F60 também utiliza seus `label()` como fonte única, mantendo indicação DEMO e a expressão **sem execução** quando há aprovação interna fictícia.

Estes enums **não** são substitutos do `DemoCampaignStage` ou `DemoRequestStage` do Portal. São observações do sistema Performance, não decisões/etapas próprias do Portal. `portal_stage_mapping_ready=false` continua obrigatório, e nenhum deles autoriza campanha, cobrança ou publicação.

## Aceite

Os testes recusam estados de veiculação (`active`, `published`, `serving`), estados desconhecidos, elevação de flags, input inválido, IDs incompatíveis e execução fora de laboratório. O HTML continua sem script, forms, botões ou conexão externa.

O contrato é candidato para visualização em futura aplicação autenticada; ainda faltam Portal/IAM/S2S reais, autoridade confiável, consentimento de anunciantes, OAuth Google/Meta, LGPD, AppSec/DBA, VPS e homologação P0/HT-R01/SEC-R03/T3.

**Nenhum merge, deploy, conta publicitária, cliente, despesa ou cobrança.**
