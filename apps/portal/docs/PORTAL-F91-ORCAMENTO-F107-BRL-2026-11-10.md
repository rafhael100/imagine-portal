# PORTAL-F91 — orçamento diário BRL exato do Performance F107, sem veiculação

Esta entrega integra ao Portal o contrato `perf.portal.credential-bound-budgets.synthetic.v1`, originalmente desenvolvido no [PR #227](https://github.com/rafhael100/imagine-portal/pull/227), à linha de revisão F90.

## Código efetivamente integrado

`CredentialBoundBudgetPreview` valida em memória, sem conexão externa, um orçamento diário que só pode existir como **simulação**. O payload exige BRL em centavos inteiros (sem `float`), canais Google Ads/Meta Ads com divisão em 10.000 basis points, reconciliação exata da soma por canal e por dia, calendário UTC consistente e ano bissexto, sem valores perdidos por arredondamento. Rejeita dias inválidos, mais de 366 datas, total divergente, colunas desconhecidas, referências de contas não-DEMO e quaisquer flags de permissão de mídia ou cobrança.

O modelo **não emite proposta financeira, orçamento aprovado ou consentimento de anunciante**, e declara `canExecuteMedia=false`, `canBill=false`, `hasHumanApproval=false` e `isAuthenticatedPortalSession=false`.

## Controles adicionais

- Matriz de regressão PHP8.3/8.4 × Node22/24 e teste de contrato Performance F100, sem `if: github.event.repository.private == false`; as verificações devem continuar executando quando o GitHub estiver privado.
- Testes de payloads alterados, datas impossíveis, valores fracionários, inversões de canal, mês de fevereiro em ano bissexto e permissões falsas.
- O Dockerfile exclui `CredentialBoundBudgetPreview.php` **antes do Composer classmap**; o smoke garante que a classe não existe ou pode ser autoloadada na imagem privada de homologação.
- Nenhuma API, página cliente, ação publicitária, acesso ao banco operacional ou manipulação de dinheiro foi implementada.

Ainda é necessário obter autoridade do serviço contratado, autorização do anunciante por canal, controle de execução e fonte de métricas reais, integração com PSP e auditoria para qualquer orçamento efetivo. A gestão de credenciais, login e revogação do Portal continuam NO-GO.

**PR DRAFT / NO MERGE/DEPLOY. Sem Google/Meta, VPS, cobrança, campanhas ou custos adicionais.**