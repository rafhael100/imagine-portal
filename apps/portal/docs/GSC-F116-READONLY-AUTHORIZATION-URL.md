# GSC-F116 — Contrato de URL de consentimento OAuth Google (sem endpoint)

## Alteração no código Laravel

A classe `App\\Domain\\SeoReview\\GoogleSearchConsoleAuthorizationUrl` constrói de forma determinística uma **URL de autorização**, não um token ou um grant. O contrato é adequado somente como etapa de preparação de integração, **não pode ser usado como autorização de HTTP**.

Exige:

- Client ID com formato de aplicativo web Google (`*.apps.googleusercontent.com`), sem query, fragmento ou caracteres de controle.
- Callback exata `https://portal.imaginecomunicacao.com.br/oauth/google/callback`; outras URLs, subdomínios, HTTP ou redirecionamentos abertos são recusados. A callback precisa ser registrada futuramente no Google Cloud Console.
- `state` e `code_challenge` distintos, cada um Base64URL canônico de 32 bytes; `code_challenge_method=S256` fixo.
- `scope=https://www.googleapis.com/auth/webmasters.readonly` exclusivamente, `response_type=code` e `include_granted_scopes=false`.
- O emissor confiável futuro é responsável por gerar e persistir `state` e PKCE verifier em ambiente de backend, revalidar MFA/grant/empresa e vincular a propriedade exata. A classe de URL **não verifica a origem** dessas entradas.

Nenhuma credencial de cliente, verifier, refresh token ou access token é incluída na URL. Nenhum `client_secret` é aceito como argumento.

## Segurança — limites

A montagem da URL não faz uma chamada de rede e **não autentica** sessões, usuários ou propriedades Google. O método `authorizesOperationalOAuth()` permanece `false`. Testes Unit verificam parâmetros fechados, callbacks falsificados, IDs de cliente malformados e Base64URL; testes Feature verificam que início, callback e APIs GSC continuam devolvendo 404.

Não foram adicionadas rotas HTTP, middleware, serviços de tokens, credenciais Google, configurações de produção, migrações aplicadas, alterações em `main` ou deploy. A integração futura requer registro do OAuth client, autorização de tenant real, consentimento, callback server-side, token exchange, KMS/vault, revogação, CI E2E e revisão independente de AppSec/DBA.
