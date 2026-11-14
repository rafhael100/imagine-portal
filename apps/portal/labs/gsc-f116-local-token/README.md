# Portal GSC F116 — ensaio de transporte de token em HTTP local, sem Google

**A F116 NÃO é integração OAuth de produção.** O laboratório valida o
limite entre a requisição POST form-urlencoded e a resposta JSON que um
serviço de token Google poderia produzir. Nenhuma credencial Google,
propriedade, usuário, grant real, endpoint público, secret manager ou
VPS foi acessado.

## Implementação

- `OfflinePortalGscLoopbackTokenTransportReview` usa o contrato
  `GoogleSearchConsoleOAuthTokenPostRequest` existente, mas envia
  **exclusivamente** para `http://127.0.0.1:18765/token` no runner
  descartável de CI. A URL não é um parâmetro e o código recusa
  `APP_ENV` diferente de `testing` e a ausência da flag explícita
  `PORTAL_GSC_F116_LOOPBACK_LAB`.
- O cURL exige POST, formulário RFC3986, sem redirecionamentos,
  sem proxy, sem payload maior que 8 KiB, com tempo máximo local de
  3 segundos e limite de corpo da resposta de 16 KiB. O servidor local
  é iniciado com a interface **127.0.0.1**; nunca `0.0.0.0`.
- O parser de resposta original da cadeia GSC valida JSON com esquema
  fechado: `access_token`, `expires_in`, `scope` exato
  `https://www.googleapis.com/auth/webmasters.readonly`,
  `token_type=Bearer` e refresh token opcional. Outros escopos,
  status diferente de 200, tipo, prazo ou campos extras são recusados.
- Tokens e código de autorização não são retornados, armazenados,
  colocados em log ou serializados. O envelope existente é de uso único.
  A resposta segura define, sem ambiguidade, `providerOriginVerified`,
  `tokenPersistenceAuthorized` e `customerDisplayAuthorized`
  como `false`.
- O simulador local retorna sucessos *sintéticos* com e sem refresh
  e vários casos hostis: 302, HTTP 401/500, JSON inválido, oversized,
  token de tipo incorreto, expiração impossível, excesso de campos,
  scope fora da allowlist, conteúdo textual, token com controle,
  resposta vazia e tentativas de reuso.

## Relação com a F115

A F115 demonstra que o estado OAuth é consumido **somente depois de
revalidar a sessão original MFA** e o grant `seo.gsc.connect`, mas
deliberadamente NÃO entrega um objeto com autorização para transporte
de código/verificador. A F116 exercita com um envelope **totalmente
sintético separado**, não um callback de cliente. Não é correto unir
esses dois caminhos com um DTO ou string do navegador.

A fase de integração deve introduzir uma capacidade opaca, criada
**exclusivamente** depois do consumo transacional F115, que não possa
ser falsificada, clonada, repetida ou enviada por rede/log, e só possa
ser entregue ao cliente de troca Google depois da revisão de segurança.

## Gates operacionais

- [ ] Implementar camada real Google HTTPS com URL fixa
  `https://oauth2.googleapis.com/token`, TLS verificado, política
  anti-redirect/SSRF, timeouts e tratamento de erros auditados.
- [ ] Projeto Google Cloud, client ID/secret, redirect URI exato e
  consentimento do aplicativo Imagine Portal configurados em
  secret manager, nunca em GitHub/chat/arquivo do código.
- [ ] Callback HTTPS autenticado com MFA, política de cookies,
  CSRF/origin e state session-bound da F115; conta Google e
  autorização atual à propriedade confirmadas via API oficial.
- [ ] Vault isolado por tenant, refresh-token lifecycle, revogação,
  auditoria e retenção LGPD; `seo.gsc.view` continua distinto de
  `seo.gsc.connect`.
- [ ] Revisão AppSec/DBA/LGPD, homologação controlada, backup,
  restore/rollback e aprovação explícita para release.

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY / NO-CUSTOMER-DATA.**
