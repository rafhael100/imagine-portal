# GSC-F127 — Consolidar métricas retornadas pelo Search Console sem extrapolar resultados

A F122 prepara a estrutura de requisição Search Analytics somente leitura. A F127 acrescenta ao domínio Laravel `GoogleSearchConsoleMetricsReview`, capaz de **avaliar um payload sintético ou previamente obtido por um backend confiável**, validar sua estrutura e calcular indicadores somente a partir das linhas efetivamente presentes.

## Contrato implementado

A resposta aceita apenas `rows[]` e o campo opcional `responseAggregationType`, com dimensões conhecidas, número de linhas limitado por `rowLimit` e métricas numéricas finitas. Cada linha contém cliques, impressões, CTR entre 0 e 1, posição válida e exatamente as chaves de dimensão esperadas. Linhas duplicadas, datas inválidas, dimensões extras, dados com `NaN`/`INF`, campos de token e respostas inconsistentes falham de modo fechado.

O resultado contém apenas número de linhas, somas de cliques/impressões, CTR combinado calculado sobre as linhas recebidas, posição média **ponderada por impressões** e indicação de possível truncamento quando a consulta atinge `rowLimit`. Os valores das chaves de pesquisa ou das URLs **não são devolvidos no resumo**, reduzindo exposição de consultas potencialmente sensíveis.

O campo fixo `coverage=returned_rows_only_not_complete` informa que as somas **não representam automaticamente os totais de uma propriedade**. O Google explicita que a Search Analytics API é limitada e pode retornar somente as linhas de maior desempenho; também pode omitir datas sem dados quando a dimensão é data. [Documentação oficial de Search Analytics](https://developers.google.com/webmaster-tools/v1/searchanalytics/query).

## Bloqueios de segurança

Este processador não prova que os dados vieram do Google. `googleOriginVerified=false` e `authorizesReporting()=false` são intencionais. Um array inventado pode satisfazer o formato, mas não deve poder ser publicado como relatório de cliente. Antes do uso real, o backend precisa autenticar a sessão Portal/MFA, validar tenant/propriedade/grant, realizar a consulta com o token guardado no cofre aprovado, controlar paginação, limitações, privacidade e auditoria.

Não há rotas HTTP, chamadas Google, tokens, dados reais de clientes, migration aplicada, merge main, deploy ou novo custo. Os testes usam somente fixtures sintéticas.
