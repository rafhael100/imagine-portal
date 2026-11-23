# OnePage Portal → Sites R22 — valores opacos estritos (CI-only, NO-GO)

## Defeito observado na R21

A comparação PHP de objetos com `!=` verifica valores por igualdade frouxa, não a identidade exata dos campos. Referências de negócio válidas como `01` e `1` são **distintas** (identidade de contrato, aprovação ou cliente), embora `new Value("01") == new Value("1")` possa ser verdadeira.

O teste R22, introduzido antes da correção na branch filha da Portal #1096, reproduziu em PHP 8.4 a falha: uma troca de `contractRef` `01` → `1` deixava ocorrer uma **segunda leitura privada**. A recusa posterior não apaga esse acesso. Uma segunda regressão protege `approvalRef` `01` → `1` entre leituras, que poderia ser tratada como a mesma aprovação.

## Correção mínima

A classe `OnepageApprovedBriefExportGate` agora usa `sameContract()` e `sameBrief()` com comparação `===` dos campos tipados. As duas leituras independentes podem retornar objetos distintos, mas cada referência, revisão, conteúdo original, seletor e recibo devem ser **exatamente iguais**. A comparação de contratos acontece **antes** da segunda leitura privada. A comparação de aprovação precede o retorno de um candidato. O gate revalida contrato após a leitura, como R21; nenhuma nova consulta, permissão, assinatura ou fonte foi adicionada.

Os testes da mesma suite fictícia acrescentam recusas e contagens de consultas para:
- contrato que muda `01` → `1`, sem a segunda leitura privada;
- aprovação que muda `01` → `1`, sem aceitação do recibo obsoleto.

CI em PHP 8.3 e 8.4 executa a suite afetada no SHA exato do PR draft filho, sem segredo. Os links e a SHA da execução ficam na conversa da PR após conclusão efetiva; não considerar checks pendentes como PASS.

## Limites que continuam obrigatórios

Esta é apenas uma candidata de fronteira. `PortalSiteContractSource`, `ApprovedOnepageBriefSnapshotSource` e `CurrentOnepageBriefApprovalWitness` **continuam sem writers/readers oficiais provisionados**. O default é negar. O mock de testemunha não comprova declaração de direitos, identidade corporativa, sessão, aprovação operacional ou revogação durável. O snapshot não comprova atomicidade; falta CAS/epoch sob transação na origem oficial. Reutilizar Sites #552 / Portal #1003, sem reinterpretar contrato.

Nenhum avanço na Landing Page, reuso de credencial do Faísca, chamada OpenAI, checkout/Asaas, publicação/rollback, cloud, VPS ou produção. Sem merge, deploy ou alteração de main. **Sites #38 permanece NO-GO.**
