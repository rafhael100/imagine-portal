# Portal GSC-F138 — Contrato real Node SEO-F54 → PHP, somente no laboratório

O Portal passa a validar relatórios comparativos offline `seo-gsc-daily-comparison-offline-v1` do motor Imagine SEO. A CI obtém a revisão imutável do SEO Engine F55, produz um JSON de duas janelas de dados *fictícios* e consome exatamente essa saída pelo validador PHP do Portal.

A validação recusa permissões falsificadas, outros tenants, fingerprint diferente, campos extras, datas inválidas e sinais de queda quando a cobertura é parcial. Todos os métodos de autorização real retornam falso. O novo componente só aceita PHP CLI com `APP_ENV=testing` e `PORTAL_GSC_COMPARISON_LAB=1`. Não é registrado como rota, service provider, endpoint ou API.

A conexão de domínio GSC disponível no OpenSEO **não** confere autenticação ao Portal. Nenhum tráfego real, token, cookie, consentimento, cliente, servidor ou DNS é modificado. Deploy, merge na `main` e publicação continuam **NO-GO** até IAM/MFA, backend seguro, revisão externa e homologação.
