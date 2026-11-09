# Portal Comercial — Emissor PHP do catálogo publicável (F1, laboratório)

## Motivação
O Imagine Comercial já valida snapshots Ed25519 no build Astro. Este recorte fornece a função PHP pura correspondente, **sem criar API nem uma operação de publicação real** no Portal.

## Contrato
- `apps/portal/app/Domain/CommercialPublishing/PublicCatalogSigner.php`.
- Entrada: snapshot público previamente aprovado e validado, `keyId` conhecido, chave secreta Ed25519 **fornecida de ambiente confiável no momento da emissão**.
- Saída: envelope `imagine-catalog-v1` com `keyId`, `payloadBase64` e `signatureBase64`; assinatura sobre `imagine-commercial-catalog-v1\0` + bytes JSON exatos.
- Rejeita campos privados, rascunhos, IDs inválidos, preços, dados de clientes e itens duplicados.
- Teste cruzado: PHP gera envelope com chave efêmera; verificador JS do Imagine Comercial (SHA fixo) valida, e adulteração falha.
- Não armazenar chave secreta, nem imprimir valores, e não inserir chave em repo público.

## Limites e próximos gates
- Não há endpoint HTTP, autenticação/autorização efetiva, RBAC/multiempresa real, MFA, aprovação editorial, transações, outbox, rotação/revogação de chave, anti-replay em banco ou publicação.
- O emissor **não certifica autorização por conta própria**: somente um serviço futuro de publicação do Portal, depois de verificar vínculo de organização, papel, status, aprovações e escopo, poderá chamá-lo.
- Não habilitar CMS real, valores, contratos, notas fiscais, checkout, domínio ou Cloudflare.
- PR em draft empilhado na branch isolada do Portal, sem merge na main.

Repositório consumidor: https://github.com/rafhael100/imagine-comercial/pull/11
Issue do Comercial: https://github.com/rafhael100/imagine-comercial/issues/4
