# GSC Slice H — classificação isolada de retorno OAuth, sem tokens

Após o preflight PKCE do Lote G, `GoogleSearchConsoleOAuthCallbackPolicy` verifica a forma da **resposta de redirecionamento OAuth** contra o registro secreto do servidor e o contexto autenticado que futuramente será recuperado do Portal.

## Contrato

- Requer `state` válido e EXATAMENTE um de `code` ou `error`, negando parâmetros desconhecidos como `client_secret`, `redirect_uri` externo e `error_description` de fonte não confiável.
- Aceita os parâmetros opcionais `scope` somente quando equivalem ao `webmasters.readonly`, `authuser` numérico delimitado e `prompt` dentro dos valores conhecidos. Nenhuma alegação presente na resposta comprova os privilégios reais dos tokens.
- Não aceita códigos vazios, excessivamente longos, com CR/LF, formato de header ou conteúdo estruturado. O código é **opaco e jamais retornado, registrado, persistido ou ecoado** por esta classe.
- Exige correspondência do `state` com hash no registro criado no preflight, operador, empresa, TTL e `consumed=false`.
- Resposta de código é `CODE_RECEIVED_REQUIRES_ATOMIC_STATE_CONSUMPTION`; erro de consentimento, `CONSENT_DENIED_HOLD`; demais erros, `PROVIDER_ERROR_HOLD`. Todos os caminhos exigem que o backend de produção consuma o state **uma única vez** antes de tratar a callback.
- Flags `canExchangeTokens`, `oauthVerified`, `customerDisplayAuthorized` e `publicationAuthorized` permanecem **false** em todos os casos.

## Testes

`verify_callback_policy.php` valida fluxo simulado com callback válido, opcionais esperados, erros, escopo excessivo, injeções, parâmetros extras, estado inválido ou expirado, cross-tenant, ator diferente e tentativa de reusar registro marcado consumido. A suíte é executada em PHP 8.3/8.4 na matriz do Portal, com `APP_ENV=testing` e `PORTAL_GSC_CALLBACK_LAB=1`.

## Próxima implementação necessária

A função **não** implementa rota HTTP OAuth, troca de tokens, registro seguro da PKCE, consumo atômico real no banco, MFA, consentimento de propriedade Google, grant por empresa ou persistência de credenciais. O backend deve recuperar e consumir o state numa operação transacional de uso único, revalidar sessão/grants e então usar um cliente Google HTTPS limitado e auditado, protegendo tokens com criptografia e revogação.

**NO-GO:** sem produção, Google real, token ou autorização de cliente; sem merge em `dev/main`, deploy, DNS/301 ou custos. Revisão independente AppSec/DBA/LGPD continua exigida.
