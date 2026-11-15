# Portal SEO — Lote B: tendências de sobreposição, somente revisão

Este lote é uma extensão **pequena e revisável** do [Lote A](https://github.com/rafhael100/imagine-portal/pull/398), sem importar a cadeia histórica de centenas de commits do Portal. É um PR empilhado **somente sobre o Lote A**; **não** se deve mesclar os dois conjuntos em duplicidade.

## Conteúdo

- Validador PHP `OfflineGscOverlapTrendPreview` que reconhece os relatórios agregados SEO RC32/F67 e exige status seguro quando há consultas omitidas.
- Teste de integração `emit_node_overlap_trend_rc32.mjs` usando a implementação real do SEO Engine fixada no SHA `f46b65aaef9b10e32d02e415907cd916a43b548e`.
- Verificador PHP que confronta métricas, versões, cobertura, privacidade, sinais e privilégios.
- Prévia HTML `f153-render-gsc-overlap-trend-offline.php` apenas para laboratório; sem links, formulários, JavaScript ou dados de clientes, com CSP e `noindex,nofollow`.
- Três cenários CI: **normal** (sinal editorial humano possível, nunca publicação), **redacted** (CPF fictício ocultado, sem tendência/sinal) e **partial** (amostra incompleta, sem tendência/sinal).
- Workflow consolidado dos Lotes A e B: Node 22/24 e PHP 8.3/8.4, checkout do commit exato da RC32, testes contra escopo alheio, sem credenciais persistentes.

**NO-GO operacional:** nada foi mesclado em dev/main e nenhuma conexão Google/OAuth, sessão, IAM, grant, banco, cobrança, DNS, 301, produção ou despesa foi ativada. Relatórios sintéticos não validam tráfego real ou conformidade LGPD. Requer revisão externa AppSec/DBA/LGPD, staging autenticado e autorização específica de integração antes de promoção.
