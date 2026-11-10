# GSC-F119 — Configuração OAuth Google fechada por padrão

Adicionada ao Laravel uma configuração explícita de integração Search Console que **nasce desativada**. Os controles de autorização, callback, token exchange, armazenamento de tokens e consulta de propriedades permanecem `false`, sem nenhuma variável de ambiente capaz de ativá-los isoladamente.

O arquivo fixa para revisão os endpoints públicos do Google, callback HTTPS do Portal, escopo único `webmasters.readonly` e método `PKCE S256`. Não contém client ID, client secret ou tokens reais, e marca as origens de sessão, grant empresarial, autoridade da propriedade e cofre de segredos como `unconfigured`.

### Testes e objetivo

Os testes Feature confirmam que todas as capacidades operacionais estão desativadas mesmo com uma variável de ambiente de habilitação presente, que não há credenciais de Google neste arquivo, que os contratos F116–F118 não habilitam OAuth e que as rotas permanecem 404. Com isso, fica explícito o bloqueio antes de configurar o aplicativo Google e integrar o backend confiável.

### Requisito ainda não satisfeito

Esta configuração é uma *barreira de inicialização e revisão*, não substitui autenticação/CSRF, validação do grant `seo.gsc.connect`, estado atômico F111/F112, propriedade Google verificada, troca HTTPS do código por token, vault criptografado, revogação e auditoria AppSec/DBA. Em uma implementação futura, cada endpoint deverá validar esses requisitos independentemente; nunca basta trocar um `false` por `true`.

A configuração está apenas em PR de revisão. Sem ativação real, deploy, alterações em main ou custos de fornecedores.
