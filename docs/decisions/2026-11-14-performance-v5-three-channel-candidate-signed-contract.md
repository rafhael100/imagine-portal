# Imagine Portal — candidato de contrato Performance tricanal V5 (14/11/2026)

**Status:** homologação de contrato em PHP, sem adição ao catálogo ativo, checkout, financeiro ou Ads. Repositório: Portal PR #770 fornece o JSON owner-approved em commit pinado `fa96f973bd0b826ee8eb88c688a96c0b0d3176f9`; a CI exige a **mesma sequência de bytes** na cópia candidata.

## Proposta comercial aprovada
- Meta Ads: mídia mínima R$700/mês, referência recomendada R$1.000.
- Google Ads: mídia mínima R$1.000/mês, referência recomendada R$1.500.
- ChatGPT Ads: mídia mínima R$1.200/mês, referência recomendada R$1.800.
- A taxa Imagine é 20% do valor de mídia efetivamente investido, com adiantamento estimado e reconciliação após prova de gasto; mídia paga pelo cliente direto à plataforma. R$1.200 ChatGPT é **piso comercial Imagine**, não mínimo mensal universal da OpenAI.

## Arquitetura
`PerformanceTriChannelV5CandidateCatalog` lê apenas o JSON de decisão da PR #770 e bloqueia se dados de cobrança ou escopo mudarem. A cotação em BRL-centavos tem a mesma estrutura de 12 propriedades do catálogo v4, mas o `catalog_version` aponta para **SHA-256 da decisão candidata**, e não para o arquivo ativo. Isso evita que um contrato V5 seja tratado como uma cotação V4.

`PortalPerformanceTriChannelV5LabIssuer` usa um envelope HMAC distinto com `contract_version=performance-trichannel-v5-candidate`, audiência `imagine-performance-finance-v5-candidate-preview`, `billing_period`, tenant, key ID, nonce, TTL e versão de catálogo. Prefixo assinado: `imagine:portal:performance:quote:v5\n`. Isso evita downgrade ou reinterpretação automática da assinatura v1/v2. A chave é **fictícia e somente CI**.

O `ApprovedOfferCatalog` original e `approved-catalog.json` V4 permanecem **inalterados** e continuam recusando ChatGPT Ads. Nenhuma cobrança é emitida, e o contrato candidato só opera quando `APP_ENV=testing` e `PORTAL_PERF_TRICHANNEL_V5_LAB=1`.

## Requisitos não atendidos de GO
No Performance, implementar guard específico de HMAC V5 com catálogo pinado, nonce durável e fronteira de empresa confiável; adaptar banco, evidências oficiais por conta Google/Meta/ChatGPT, contratos/versionamento e migração sem prejuízo a contratos antigos. No Portal, IAM/S2S autenticado, vault/rotação de chaves, aprovação e consentimento, migrações SQL auditadas, Asaas Sandbox, regras financeiras/fiscais, SEG/DBA/LGPD e testes em staging. Não confundir assinatura com prova de gasto, aceite de cliente ou permissão de ativar checkout.

**DRAFT / NO-MERGE / NO-DEPLOY / NO-CHARGE / NO-ADS-WRITE.**
