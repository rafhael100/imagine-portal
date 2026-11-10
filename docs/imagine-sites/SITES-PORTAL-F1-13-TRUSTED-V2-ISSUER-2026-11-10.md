# Portal Sites F1-13 — Fronteira de confiança para emissão HMAC v2

## Problema

No emissor sintético F1-12, o chamador fornece tanto a chave HMAC quanto a geração `keyEpoch`. O contrato demonstra paridade, mas **não estabelece a autoridade do emissor**; em uma integração operacional, o solicitante não pode definir os próprios dados de assinatura.

## Implementação laboratorial

`LabPortalSitesVersionedIssuer` aceita somente identificadores de pedido/operação/idempotência, nonce e intervalo de validade. Obtém o comando do `SitesCreateProjectEncoder` — cuja fonte comercial padrão é `DenyingPortalSiteContractSource` — e obtém **segredo e época no mesmo registro** de `PortalSitesSigningMaterialSource` injetada, cuja implementação padrão `DenyingPortalSitesSigningMaterialSource` retorna `null`.

`CandidatePortalSitesSigningMaterial` exige emissor `imagine-portal`, audiência `imagine-sites`, chave sintética de no mínimo 32 bytes e geração positiva. A fonte é consultada outra vez **após calcular** o envelope HMAC v2; alteração de bytes ou época, ausência de material ou exceção nega antes de devolver um envelope. Esse teste detecta revogação/rotação observável durante a emissão, mas não é um lock transacional de uma autoridade distribuída.

O chamador não fornece parâmetros de chave ou `keyEpoch` ao novo método `issue`. O emissor sintético subjacente F1-12 permanece disponível diretamente para fixtures/testes — não como endpoint HTTP.

## Testes

`apps/portal/tests/Unit/LabPortalSitesVersionedIssuerTest.php` valida:
- negação padrão sem fonte comercial e/ou sem material de assinatura;
- paridade do envelope v2 com o emissor F1-12 e ausência do segredo na resposta;
- recusa por revogação/erro da fonte;
- troca A→B→A com época alterada entre duas leituras, mesmo com segredo restaurado;
- segredo alterado com época igual;
- pedido comercial inexistente negado **antes** de consultar chaves.

Os testes integram a matriz Laravel existente em PHP 8.3 e 8.4, sem banco e sem rede.

## Limites e promoção

A fonte usada no teste é uma fixture em memória; **não autentica Portal IAM**, contrato, cliente, pagamento, aceite ou propriedade de domínio. O Portal ainda não possui fonte oficial de chave/época durável, vault, mTLS/OAuth, fluxo aprovado de rotação, endpoint de serviço, registro de auditoria real nem autenticação ponta a ponta. O `keyEpoch` continua gerado por fonte não operacional. A verificação de duas leituras não cobre alteração posterior à emissão.

Próxima prova: integrar o novo `LabPortalSitesVersionedIssuer` com o verificador v2 do Imagine Sites por checkout de SHA fixa em teste cross-repo. Nenhum merge, deploy, chave real, VPS, DNS, dados de cliente ou novo gasto foi executado. Todos os gates operacionais continuam bloqueados.
