# Portal GSC F118 — proveniência do callback até o transporte local

A F115 provou uma sessão MFA real **com dados fictícios** vinculada
ao estado PKCE, com consumo de uso único em PostgreSQL 18. A F116
comprovou POST HTTP em `127.0.0.1` e parse estrito de token
**sem servidor Google**. Antes da F118 essas provas eram isoladas.

A F118 demonstra a passagem entre os dois componentes com o mínimo
de nova superfície:

1. `OfflinePortalGscOAuthStateStartReview::reviewBoundCallback`
   continua executando a mesma verificação de sessão first-party,
   revisões e `seo.gsc.connect` em PostgreSQL, com row locks.
2. A função `portal_gsc_f115_consume_bound_state` consome o estado
   uma vez, verifica que a sessão ORIGINAL continua válida e
   devolve o verificador PKCE cifrado ao processo PHP restrito.
3. Somente com `PORTAL_GSC_F118_PROVENANCE_LAB=1`, em `APP_ENV=testing`
   e execução CLI, um callback de teste pode receber
   `GoogleSearchConsoleOAuthTokenPostRequest` **criado dentro do
   método após o COMMIT**. O código e o verificador vêm da
   verificação F115; a configuração de cliente/segredo é
   explicitamente **fictícia** no laboratório, sem argumento
   recebido do navegador ou payload de SEO.
4. O envelope é entregue **uma única vez** ao cliente F116, que
   aceita somente `127.0.0.1:18765` e só reconhece token sintético
   com schema/escopo readonly fechados.
5. A saída pública contém somente flags e metadados de laboratório,
   nunca código Google, verificador, bearer, segredo, token ou URL
   da propriedade.
6. Três cenários F104 e os testes anteriores são executados
   com autenticação Argon2id+TOTP, sessão, GSC/F115 PG18,
   competidores que tentam revogar o grant e a sessão, replay e
   logout. Todo o banco é descartável, e migrations são revertidas.

## Restrições

A F118 **não** cria uma capacidade de token Google de produção:
a assinatura do callback aceita um `Closure` apenas sob flag de
teste, não um cliente externo de rede. Não há HTTP callback do
Laravel, nem client ID/secret reais ou token vault. O HTTP é
exclusivamente o mock no loopback; não pode ser mudado por
código/estado/query do usuário.

Mesmo um sucesso F118 não comprova autenticação Google,
propriedade Search Console, consentimento, RBAC de visualização
ou liberação de acesso. O futuro backend deve usar uma capacidade
opaca, não serializável e de uso único, escopo/identidade
Google verificáveis, TLS e origem fixados, secret manager,
auditoria LGPD e rechecagem de grants durante a operação.

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY / NO-CUSTOMER-DATA.**
