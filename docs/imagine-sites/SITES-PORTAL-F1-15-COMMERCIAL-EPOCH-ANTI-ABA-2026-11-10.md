# Portal Sites F1-15 — Revisão independente de autorização comercial (anti-ABA)

## Risco

O emissor Portal F1-14 compara duas leituras do pedido/contrato antes de entregar uma HMAC v2. Isso detecta cancelamento, mudança de empresa ou revisão **quando os dados da segunda leitura são diferentes**. Não detecta um evento de revogação seguido de restauração que devolva o **mesmo snapshot A→B→A** durante a emissão.

## Implementação candidata

Introduzido `PortalSiteCommercialEpochSource::currentEpochFor(orderRef)`, com `DenyingPortalSiteCommercialEpochSource` como implementação padrão que retorna `null` (DENY).

`LabPortalSitesVersionedIssuer` agora consulta uma revisão inteira positiva **separada do próprio snapshot comercial** logo após a primeira leitura do contrato, antes da chave HMAC/assinatura; após revalidar segredo e época da chave e reexecutar o comando comercial, consulta essa revisão novamente e exige identidade exata. Ausência, valor inválido, erro ou divergência → nega sem devolver envelope.

A época de autorização comercial é conceitualmente **distinta** da época da chave de assinatura. Esse controle complementa, mas não substitui, a rechecagem final do Sites antes do COMMIT.

## Prova automatizada

A suíte `LabPortalSitesVersionedIssuerTest.php` acrescenta fixture de geração comercial e cenários em PHPUnit PHP 8.3/8.4:
- revogação e restauração do mesmo snapshot durante a assinatura, acompanhada de incremento da época: nenhuma emissão;
- nova tentativa com revisão corrente pode emitir somente um novo envelope sintético;
- fonte de época indisponível/ausente nega **antes** de consultar material de assinatura;
- cenários F1-13/F1-14 de chave, tenant, revisão de contrato e pedido revogado permanecem intactos.

## Limites

As épocas de testes são variáveis em memória: **não** demonstram monotonicidade, auditabilidade ou revogação real. Um provedor comprometido, obsoleto ou restaurado de backup pode retornar uma época antiga. Se a revogação ocorrer após a última consulta, essa verificação não a captura. O envelope v2 ainda não contém uma época comercial autenticada; a geração é controle interno de emissão e não uma prova independente verificável pelo Sites. É necessário negociar um contrato operacional que vincule autorização por pedido e cliente a um emissor autenticado, com histórico/revisão fora do domínio de restauração.

Sem endpoint, IAM oficial, vault, HTTPS, mTLS/OAuth, cliente, aceite, merge, deploy, VPS ou custo. **10/10 gates de produção BLOCKED.**
