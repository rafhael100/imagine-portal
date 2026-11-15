# Imagine Portal — SEO-F106 cross-stack proof (laboratório)

**Apenas laboratório, sem HTTP público, integração em produção, OAuth, tokens,
migrations, acesso real a Google, CRM, Search Console ou dados de clientes.**

A PR usa o código do Imagine SEO Engine F106 a partir de SHA **fixa**
\`7a072c01c3cbf48e69f99a7273081c20d49390d1\`
([SEO PR #153](https://github.com/rafhael100/imagine-seo-engine/pull/153))
para gerar três DTOs sintéticos, que o PHP do Portal valida de forma
independente. A origem dos JSONs continua não verificada; o teste de
interoperabilidade não constitui autenticação.

## Contratos e situações verificadas

- \`complete\`: data anterior e atual completas (segundo linhas *fornecidas*),
  cliques e impressões do **GSC** ficam apenas na área de descoberta orgânica.
  As três etapas **Visitas, Leads, Clientes** permanecem **não disponíveis**.
- \`partial\`: data ausente na janela anterior bloqueia inclusive os números
  da área de descoberta orgânica. Não presumir tráfego zero.
- \`zero\`: cliques de busca zero **não** significam visitas zero, leads zero,
  ou clientes zero.
- Casos negativos: adulteração da origem, propriedade, tenant, flags de
  autorização, alegações de conversão, status e totais, períodos e origem de
  métricas. Modos não-test sempre bloqueados.
- O PHP só responde com resumo mínimo e estado indisponível. Nunca copia
  o DTO original, propriedades, identificadores de tenant, claims ou segredos.

## Validação CI

\`.github/workflows/portal-seo-f106-funnel-ci.yml\` executa Node.js
22/24 e PHP CLI no Ubuntu 24.04. Checkout da HEAD exata do Portal e da
SHA F106 do SEO Engine (ambas verificadas); ações por SHA; alteração restrita
a 5 caminhos; credenciais git não persistidas; nenhum deploy.

## Próximos gates reais

A integração **real** requer implementar no backend autenticado do Portal:
sessão, MFA/RBAC, tenant/site com segregação e auditoria, propriedade GSC
autorizada e OAuth próprio readonly, fonte analytics autorizada para visitas,
eventos CRM atribuídos para leads/clientes, LGPD e retenção, proteção contra
replay, staging com testes adversariais, backups, rollback e aceite de release.

Esta PR **não** implementa esses serviços e não concede permissão; ela apenas
estabelece e testa as fronteiras de dados. Qualquer uso público exigirá
outro recorte de implementação e revisão.

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY**.
