# Landing Page — parser canônico de solicitação S2S (candidato)

Data: 19/11/2026. Este recorte adiciona o primeiro receptor de formato próprio de Landing Page, sem alterar o parser/dispatcher Onepage.

## Origem independente

- Sites [#553](https://github.com/rafhael100/imagine-sites/pull/553), SHA `26c64e4046a7e6e286676d1620c9af8a20d721aa`, emite `sites.landing-page-current-head.fetch.v1` e `productCode=landing_page`.
- Portal `LandingPageCurrentHeadS2sRequestCandidate::decode()` interpreta somente **esses bytes exatos**, devolvendo exclusivamente tenant/order/site como seletores sem autoridade. Um pedido Onepage é recusado e vice-versa.
- CI PHP 8.3/8.4 pinado reexecuta regressão de Onepage, ataques de chave duplicada, alteração de produto ou versão, campos de autoridade, identidade S2S dentro do body, UUID inválido e ordem/codificação não canônica.

## Limites

A existência do parser **não cria** endpoint, cliente autorizado, reader transacional de briefing, aprovação vigente, revisão, transporte mTLS, CAS, auditoria, release ou aceite. O serviço deve ser autenticado externamente e ligado ao tenant em leitura oficial do Portal. O produto Landing Page não recebe autorização por ser um JSON bem formado. A integração de produção e da identidade visual Imagine exige desenvolvimento e homologação próprios.

**DRAFT / NO-MERGE / NO-DEPLOY / NO-GO.** Dez gates bloqueados. Não tocar main, VPS, Cloudflare, DNS, produção, Asaas, gastos nem credenciais.
