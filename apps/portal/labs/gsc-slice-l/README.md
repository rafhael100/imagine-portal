# GSC Slice L — Planejamento da troca OAuth, sem executar HTTP

O Lote L prepara a **requisição POST de troca do código OAuth**, mas não cria cliente HTTP, endpoint público ou integração com credenciais reais. O formato é baseado na documentação Google de aplicativos web e no endpoint fixo `https://oauth2.googleapis.com/token`.

## Fluxo integrado

Após a classificação do callback (H), consumo **atômico** do state no PostgreSQL (I/J) e revalidação do contexto servidor, `GoogleSearchConsoleOAuthTokenExchangePlan` recebe o `GoogleSearchConsoleOAuthCallbackOutcome` interno e uma configuração confiável contendo `clientId`, `clientSecret` e `registeredRedirectUri`.

- Refatorado o preflight G para expor `validRegistration` apenas como **validação sintática** de `clientId` e callback HTTPS com caminho fixo; isso não prova que o URI esteja cadastrado no Google Cloud.
- Rejeita retornos de consentimento negado, códigos inválidos, verificador PKCE inválido, secrets vazios, parâmetros desconhecidos, redirect diferente do contrato e tentativas de injetar headers.
- Gera um `GoogleSearchConsoleOAuthTokenPostRequest` com campos exatos `client_id`, `client_secret`, `code`, `code_verifier`, `redirect_uri` e `grant_type=authorization_code`.
- O resultado é um objeto protegido com `SensitiveParameterValue` contendo o formulário; logs, `json_encode`, `print_r` e `var_export` **não contêm código, secret ou verifier**.
- `takeForTrustedHttpsTransport()` só deve ser invocado por um futuro serviço backend revisado com TLS, sem redirecionamentos, timeout 10 segundos e diagnóstico sem dados privados.
- `networkPerformed=false`, `googleTokenVerified=false`, `tokenStorageAuthorized=false` e `customerDisplayAuthorized=false` até validação do servidor real.

## Provas

Testes PHP verificam URL/método, formulário correto, segredo opaco, bloqueio de configurações hostis, CR/LF, consentimento negado, alteração de escopo e ausência de dados em logs. O laboratório PostgreSQL do Lote J também verifica que **um código proveniente de state consumido** produz o formulário exato, sem enviar rede.

## Gates necessários

Credenciais reais nunca devem aparecer no repositório, CI, texto de PR ou respostas de cliente. Antes de executar o plano: configuração Google OAuth registrada, grants por tenant/propriedade, sessão MFA/RBAC, transporte HTTP fixado e auditado, controle de timeout/quota/SSRF e inspeção estrita da resposta do provedor (escopos, validade, erros). A resposta e eventual refresh token exigem vault cifrado e política de revogação/retention separados.

**DRAFT/NO-GO:** sem HTTP Google, token real, merge em dev/main, migration produtiva, deploy, DNS/301 ou cobrança.
