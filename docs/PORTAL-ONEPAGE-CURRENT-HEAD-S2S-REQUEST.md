# Portal → Sites: corpo canônico do pedido de head Onepage (candidata)

Data: 2026-11-19. Continuação isolada da [PR Portal #998](https://github.com/rafhael100/imagine-portal/pull/998), HEAD `cd0ce62052727b4df12813858d2244a7e9f06a3d`, sem modificar a branch anterior.

## Implementação

`OnepageCurrentHeadS2sRequestCandidate::decode()` valida o corpo da **futura** requisição privada `POST /internal/sites/v1/brief-head/current`, no formato exato enviado pelo candidato `PortalOnepageHeadMtlsHttpSource` da [PR Sites #551](https://github.com/rafhael100/imagine-sites/pull/551), pinada em `49e6f0a8da848ebf50b6f507d2d3cec6ae65dc97`.

O corpo JSON deve ter, nessa ordem, somente `schemaVersion`, `tenantRef`, `orderRef`, `siteRef` e `productCode`, com schema `sites.onepage-current-head.fetch.v1` e produto `onepage`. O parser nega conteúdo fora do formato canônico, duplicação de chaves, campos extras de autoridade, requisições truncadas/maiores que 1024 bytes, referências inválidas, produtos diferentes e encodings alternativos. Ele devolve apenas três seletores para uma fonte **autorizada por outro componente**. Não aceita `authenticatedServiceRef` no corpo.

A validação canônica é propositalmente estrita porque existe um único produtor conhecido. Clientes HTTP genéricos não têm promessa de compatibilidade. Uma alteração no contrato exigirá nova versão e testes cruzados, e não interpretação permissiva de payloads.

## Restrições e gates

**O parser não autentica ninguém.** Um futuro endpoint deve derivar a identidade exclusivamente de mTLS verificado, autorizar o serviço em tenant/pedido/site, proteger contra replay, aplicar transação/CAS de aprovação e revogação em fonte oficial e responder por `OnepageCurrentHeadS2sResponseCandidate`. Não existe controller, route ou ledger oficial nesta PR. Não há operação HTTP, banco, acesso real, aceites, pagamento ou publicação. Os dez gates operacionais continuam bloqueados. `DRAFT / NO-GO / NO-MERGE / NO-DEPLOY`.

A CI em PHP 8.3 e 8.4 exercita negativas do request e mantém a paridade com o parser de resposta imutável do Sites. Não são evidências de identidade, autorização ou homologação operacional.
