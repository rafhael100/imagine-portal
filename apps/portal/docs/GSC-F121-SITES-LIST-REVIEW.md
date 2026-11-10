# GSC-F121 — Revisão de propriedades listadas pelo Google por nível de permissão

A documentação oficial da Search Console API define cada `siteEntry` como um `siteUrl` com `permissionLevel`, entre `siteOwner`, `siteFullUser`, `siteRestrictedUser` e `siteUnverifiedUser` ([Google Sites](https://developers.google.com/webmaster-tools/v1/sites); [Sites: list](https://developers.google.com/webmaster-tools/v1/sites/list)).

Esta etapa acrescenta ao domínio PHP uma **verificação sintática fechada** do resultado futuro `sites.list`. A função `containsExactFullAccessCandidate()` só identifica candidato de acesso para uma propriedade `siteUrl` **exatamente igual** à propriedade requisitada e com `siteOwner` ou `siteFullUser`; os níveis `siteRestrictedUser` e `siteUnverifiedUser` não aprovam a política conservadora de conexão. Bloqueia itens duplicados para a mesma propriedade, respostas malformadas, níveis desconhecidos, campos extras, listas excessivamente grandes e IDs não canônicos.

Os testes garantem que `sc-domain:exemplo.com` não equivale a `https://exemplo.com/`, que HTTP/HTTPS, subdomínio e prefixos de caminho não são intercambiáveis e que uma resposta inventada por um cliente **nunca** se torna evidência de titularidade.

**Limite fundamental:** este método recebe um array, sem validar sua procedência. Um array forjado pelo cliente poderia conter `permissionLevel=siteOwner` e passar pela checagem de formato. Isso é esperado e demonstrado em teste; **não se pode conectar esse método diretamente a uma rota ou ação de cliente**. O backend de produção deverá buscar `sites.list` em HTTPS autenticado no Google, associar a resposta ao token mantido em cofre, à sessão Portal/MFA e a um grant empresarial vigente, e aplicar revisão/consentimento.

`verifiesGoogleOwnership()` e `authorizesGoogleAccess()` permanecem `false`. Nenhum Google real, token, chamada de rede, registro persistente, endpoint, deploy, merge main ou gasto.
