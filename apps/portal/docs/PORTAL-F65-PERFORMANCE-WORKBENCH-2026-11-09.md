# PORTAL-F65 — prévia HTML offline de gestão de campanhas Imagine Performance

## Implementação reunificada

Esta etapa incorpora à linha do Portal F63/F64 o leitor e o gerador de HTML desenvolvidos em paralelo:

- Portal [PR #193](https://github.com/rafhael100/imagine-portal/pull/193), leitor do contrato `perf.portal.workbench.offline.synthetic.v1` (`dev/portal-f59-performance-workbench-consumer-2026-11-09`)
- Portal [PR #196](https://github.com/rafhael100/imagine-portal/pull/196), tela HTML DEMO (`dev/portal-f60-performance-offline-workbench-html-2026-11-09`)

Os cinco arquivos importados foram preservados byte a byte; `PortalImportedLabProvenanceTest` agora compara **16 blobs Git de origem**, incluindo os da tela Performance, e detecta alterações não revisadas.

## Visualização

O CLI `apps/portal/tools/f60-render-performance-workbench.php` recebe unicamente um JSON local compatível com o contrato candidato. Apresenta resumos de campanhas Google Ads e Meta, revisões internas pendentes/recusadas, estados DEMO, sem aprovações comerciais nem execução.

O CI F65 valida PHP 8.3/8.4 + Node 22/24 e produz **um HTML estático temporário** para revisão visual (artefato do GitHub Actions com retenção de 2 dias). A prévia é independente do pacote F58 de sete telas gerais; nenhuma página dinâmica ou rota do Portal foi aberta.

Para reproduzir **somente em ambiente local de testes**, na raiz do repositório:

```bash
APP_ENV=testing PORTAL_F59_WORKBENCH_LAB=1 PORTAL_F60_HTML_LAB=1 \
  php apps/portal/tools/f60-render-performance-workbench.php \
  apps/portal/tests/Fixtures/performance-workbench-offline-v1.json > performance-demo.html
```

Não publicar o HTML em domínio público; contém exclusivamente dados fictícios, mas **não possui autenticação**. Os dados reais de campanha, custos, spend, autorização de provedores e consentimento não são suportados por este contrato e causam recusa.

## Controles e limitações

- Validação fechada de esquema, status, canais e contagens.
- Não aceita cliente/tenant real, status de publicação, faturamento, autenticação ou aprovação operacional.
- HTML escapado e CSP de bloqueio, sem JavaScript, links, formulários, rede ou operações.
- Uso exclusivo em PHP CLI `APP_ENV=testing` com flags de laboratório. Proibido em produção.
- Preserva as rotas do Laravel (somente `/up`). A stack F64 mantém a única rota de saúde, não serve este HTML.

**DRAFT. Nenhum merge/deploy, acesso ao Google/Meta, custo novo ou mudança na VPS.**