# PORTAL-F79 — integração de revisão SEO no Portal, somente HTML fictício

A F79 integra com a linha de segurança F78 o consumidor de contrato `seo-portal-review-handoff-v1`, desenvolvido na branch separada do Portal (PR [#210](https://github.com/rafhael100/imagine-portal/pull/210)). As três peças originais foram copiadas **sem modificar os bytes**: validador PHP, teste PHPUnit e documento de contrato SEO. A origem de dados é o Imagine SEO Engine F24, cuja autorização operacional ainda não foi implementada.

## O que foi acrescentado

- Fixture JSON 100% sintética (`imagine-demo`, hash fictício de propriedade, sem OAuth, métricas inventadas com fins visuais).
- `tools/f79-render-seo-review.php`: gera HTML standalone responsivo, em português, com indicadores técnicos, tendências fictícias e recomendações para revisão humana.
- `tests/ui/test-f79-seo-review.mjs`: valida proteção contra flags falsas de autorização, tenant de cliente, tentativa de injeção, esquema divergente, arquivos ausentes e uso fora do ambiente de teste.
- Workflow `portal-f79-seo-review-ci.yml`: PHP8.3/8.4, Node22/24, sem rede externa, e exporta artefato HTML DEMO por dois dias, somente em uma combinação de matriz.

Para reproduzir **somente em computador de desenvolvimento ou CI**, na raiz do repositório:

```bash
APP_ENV=testing PORTAL_SEO_HANDOFF_LAB=1 PORTAL_F79_HTML_LAB=1 \
  php apps/portal/tools/f79-render-seo-review.php \
  apps/portal/tests/Fixtures/seo-review-offline-v1.json > seo-review-DEMO.html
```

O relatório contém CSP `default-src 'none'`, sem JavaScript, links, formulários, rastreadores, requisições de rede ou dados reais. Não abre uma nova rota do Laravel e não integra serviços de terceiros.

## Requisitos de segurança ainda fechados

Um relatório real do Google Search Console depende de conta/propriedade autorizadas, consentimento, identidade e sessão Portal-only, grant por empresa, origem/verificação de dados, escopo de propriedade, retenção e auditoria LGPD. A fixture sem essa autoridade **não pode ser mostrada a clientes**, mesmo que o HTML seja visualmente útil.

O PR não libera SEO, não cria solicitações de revisão reais, não publica páginas nem toca no Google. Não há login, OAuth, S2S, billing, Cloudflare, alteração de VPS ou custos. A linha F78 de segurança segue com review-only e antirollback SEC-R03 pendente.

**Status: DRAFT / NO MERGE / NO DEPLOY.**
