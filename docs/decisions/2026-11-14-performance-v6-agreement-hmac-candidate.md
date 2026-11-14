# Portal Performance — protocolo HMAC V6 com ID do contrato assinado (laboratório)

**Motivação:** o protocolo candidato V5 (PR #772) assina empresa, competência, catálogo, orçamento e nonce, mas não assina `agreement_id`. Para permitir múltiplos contratos por empresa/mês, a PR Performance #362 usa vínculo cadastrado separadamente, que é seguro **apenas como ensaio do CI**, pois não comprova autorização real.

## Nova proposta candidata

`PortalPerformanceV6AgreementLabIssuer` emite cotação com os **mesmos preços comercialmente aprovados** Meta R$700, Google R$1.000, ChatGPT R$1.200 mínimos mensais de mídia, gestão Imagine 20%. A assinatura inclui agora explicitamente o identificador imutável `agreement_id`, além de `tenant_id`, `billing_period`, `contract_version`, `audience`, `expires_at`, `issued_at`, `key_id`, `nonce` e a cotação completa.

Domínio HMAC isolado: `imagine:portal:performance:quote:v6\n`; versão `performance-trichannel-v6-agreement-candidate` e audiência `imagine-performance-finance-v6-agreement-preview`. Alterar um único identificador de contrato modifica a assinatura, mesmo com empresa, competência e orçamento iguais.

**Não altera** o catálogo ativo V4, a proposta V5, os contratos históricos, o checkout ou os outros emissores. Utiliza `PerformanceTriChannelV5CandidateCatalog` apenas como fonte de preços aprovados ainda **não ativados**. Precisa `APP_ENV=testing` e as flags `PORTAL_PERF_V6_AGREEMENT_LAB=1`, `PORTAL_PERF_TRICHANNEL_V5_LAB=1`.

Para concluir o recorte E2E, o Performance terá que verificar essa assinatura V6 específica, vincular `agreement_id` à autoridade IAM do Portal e persistir nonce e rascunho em transação PostgreSQL. Por enquanto não há chave de produção, login real, credencial Ads, cobrança Asaas, consentimento ou autorização de mídia.

**DRAFT / NO-MERGE / NO-DEPLOY / NO-CHARGE / NO-ADS-WRITE.**
