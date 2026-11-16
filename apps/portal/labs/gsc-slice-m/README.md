# GSC Slice M — Classificação estrita de resposta de token OAuth

Depois de preparar uma intenção PKCE, consumir seu state uma vez no PostgreSQL e construir o POST autenticado (L), ainda é necessário **validar a resposta da Google** antes de persistir ou usar qualquer token.

`GoogleSearchConsoleOAuthTokenResponsePolicy` é um parser estrito e isolado, **sem realizar HTTP nem verificar origem do provedor**:

- Só considera uma resposta sintética com **HTTP 200**, JSON de no máximo 16 KiB, objeto simples com os quatro campos obrigatórios `access_token`, `expires_in`, `scope`, `token_type` e campo opcional `refresh_token`.
- Exige `token_type=Bearer`, `scope` **exatamente** `https://www.googleapis.com/auth/webmasters.readonly`, `expires_in` inteiro entre 1 e 3600 segundos e tokens ASCII não vazios, sem controles ou caracteres de espaço.
- Rejeita escopos mais amplos, tokens malformados, tokens curtos demais, JSON inconsistente, campos extras de erro/metadados ou `id_token` não solicitado.
- Retorna objeto interno `GoogleSearchConsoleOAuthTokenCandidate`, com os possíveis tokens encapsulados em `SensitiveParameterValue`. `json_encode`, `print_r` e `var_export` não devem expor conteúdo de acesso ou refresh token.
- O método `takeForTrustedVault()` é **somente de backend**, exigindo avaliação independente de origem HTTPS, credencial e grants antes de qualquer utilização.
- Mesmo com resposta sintática válida, `providerOriginVerified`, `googleIdentityVerified`, `tenantGrantVerified`, `propertyAuthorizationVerified`, `tokenPersistenceAuthorized`, `customerDisplayAuthorized` e `publicationAuthorized` continuam **false**.

O laboratório M cobre sucesso com refresh token opcional, bloqueio de scopes ampliados e `error` malicioso, corpo excessivo, erros de tipo, timestamps inválidos e não vazamento. Executado no CI PHP 8.3/8.4.

**Pendente para operação real:** cliente HTTP Google de origem fixa com TLS e verificação de certificados, validação da origem da resposta, política de quotas/retries, cofre cifrado, grants por tenant/propriedade, revogação, logs sem PII e revisão AppSec/DBA/LGPD. Sem rota pública, tokens reais, merge ou deploy.
