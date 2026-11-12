# Portal GSC — Lote G: preparação de OAuth com PKCE, sem autenticação real

## Escopo

Novo componente PHP `GoogleSearchConsoleOAuthPreflight` para **preparar** uma intenção OAuth 2.0 com PKCE S256 e avaliar um estado CSRF recebido contra um registro de curta duração recuperado de uma fonte confiável no futuro. O escopo de autorização é **exclusivamente** `https://www.googleapis.com/auth/webmasters.readonly`, conforme documentação oficial do Search Console. O endpoint fixo é `https://accounts.google.com/o/oauth2/v2/auth`.

Este módulo **não** implementa login, callback HTTP, consentimento real, troca de código por tokens, gravação de refresh token, sessão MFA ou acesso a propriedades. Não é uma implantação de OAuth funcional.

## Preparação offline

`prepare(config,context,now)` aceita somente configuração de cliente Google recebida de um **backend confiável** (`clientId`, `registeredRedirectUri`) e contexto autenticado que **deverá** ser fornecido pelo backend (`tenantRef`, `actorRef`).

- A URI de callback deve ser HTTPS, sem porta, credenciais, query, fragmentos, percent-encoding ou caminho variável. O caminho fixo é `/integrations/google/search-console/callback`. O componente **não comprova** que o URI foi registrado no Google; essa verificação é externa e obrigatória.
- Gera `state` e `codeVerifier` independentes com `random_bytes(32)` e desafio `BASE64URL(SHA256(verifier))`, no modo S256. O URL de autorização público contém somente o desafio, jamais o verificador.
- A seção **serverOnly é secreta**: contém `codeVerifier`, SHA-256 do state, tenant, operador, tempo de emissão e vencimento de 600 segundos. **Nunca enviar o objeto retornado inteiro ao navegador nem registrar sua seção serverOnly em logs.** Uma integração real exigirá armazenamento criptografado e expiração efetiva.
- A verificação `matchesStoredState` só compara estado com registro fornecido pelo backend, vínculo de tenant/ator, tempo e flag `consumed=false`; **ela não consome o registro**. O controle de utilização única, sessão/MFA e permissões continua obrigatório no backend antes de qualquer troca de código.
- Todas as flags `oauthVerified`, `tenantGrantVerified`, `propertyAuthorizationVerified`, `callbackAuthenticated`, `tokenExchangeAuthorized`, `customerDisplayAuthorized`, `networkPerformed` permanecem `false`.

## Testes

`verify_pkce_preflight.php` usa apenas valores sintéticos e exige `APP_ENV=testing` e `PORTAL_GSC_PKCE_LAB=1`. Confere escopo somente de leitura, URL e parâmetros esperados, aleatoriedade e S256, segregação do verificador, comparação temporal e de estado, rejeição de estado reutilizado (flag consumido no registro), cross-tenant, troca de operador, callbacks HTTP, hosts malformados, query/fragments, URI com credenciais, parâmetros extras e timestamps inválidos.

CI: PHP 8.3/8.4 × Node 22/24, matriz existente de integração A–G. Nenhum recurso real Google, token ou cliente é usado.

## Requisitos ainda bloqueadores

1. Sessão Portal verificada com MFA e RBAC, integração com grants por empresa e vinculação da propriedade Google.
2. Configuração credencial Google registrada no Cloud e validação exata do callback sob controle do backend.
3. Persistência segura de estado/verificador com expiração e consumo atômico, proteção CSRF, anti-replay e isolamento multiempresa.
4. Implementação posterior e revisada da troca HTTPS do código OAuth, token vault, revogação, retenção, auditoria sem segredos e rollback.

**NO-GO operacional** e **DRAFT**: sem merge `dev/main`, autenticação real, HTTP, OAuth de cliente, banco, deploy, DNS/301 ou custo adicional. Revisão independente AppSec/DBA/LGPD e homologação em staging são indispensáveis.
