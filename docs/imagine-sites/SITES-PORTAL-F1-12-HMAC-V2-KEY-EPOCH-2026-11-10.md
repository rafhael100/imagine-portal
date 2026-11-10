# Portal Sites F1-12 — Emissor candidato HMAC v2 com época de chave

## Objetivo

Adicionar ao repositório do Imagine Portal um emissor PHP **independente** que produza exatamente o envelope sintético v2 esperado pelo Imagine Sites F1-118. A versão atual v1 permanece inalterada; nenhuma requisição ao motor Sites é executada.

O emissor `SyntheticSitesVersionedEnvelopeSigner::sign` reutiliza a validação do contrato CreateProject v1 (`SyntheticSitesEnvelopeSigner`) e acrescenta `keyEpoch` inteiro positivo **dentro dos campos cobertos por HMAC SHA-256**, com `schemaVersion='2'`.

A estrutura v2 é: `schemaVersion`, `issuer`, `audience`, `issuedAt`, `expiresAt`, `nonce`, `payload`, `keyEpoch`, `mac`, nessa ordem. A serialização PHP é JSON compacto com `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`.

## Testes

`apps/portal/tests/Unit/SitesVersionedSignedEnvelopeParityTest.php` valida formato e ordem dos campos, equivalência do payload com a v1, HMAC reproduzível, alteração da assinatura quando apenas a época muda mesmo com a **mesma chave**, e rejeição de época inválida ou identificador de tenant inseguro. A matriz Laravel existente executa PHP 8.3 e 8.4 sem banco nem serviços externos.

A paridade criptográfica **entre** o emissor Portal e o verificador Sites deve ser confirmada pelo workflow cross-repo de um PR separado no Imagine Sites, fixando a revisão exata desta branch.

## Limites

Este emissor usa **chave de teste e epoch informados pelo próprio teste**, sem fonte de confiança autenticada. Não deve ser usado por HTTP, queue, cliente ou serviço operacional; não possui vault, keyId oficial, mTLS/OAuth, revogação confiável, consentimento/aceite ou autorização comercial real. O contrato v2 continua candidato, ainda não homologado. Não há deploy, merge, chaves reais, custo ou liberação de cliente.
