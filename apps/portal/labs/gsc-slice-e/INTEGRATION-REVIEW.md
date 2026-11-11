# Integração Portal SEO — Candidata A–E (somente revisão)

**Base:** `dev/fast-track-portal-2026-11-05`. **PR consolidada:** [#408](https://github.com/rafhael100/imagine-portal/pull/408), DRAFT/NO-GO. A candidata não depende da linha acumulada de 545 arquivos da antiga [#394](https://github.com/rafhael100/imagine-portal/pull/394).

## Origem das entregas menores

| Fatia | PR histórica | Objetivo |
| --- | --- | --- |
| A | [#398](https://github.com/rafhael100/imagine-portal/pull/398) | F74 RC32 → validador PHP e HTML de consulta/página |
| B | [#399](https://github.com/rafhael100/imagine-portal/pull/399) | Tendência F67, redaction e painel HTML |
| C | [#400](https://github.com/rafhael100/imagine-portal/pull/400) | Planejamento de paginação, propriedade e intenção sem HTTP |
| D | [#403](https://github.com/rafhael100/imagine-portal/pull/403) | Política de erros/retry sintéticos, redaction e testes adversariais |
| E | [#408](https://github.com/rafhael100/imagine-portal/pull/408) | Paridade do modelo de privacidade SEO-F79 com HTML PHP e QA consolidado |

**Não mesclar A–D separadamente depois de aprovar E.** As PRs menores são trilha de auditoria, não cinco conjuntos independentes de produção.

## Evidência técnica exigida

- Checkout SEO RC32 SHA imutável `f46b65aaef9b10e32d02e415907cd916a43b548e`, novo modelo F79 também fixado no SHA `fdae08985e89b9bd1323e42d1d64678ddc69e446` para QA somente.
- GitHub Actions com Node 22/24 × PHP 8.3/8.4, contratos PHP sem segredos, sete classes de cenário incluindo amostras redacted/parciais, privacidade do HTML, SHA real e proibição de rotas, migration e permissões operacionais.
- O filtro CI para a PR consolidada aceita **somente caminhos previamente enumerados** em `apps/portal/labs/gsc-*`, contratos PHP de revisão, renderizadores estáticos e workflow `portal-gsc-slice-a-ci.yml`. Contagem de 20–35 caminhos sobre dev. Não serve como revisão de conteúdo.
- Identificadores `imagine-demo`, `sc-domain:example.com` e dados de consultas são fictícios.

## Critérios para promoção (ainda NÃO satisfeitos)

1. Revisor independente AppSec/DBA/LGPD com registro em [#251](https://github.com/rafhael100/imagine-portal/issues/251); conferir origem de sessão/MFA, grants por tenant e propriedade exata, OAuth consentido, RLS e segregação de credenciais.
2. Homologação de tráfego autorizado em staging, quotas e deadlines por tenant, retomada/revogação, proteção anti-SSRF, logs sem PII, backup e rollback testados. Estes componentes **não são implementados** por esta PR.
3. Atestar que nenhum módulo de laboratório é conectado a `routes/`, provedores, controllers, migrations, credenciais ou processo de deploy. Revalidar o SHA do head antes de qualquer merge.
4. Aprovação explícita de integração. **Não houve e não há autorização para deploy/merge de produção por testes verdes.**

**Estado:** DRAFT, HOLD_EXTERNAL_AUTHORIZATION. Nenhuma conexão Google, OAuth, dados de cliente, cobrança, publicação, 301 ou mudança de infraestrutura.
