# GSC-F156 — Paridade exata SEO RC32 → Portal PHP F155

A cadeia F155 foi estendida para executar a integração real **de código**, e não somente uma amostra PHP fabricada. O workflow `portal-f79-seo-review-ci.yml` agora consulta o commit **imutável** do SEO Engine `f46b65aaef9b10e32d02e415907cd916a43b548e` (RC32) em `.qa/seo-rc32`, mantendo o pin antigo para os consumidores legados. Assim, a mudança não força upgrades dos contratos F50/F59/F65 existentes.

O script `emit_node_query_page_review.mjs` utiliza os módulos verdadeiros do SEO `planOfflineGscQueryPage` e `prepareOfflinePortalQueryPageReview` para emitir quatro cenários sintéticos (sobreposição, linha privada ocultada, página final completa e lote vazio). Os JSONs de laboratório são gravados em um arquivo temporário protegido por `umask 077`, apagado no final, e verificados pelo PHP `verify_php_query_page_review.php`. A prova exige compatibilidade com `OfflineGscQueryPageReviewPreview`, revisão de SHA, contagens consistentes, ausência de permissões, e recusa de adulterações de tenant, status, métricas, redaction e privilégios.

**Limites:** o SHA pinado comprova apenas qual **código** gerou o DTO sintético no CI; não comprova origem de consultas reais, consentimento, acesso à propriedade nem identidade do usuário. Não é liberação de exibição ao cliente, de Google HTTP, OAuth, migrações ou deploy. Os testes continuam `APP_ENV=testing`, sob flags laboratoriais, sem rota HTTP, banco real ou tokens.

CI exigido: Portal F79 em PHP 8.3/8.4 e Node 22/24 (a paridade F156 roda na combinação 8.4/22). Revisão independente AppSec/DBA/LGPD, IAM e homologação em staging continuam gates obrigatórios para qualquer integração operacional.

## F158 — Cenários de duas páginas

O mesmo pipeline F156 agora inclui `multi` (três linhas em duas páginas, sobreposição detectada entre offsets 0 e 2) e `multi_redacted` (a segunda página contém URL parametrizada com token sintético e a revisão é suprimida). A validação cruzada em PHP exige duas páginas, `pageSize=2`, último lote com uma linha, sem continuação nem publicação. O token fictício nunca deve sair no JSON agregado. Dados de testes somente; sem autorização real ou rede.
