# Portal Sites F1-14 — Revalidar a elegibilidade comercial antes de devolver a assinatura

## Problema

O emissor candidato da F1-13 já consulta segredo+época em uma fonte independente e revalida o material imediatamente antes de retornar o HMAC v2. Mas o pedido comercial era consultado apenas no início. Se o contrato fosse cancelado, mudasse de revisão ou transferido de empresa **durante a assinatura**, o Portal poderia devolver um envelope usando os dados antigos.

## Alteração laboratorial

Após construir o primeiro comando e gerar a assinatura, `LabPortalSitesVersionedIssuer::issue` revalida a fonte de material de chave e **reexecuta `SitesCreateProjectEncoder::build` com os mesmos identificadores**, obtendo um segundo comando comercial. Só devolve o envelope se esse segundo comando for **exatamente igual** ao original. Uma revogação, erro da fonte comercial, alteração de empresa, pedido, produto ou revisão contratual nega a emissão, sem produzir um envelope retornado ao chamador.

A segunda leitura ocorre depois da validação de segredo+época e é feita contra a fonte comercial injetada (padrão DENY). O chamador ainda não fornece segredo, `keyEpoch`, empresa ou contrato diretamente ao método `issue`.

## Testes

`apps/portal/tests/Unit/LabPortalSitesVersionedIssuerTest.php` ganhou casos que mutam a fonte comercial fictícia entre a geração HMAC e o retorno, verificando:
- revogação do pedido → DENY e duas consultas comerciais;
- mudança de revisão contratual → DENY;
- mudança de empresa associada ao pedido → DENY, evitando emissão de token vinculado ao tenant anterior;
- recuperação posterior da fonte → somente uma nova emissão sintética válida;
- testes anteriores de alteração de material HMAC e default DENY mantidos.

A matriz Laravel PHP8.3/8.4 continua executada pelo workflow existente, sem banco ou rede real.

## Limites

Não é uma transação atômica. Uma revogação posterior à última consulta ainda pode ocorrer, e a autoridade pode retornar dados obsoletos. A fonte atual é in-memory, **não** prova propriedade de recurso, aceite do cliente, pagamento, assinatura jurídica nem autenticação Portal oficial. Os Sites ainda revalidam sua autorização no próprio lado antes do COMMIT. O desenvolvimento operacional precisará de fontes duráveis, revisão monotônica, IAM real e auditoria AppSec independente.

Tudo permanece em PR draft empilhado, sem deploy, merge em main/dev, dados de cliente, DNS, VPS ou custos. Os dez gates operacionais seguem BLOCKED.
