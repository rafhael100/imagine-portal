# OnePage Portal R19 — negar consulta privada sem contrato autorizado

## Defeito e escopo de laboratório

A candidata [Portal #976](https://github.com/rafhael100/imagine-portal/pull/976) consultava `ApprovedOnepageBriefSnapshotSource::readExact` **antes** de conferir o resultado de `PortalSiteContractSource::findAuthorizedSiteOrder`. Isso permitia observação/efeito colateral na fonte de briefing de um pedido não autorizado, embora o gate lançasse erro ao final. O segundo ciclo repetia a leitura privada mesmo quando o contrato já havia sido revogado ou alterado.

O patch conserva o encoder, o esquema e os retornos `authorityVerified=false`, `clientAccepted=false`, `canPublish=false` e `canAcceptRealOrders=false`. Não cria identidade, escritor, service provider, rota ou fonte operacional.

## Correção

- Primeiro, leia o contrato. Se o contrato não existir, não for `onepage` ou não for do pedido explicitamente solicitado, recuse **sem consultar** a fonte privada.
- Após construir o candidato, verifique primeiro a continuidade exata do contrato autorizado. Uma revogação, mudança de produto, pedido, tenant ou revisão recusa antes da segunda consulta privada.
- Em todos os casos, o erro público continua `ONEPAGE_APPROVED_BRIEF_EXPORT_DENIED`, sem revelar motivo nem dado. A testemunha independente continua obrigatória e o segundo ciclo não é apresentado como atomicidade.

## Cobertura material nova

O teste offline existente exercita, com contadores de chamadas separados: contrato inicial negado (0 consultas de briefing e testemunha), contrato retornado para outro pedido (0), contrato Landing para o pedido de OnePage (0), contrato alterado após a primeira leitura (somente 1 consulta privada) e revogação antes da segunda leitura (somente 1). Permanecem válidas as recusas anteriores para SHA, revisão, contexto, testemunha e bytes alterados.

A CI dedicada da PR draft executa PHP 8.3/8.4 em branch isolada, sem banco, HTTP, dados reais, segredos ou provedor externo; resultados da SHA final devem ser consultados separadamente.

## Limites

Esta prova **não** cria autoridade de compra/revisão. O Portal Laravel ainda precisa de uma fonte oficial de bytes imutáveis, confirmação de direitos do comprador do pedido, testemunha independente de aprovação/revogação e transação/revalidação antes do transporte S2S. OnePage não é Landing Page; nenhuma autorização de um produto foi transferida. Reuso de Portal #1003 / Sites #552 não foi alterado; #541/#542 são históricos. Sites #38 permanece NO-GO. Sem merge, deploy, checkout, VPS, IA real, DNS ou publicação.
