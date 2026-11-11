# GSC-F137 — Tela estática de monitoramento orgânico em laboratório

Renderiza, apenas em PHP CLI com `APP_ENV=testing`, `PORTAL_GSC_WORKFLOW_LAB=1` e `PORTAL_GSC_HTML_LAB=1`, uma prévia HTML com métricas agregadas de um DTO sintético do SEO Engine F50. O conteúdo contém indicadores fictícios, aviso explícito de demonstração, metatags de noindex/nofollow, CSP sem requisições externas, sem formulários, scripts ou links.

A CI usa o SHA fixo do SEO Engine, produz a saída F50 com Node, valida a forma via PHP F135 e testa o HTML. Não há rota de produção, OAuth, Google real, tokens, banco, dados de clientes, deploy ou merge em main. A prévia não é evidência de permissão, consentimento, veracidade do tráfego ou liberação de negócio.
