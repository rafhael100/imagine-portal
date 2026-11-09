# Portal F1-48 — consumidor experimental de propostas assinadas Imagine Sites

Esta etapa acrescenta o **lado Portal do contrato sintético Sites → Portal** das etapas Sites F1-45/46, como código PHP puro de laboratório. É uma proposta de revisão: **não cria uma aprovação, publicação, entrega, assinatura comercial ou sessão de cliente**.

O consumidor verifica campo e ordem canônicos, emissor Sites, destinatário Portal, validade máxima de 120 segundos e no máximo 5 segundos de folga, nonce, SHA-256, HMAC e as flags obrigatórias `portalAuthorityRequired=true`, `officialClientAcceptance=false`, `publishable=false`. Somente depois consulta `ReviewHandoffAuthoritySource` para snapshot **independente e atualizado** (tenant, site, workflow, revisão, release e digest). Se autorizado, usa um `ReviewHandoffNonceFence` atômico para impedir replay.

**Ambos os adaptadores negam por padrão.** No CI, fontes e nonce fences de teste em memória representam comportamento de integração sem fornecer identidade/eligibilidade real. Nenhuma rota Laravel pública ou API foi acrescentada.

A verificação HMAC não confere identidade de serviço, contrato ativo, aceite expresso, pagamento ou autorização de cliente; o adaptador oficial ainda não foi definido e seus gates P0/RF-PEND/RV-CT/AppSec/LGPD seguem abertos. Nenhum conteúdo confidencial, cliente, VPS, Cloudflare, custo novo ou deploy. Código em branch isolada baseada no PR Portal #105, sem merge ou promoção de main.
