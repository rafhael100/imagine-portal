# F1-411 — Integridade da revisão financeira multi-gateway

**DRAFT / NO-MERGE / NO-DEPLOY / NO-CHARGE.**

Este patch aplica-se à linha integrada F410 (PAY-RC01, isolamento HMAC/RLS,
receptor Asaas e exportador de revisão para o CRM) sem mudar contratos já
emitidos, dados do banco ou chaves.

## Dois riscos encontrados

1. `GatewayPolicyReview::review` exigia aumento sequencial de `revision`
   **antes** de comparar o hash de toda a política. Como a versão sempre
   mudava, a comparação de identidade do payload era inatingível. Agora o
   conteúdo efetivo é comparado excluindo somente `revision`, mas os
   hashes anteriores/posteriores do registro continuam incluindo a versão.
   Uma revisão numérica sem alteração comercial é recusada.
2. `PortalGatewayReviewExporter::export` aceitava envelope de revisão
   financeiramente incoerente (tarifa igual/superior ao bruto, líquido
   adulterado, modo desconhecido e prazo inválido). Agora recusa qualquer
   cotação sem `0 <= fee < gross`, `net=gross-fee`, modo manual/lowest_cost e
   prazo inteiro entre 0 e 90 dias.

## Paridade e limitação

A assinatura HMAC com a fixture fictícia já combinada com o CRM permanece
idêntica; `can_charge=false` e `can_activate=false` continuam imutáveis.
Os testes são locais e não dependem de rede nem credenciais reais. A verificação
de hashes **não confere credenciais, contratos, aprovações humanas ou
propriedade do tenant**. Exige IAM, segregação SQL, análise AppSec/DBA, prova
de integração CRM ponta a ponta e política real de rotação de chaves.

Nenhuma informação deste documento autoriza a cobrança ou a ativação de C6.
