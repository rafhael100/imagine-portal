# Portal OnePage R23 — seleção associativa tipada (23/11/2026, CI-only, NO-GO)

## Preflight antes de escrever

- Portal [#1097](https://github.com/rafhael100/imagine-portal/pull/1097): base `b7a4510f544010f72e83ca27e0c7dafa2c388b27`, 6 workflows SUCCESS para SHA exata; nenhuma candidata R23 ou branch concorrente encontrada.
- Sites [#628](https://github.com/rafhael100/imagine-sites/pull/628): HEAD `95f2a0b9951ea1febe77375684ab301ef4cfa24d`, 4 workflows SUCCESS. Sites #541/#542 são precedentes históricos, não bases reabertas.
- AI Agents [#490](https://github.com/rafhael100/imagine-ai-agents/pull/490): Responses simulado; nenhum worker privado/credencial/egress atestado.
- Branch R23 exclusiva, PR filha da #1097; nenhuma alteração à Landing Page ou à tarefa da VPS.

## Defeito comprovado e correção

O `OnepageApprovedBriefExportGate::sameBrief()` usava `$a->selection === $b->selection`. O selector Sites v0.1 é um **registro associativo com sete campos**, sem semântica de ordem de inserção. Duas fontes aprovadas poderiam devolver os mesmos campos, tipos e valores em ordem diferente, sem alterar contrato, revisão, digest, aprovação ou bytes do briefing, mas o Portal negava indevidamente.

Teste antes da correção: `tests/onepage-approved-selector-order-r23.php`, reutilizando o mesmo contrato, pedido, site, bytes e encoder sintéticos já verificados no boundary original. [Run vermelho 45172819476](https://github.com/rafhael100/imagine-portal/actions/runs/45172819476) falhou **PHP 8.3 e PHP 8.4** na primeira leitura do segundo selector reordenado, após o teste-base passar 50 verificações. HEAD do teste antes da correção: `ca4757b7ab064cb939c5ff28d60ddbfa577ec8d6`.

Correção: comparar **os sete nomes, tipos e valores exatos** por presença e `!==`, sem converter `"0"` em `0`, sem normalizar texto, JSON, hash, referências ou versões. Negativas cobrem alterações `expectedBriefRevision`, `payloadRef` e `idempotencyKey`. As portas autorizadas continuam obrigatórias e a saída mantém os quatro flags de autoridade em `false`.

## Limites e dependências

O código é um contrato **CI-only**: `PortalSiteContractSource`, `ApprovedOnepageBriefSnapshotSource` e `CurrentOnepageBriefApprovalWitness` ainda não possuem implementação operacional comprovada em Laravel/PostgreSQL para o mesmo pedido. Fixture não é fonte oficial; teste não comprova comprador autenticado, consentimento de uso, aprovação/revogação independente transacional, publicação ou aceite de cliente.

Sem credencial segregada e rota/identidade/egress exclusivos para o worker OnePage não houve chamada real OpenAI. Não houve instalação, merge, deploy, acesso à VPS, alterações de main, DNS, Cloudflare, PSP/Asaas, produção ou dados reais. Landing Page continua separada. Sites [#38](https://github.com/rafhael100/imagine-sites/issues/38) permanece NO-GO.

A SHA e os resultados finais de CI serão adicionados à PR #1098 somente depois da execução efetiva.
