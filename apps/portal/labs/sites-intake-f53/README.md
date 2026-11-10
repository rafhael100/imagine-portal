# PORTAL-F53 — CreateProject v1, compositor DEMO sem transporte

Continuação de F52/PR #182. Arquitetura do Portal Laravel isolada do legado Node/SQLite e do motor `imagine-sites`.

**Objetivo:** obter uma fixture sintética reprodutível que respeite os oito campos do schema candidato do motor Sites e o formato local F52, sem dados livres do briefing, sem identidade autenticada, sem banco, sem rede, sem publicação.

## Implementação

- `SiteEngineCreateProjectDemoComposer::compose(SiteBriefDraft, int)` usa apenas `SiteBriefDraft` (F42, dados `DEMO-` e `org-a/org-b`). Valida revisão sintética positiva até 100000.
- Tradução tipada `Onepage→onepage`, `LandingPage→landing_page`, sem aceitar produtos do CRM/Universidade/Performance.
- `tenantRef`, `orderRef`, `contractRef`, `operationId` e `idempotencyKey` possuem prefixos de demonstração. SHA-256 deriva uma chave **de fixture** determinística por empresa/referência/revisão/produto. Não é assinatura, token, prova de consentimento, idempotência persistente ou garantia de unicidade de produção.
- O texto livre do briefing e do conteúdo não é incluído no envelope.
- O compositor só devolve JSON local. Os quatro métodos de autorização continuam `false` por construção.
- Sete testes verificam mapeamento, compatibilidade local, restrições de revisão, isolamento por empresa, determinismo/versões, não vazamento de conteúdo e proibição operacional.

## Contrato de referência

`rafhael100/imagine-sites/contracts/portal-sites/v1/create-project.schema.json`, blob observado `042f4ba31776b486ced757e9624bc42757c46e74`.

## Limitações e gates

Não há contrato de cliente válido, autoridade Portal-only, MFA, RLS persistido, outbox com retries, autenticação/assinatura serviço-serviço, aceite, transferência, HTTP ou autorização de publicar. `idempotencyKey` é uma chave ilustrativa, não tem armazenamento ou deduplicação. O verificador F52 exige ordem e serialização PHP adicionais, possivelmente mais restritivas do que o schema Sites. Uma integração real exige acordo explícito de canonicalização e versões entre equipes e revisão AppSec/DBA.

**DRAFT; não fazer merge/deploy, não conectar à VPS, não criar despesas.**
