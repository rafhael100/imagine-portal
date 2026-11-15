# Portal SEO — prova sintética F104 (OpenSEO diária)

Esta entrega inclui um **consumidor PHP de laboratório** para a saída
`seo-openseo-daily-offline-review-v1` do Imagine SEO Engine F104.

## Integração e limites

- O workflow usa o código SEO fixado na SHA
  `ec1739621b20860f729c59d9863ba1b341da43e8`, com verificações de
  integridade do checkout e testes `npm test` reais.
- Node gera três DTOs exclusivamente sintéticos: dois dias completos,
  um intervalo com dia ausente e uma resposta vazia. A propriedade é sempre
  `sc-domain:example.com`, não a propriedade real Imagine.
- O PHP valida esquema estrito, fingerprint da propriedade, tenant sintético,
  intervalos, cálculos de cliques/impressões/CTR, posição e ordenação. Recalcula
  métricas e recusa permissões e campos extras falsificados.
- A classe só responde em CLI, com `APP_ENV=testing` e
  `PORTAL_OPENSEO_DAILY_LAB=1`. Fora disso, retorna `null`.
- Não há rota HTTP, migração SQL, credenciais, banco real, conexão Google,
  leitura de clientes, interface pública, token ou publicação.
- Dados ausentes permanecem **desconhecidos**; não fabricar linhas de zero.
- Funções `canQueryGoogle`, `canPublish`, `canStoreTokens`,
  `hasVerifiedTenantGrant` e `isCustomerDisplayAuthorized` são sempre falsas.

## Gates antes de produção

**Nenhuma execução desse laboratório comprova autenticação de usuário,
autorização de tenant, propriedade no Portal, LGPD ou concessão OAuth.**

Ainda são necessários backend de sessão com grants e vínculo persistido
no PostgreSQL, consentimento Google verificável, segredos em cofre, trilha
de auditoria, revisão independente, staging, backup e rollback.

Esta entrega segue **DRAFT / NO-GO / NO-MERGE / NO-DEPLOY**.
