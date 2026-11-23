# OnePage Portal R20 — revogação verificada antes da segunda consulta privada

## Problema demonstrável (candidata offline)

A [R19](https://github.com/rafhael100/imagine-portal/pull/1094) verifica o contrato antes de cada `ApprovedOnepageBriefSnapshotSource::readExact`. Porém, na segunda passagem, lê o briefing privado **antes** de consultar `CurrentOnepageBriefApprovalWitness::isCurrent`. Se a aprovação tivesse sido revogada entre a primeira e a segunda passagem, o gate negaria o resultado, mas já teria efetuado a leitura da fonte privada revogada.

## Recorte implementado

- Mantida a validação de contrato da R19, agora revalida a aprovação da revisão já conhecida **antes** da segunda consulta privada. Revogação nesse momento impede a consulta; nenhuma resposta positiva é gerada.
- Uma terceira checagem de aprovação **depois** da segunda leitura preserva o veto se a revogação ocorrer durante essa consulta. Não cria atomicidade nem autorização comercial.
- Regresso isolado com fontes fictícias: revogação exatamente na segunda consulta do witness causa uma única leitura de briefing; revogação durante a segunda leitura causa negativa no witness final. O caminho positivo requer três consultas ao witness, dois reads de contrato e dois do briefing.
- Preserva `authorityVerified=false`, `clientAccepted=false`, `canPublish=false`, `canAcceptRealOrders=false`, esquema e encoder; sem nova rota, fonte oficial, tenant, identidade ou seleção de `latest`.

## Limitações / dependências

Somente laboratório e testes PHP 8.3/8.4; sem fonte real, banco, HTTP, credencial, OpenAI, pagamentos ou implantação. A sequencia de leituras não substitui transação/snapshot consistente nem testemunha oficial para o mesmo pedido, revisão, bytes e SHA. O contrato Portal #976 mantém default-deny; o formato Portal #1003/Sites #552 é reutilizado sem alteração; #541/#542 são históricos. A Landing Page continua independente. Sites #38: **NO-GO**.
