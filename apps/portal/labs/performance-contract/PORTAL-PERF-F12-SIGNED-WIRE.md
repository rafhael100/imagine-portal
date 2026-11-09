# PORTAL-PERF-F12 — Contrato de transporte HTTP assinado em laboratório

Issue #106 · branch filha de PR #104, sem merge.

O Portal mantém uma biblioteca PHP **estritamente de laboratório**, `SyntheticWireEnvelope.php`, com assinatura HMAC-SHA256 sobre método POST, destino fixo, versão `perf.wire.synthetic.v1`, key-ID `kid-demo-portal-1`, timestamp (janela 120 segundos, skew 15 s), nonce e SHA-256 do corpo JSON bruto. A assinatura não autoriza nenhuma operação: representa só um formato testável.

O corpo é o evento revogação fictício de PERF-F11 e o destinatário é `/_synthetic/perf/revocation`. O teste PHP CLI executa **32 verificações** de assinatura, adulteração, versão, método/destino, digest, TTL, parsing de headers, duplicidade de header, limites de tamanho e recusa de chave fraca. Chave aleatória gerada em cada execução de CI.

**Atenção de segurança:** HMAC com segredo de teste **não é autenticação de workload real**; não há roteamento Laravel, API operacional, distribuição/rotação de chaves, certificado/mTLS, política de autorização comercial, banco nem usuário real. O receptor opcional do Performance F1-13 ouvirá apenas loopback no CI para testes físicos de transporte e replay; jamais publicar o servidor PHP embutido na VPS ou na internet. Nenhum pagamento, anúncio, cliente ou credencial externa.

PR draft, main/fast-track/produção inalterados, sem merge/deploy. Gates SEC-R03/T3, P0 e HT-R01 ainda abertos.
