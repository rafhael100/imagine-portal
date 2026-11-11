# Imagine Portal — Lote D isolado de política de respostas GSC

Este quarto lote da integração SEO parte do [Lote C #400](https://github.com/rafhael100/imagine-portal/pull/400) e recupera apenas a função **pura** `GoogleSearchConsoleProviderResponsePolicy` da antiga linha de revisão; não adiciona transporte HTTP.

## Escopo

- Interpreta apenas um código HTTP sintético (100–599), um único cabeçalho `Retry-After` estritamente numérico (0–120 s) e a tentativa (1–3).
- Classifica 401 (reauth HOLD), 403 (permission HOLD), 404 (not found HOLD), 429 (quota), 5xx transitórios (review), 2xx (conteúdo recebido **não verificado**) e demais códigos (review).
- Retentativas são apenas **orientação** para futuro componente autenticado, com máximo três tentativas; esta função não agenda tarefas, não faz HTTP e não retém tokens.
- Ignora corpos de resposta, recusa cookies, Authorization, Retry-After malformado, tentativas adicionais e métricas de segredo forjadas. Todas as flags de autenticação/publicação são falsas.
- `apps/portal/labs/gsc-slice-d/verify_provider_policy.php` exercita cenários sintéticos positivos, adversariais e sanitização no CI das quatro combinações PHP8.3/8.4 × Node22/24.

## Segurança e decisões pendentes

**NO-GO operacional.** Os códigos e cabeçalhos usados em teste não comprovam acesso à propriedade, resposta genuína do Google, consentimento, tokens, autorização por tenant ou identidade do usuário. O backend futuro deve aplicar MFA/RBAC, grants de propriedade, quotas por empresa, delays com limites, cancelamento/revogação, observabilidade sem segredos e revisão independente AppSec/LGPD.

Sem chamadas reais, OAuth, faturamento, dados de clientes, deploy, migrations, publicação ou merge na dev/main. O próximo gate é revisão externa, testes adversariais de autenticação e integração controlada por SHA.
