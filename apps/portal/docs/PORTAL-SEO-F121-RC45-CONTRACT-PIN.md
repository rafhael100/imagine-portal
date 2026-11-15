# Portal SEO F121 — contrato cruzado atualizado para SEO Engine RC45

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.**

O workflow anterior F119 usava a versão [Engine RC44 #173](https://github.com/rafhael100/imagine-seo-engine/pull/173). A F121 fixa o commit da [RC45 #176](https://github.com/rafhael100/imagine-seo-engine/pull/176), `7847c8c66d5be228a08a94282da562c790cdd440`, sem usar `main`, `latest` ou branch móvel.

A CI do Portal continua chamando a **função real** `reviewMunicipalStaticBuild` do Engine, com build sintético privado no runner, e encaminha o JSON completo para PHP 8.3/8.4. Cenários: build limpo (sempre revisão humana), rota municipal ausente, idioma incorreto, prévia privada no build.

A troca de commit não significa que o Portal esteja conectado a uma VPS nem que o IBGE, o OAuth ou o tenant sejam autenticados. Os resultados continuam bloqueados para uso público. O teste externo do IBGE segue em HOLD conforme a RC45.

Próxima etapa de segurança: distinguir relatório com **emissor verificável** de DTO declarado por operador. Ainda será necessária uma autoridade real de serviço, autorização por empresa, antirreplay e logs operacionais.
