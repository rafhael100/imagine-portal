# Portal SEO — Lote E: paridade de apresentação RC32/F79/PHP F159

Este lote acrescenta um teste de **integração cruzada independente de produção** à cadeia pequena A→B→C→D. O workflow mantém checkout do Imagine SEO **RC32** (SHA `f46b65aaef9b10e32d02e415907cd916a43b548e`) para os contratos estáveis e consulta uma segunda cópia do motor SEO no commit F80 `fdae08985e89b9bd1323e42d1d64678ddc69e446`, que contém a F79, **somente para testes**.

A prova `verify_f79_php_display_parity.mjs` usa o mesmo plano e páginas sintéticas em duas implementações:
- F74/RC32 produz o envelope agregado conhecido do consumidor PHP do Portal.
- F79 produz o novo modelo de apresentação, que suprime métricas por consulta quando há privacidade/redaction ou truncamento da paginação.
- PHP `f159-render-gsc-query-page-review-offline.php` recebe o envelope F74 e deve reproduzir a decisão F79 na tela HTML estática.

Cenários: sobreposição completa, termo pessoal omitido, lote potencialmente incompleto, lote de duas páginas e lote vazio. A CI compara a razão de supressão, os indicadores exibidos, a ausência de termos/URLs brutos, a proibição de JavaScript/links/formulários e as flags de privilégios falsas. A matriz do Portal roda em Node 22/24 e PHP 8.3/8.4.

**Segurança:** dois checkouts de código imutável não comprovam propriedade real, OAuth, consentimento, cliente ou identidade. Nenhum tráfego Google, token, grant, sessão, DB, HTTP de produção, migração, deploy, DNS/301, cobrança ou merge dev/main é habilitado. Este lote permanece **DRAFT/NO-GO**, exigindo CI e revisão independente AppSec/DBA/LGPD antes de qualquer promoção.
