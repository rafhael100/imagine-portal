# Portal Sites F1-16 — Envelope HMAC v3 autenticando a época comercial

## Motivação

A F1-15 detecta revogação/restauração A→B→A de um pedido durante a emissão consultando revisão comercial independente. Porém, o envelope v2 só contém `keyEpoch` (revisão da chave de assinatura). Depois de emitido, o Imagine Sites não consegue verificar a **época comercial da emissão** contra uma fonte independente.

## Implementação candidata

`SyntheticSitesCommercialEpochSignedEnvelopeSigner` produz um envelope sintético **v3** com `schemaVersion='3'` e os campos `schemaVersion`, `issuer`, `audience`, `issuedAt`, `expiresAt`, `nonce`, `payload`, `keyEpoch`, **`commercialEpoch`**, `mac` na ordem exata.

O campo `commercialEpoch` é inteiro positivo e faz parte dos bytes autenticados por HMAC SHA-256. Alterá-lo sem recalcular a MAC invalida a assinatura. O emissor protegido `LabPortalSitesVersionedIssuer::issueCommercialEpochSigned` **não aceita época ou chave informadas pelo chamador**: obtém ambas de fontes candidatas injetadas e default DENY, conserva a rechecagem final da chave, do contrato e da revisão comercial.

O método legado `issue` mantém a emissão v2 somente para os testes anteriores. O novo v3 não ativa HTTP, queue, cliente ou deploy.

## Testes e limites

A suíte PHPUnit PHP 8.3/8.4 verifica a estrutura v3, HMAC de `commercialEpoch`, revisões positivas, proteção contra mutação e negação de revogação/restauração durante emissão. O verificador Sites candidato será testado em PR específico com checkout Portal fixo.

As fontes de revisão são **variáveis sintéticas em memória**: não são IAM operacional, ledger imutável, vault, certificado de serviço, OAuth/mTLS, contrato juridicamente aceito ou autorização de publicação. O HMAC simétrico comprova conhecimento da chave, não identidade independente. O Sites precisa consultar época comercial **corrente e confiável** e sua própria autorização antes de gravar. É possível revogar após o último check; não há transação distribuída.

**10/10 gates de produção BLOCKED.** PR draft, sem merge em dev/main, rede de cliente, VPS, PII ou novos custos.
