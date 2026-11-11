# GSC-F139 — Tela de evolução orgânica em laboratório

Esta etapa agrega uma tela HTML estática e acessível para acompanhar duas janelas sintéticas de Google Search Console, usando o contrato F54 do SEO Engine gerado na CI e verificado pelo validador PHP F138. A prévia apresenta cliques, impressões, períodos, barras proporcionais e revisões propostas quando a cobertura das datas fornecidas permite comparar.

A página tem `noindex,nofollow`, política CSP restritiva e não contém JavaScript, links, formulários, requisições externas nem dados reais. O PHP só aceita execução CLI sob `APP_ENV=testing`, `PORTAL_GSC_COMPARISON_LAB=1`, `PORTAL_GSC_COMPARISON_HTML_LAB=1` e um DTO estritamente validado.

**Não há OAuth real no Portal nem autorização de uso produtivo.** A propriedade conectada no OpenSEO é independente. Não há alteração de `main`, deploy, pagamentos, clientes ou DNS. Revisão SEG/DBA/LGPD e homologação autenticada continuam gates.
