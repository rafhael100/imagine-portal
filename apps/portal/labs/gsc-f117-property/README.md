# Portal GSC F117 — validação conservadora de sites.list, SEM Google HTTP

A API oficial Search Console usa
`GET https://www.googleapis.com/webmasters/v3/sites` e pode devolver
`siteEntry[]` com `siteUrl` e `permissionLevel`. Os valores
documentados são `siteOwner`, `siteFullUser`,
`siteRestrictedUser` e `siteUnverifiedUser`:
[Google Sites:list](https://developers.google.com/webmaster-tools/v1/sites/list),
[sites resource](https://developers.google.com/webmaster-tools/v1/sites).

## O que valida o laboratório

`OfflinePortalGscSiteListEvidenceReview` avalia **JSON sintético** sem
realizar chamadas de rede. Apenas um identificador de propriedade
**exatamente igual** ao solicitado e marcado `siteOwner` ou
`siteFullUser` produz uma prévia de análise. O produto ainda não
decidiu conceder leitura a `siteRestrictedUser`; o contrato de CI
recusa esse nível por padrão até revisão de direitos.

- Reconhece tanto propriedades `sc-domain:` quanto URL-prefix,
  sem equiparar domínios a URLs, remover `www`, reescrever scheme,
  alterar maiúsculas/minúsculas ou seguir redirects.
- Reutiliza o
  `GoogleSearchConsolePropertyIdentifier` **fixado em SHA externa**.
  Exige resposta HTTP 200 informada pelo simulador, JSON não ambíguo,
  corpo até 32 KiB, até 200 entradas, schema fechado com apenas
  `siteEntry` e campos `siteUrl`/`permissionLevel`.
  Entradas duplicadas, incompletas, não canônicas ou com nível
  desconhecido invalidam todo o pacote.
- Não grava `portal_gsc_property_grants`; não retorna URL, token,
  ID de usuário ou quaisquer dados potencialmente identificáveis.
  A saída carrega somente SHA-256 do site e contadores de laboratório,
  com todas as flags de autorização negativas.
- CI PHP8.3/8.4 testa proprietários e usuários completos, acessos
  restritos/não verificados, propriedades trocadas, prefixos, alias,
  duplicatas, status HTTP, corpo grande, JSON inválido e modo de
  produção negado.

## Prova real ainda NÃO existente

Uma lista JSON, mesmo com `siteOwner`, **não prova** que o servidor
que a enviou seja o Google, nem que uma sessão Portal ou token tenha
autorização válida. A F116 simulou a troca de tokens **apenas localmente**,
e a F115 revalidou callback e sessão no banco: estas evidências
ainda não formam uma capacidade de produção.

Antes de persistir qualquer grant de propriedade são necessários
transporte Google HTTPS de origem comprovada, bearer obtido do OAuth
**próprio do Portal** e escopo readonly, consentimento atual,
correspondência de organização e membership, revogação, cofre e
auditoria, revisão DBA/AppSec/LGPD e homologação com backup/rollback.

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY / NO-CUSTOMER-DATA.**
