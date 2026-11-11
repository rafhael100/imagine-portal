# GSC-F159 — Prévia HTML estática da sobreposição SEO

A CLI `apps/portal/tools/f159-render-gsc-query-page-review-offline.php` recebe somente um arquivo JSON **sintético** do relatório `seo-portal-query-page-review-offline-v1` (SEO-F74). Revalida o contrato com a classe PHP F155, recusa privilégios, limita leitura a 64 KiB e rejeita symlink final. Se a entrada falhar, não imprime conteúdo privado nem HTML parcial.

A página apresenta somente contagens agregadas (linhas, consultas observadas, consultas que aparecem em várias páginas e linhas ocultadas), status de cobertura e texto fixo de revisão. Usa CSP `default-src 'none'`, `connect-src 'none'`, `noindex,nofollow` e **não contém** JavaScript, formulário ou links.

O workflow de laboratório F79 gera dados com **código Node real da RC32** e verifica o HTML nos seis cenários F156/F158 (incluindo batch em duas páginas e URLs parametrizadas), validando visibilidade prudente e ausência de conteúdo executável. Comandos exigem `APP_ENV=testing`, `PORTAL_GSC_QUERY_PAGE_REVIEW_LAB=1`, `PORTAL_GSC_REVIEW_HTML_LAB=1` e PHP CLI. Nenhuma rota pública foi criada.

**Não há OAuth ou Google HTTP reais, grants autenticados, exibição a clientes, deploy, DNS, publicação, migração, faturamento ou merge main/dev.** Revisão independente AppSec/DBA/LGPD e homologação do Portal continuam obrigatórias.
