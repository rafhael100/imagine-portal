# GSC-F129 — Comparação de períodos com cobertura explícita

## Funcionalidade entregue

A F129 prepara a comparação de desempenho entre dois períodos do futuro painel do Imagine SEO, aproveitando os validadores Laravel F127 (métricas agregadas) e F128 (série diária).

- Os dois períodos devem ter **exatamente a mesma duração em dias**, ser cronologicamente ordenados e não se sobrepor.
- O cálculo só considera **linhas retornadas pelo Google**. Se faltar alguma data de qualquer período, o status é `not_comparable_unreported_dates` e **nenhuma variação** é calculada; datas ausentes nunca se convertem em cliques zero.
- Se uma resposta alcançar o `rowLimit`, o status é `not_comparable_row_limit`: a comparação numérica é suspensa por risco de truncamento.
- Quando todos os dias estão presentes e a consulta não atingiu o limite de linhas, devolve um comparativo **informativo das linhas recebidas**, com diferença de cliques e impressões, percentuais e variação do CTR em pontos percentuais e da posição média ponderada.
- Se o valor anterior de cliques ou impressões for zero, a variação percentual é `null`, **nunca infinita**, evitando expressões falsas como “crescimento infinito”.
- Não devolve o conteúdo de queries, URLs, datas ou chaves de dimensão; apenas os resumos numéricos mínimos.

## Limitação de interpretação

Até uma comparação que passa pela política conservadora ainda tem `coverage=returned_rows_only_not_verified_complete` e `googleOriginVerified=false`. Isso é necessário porque a [Search Analytics API](https://developers.google.com/webmaster-tools/v1/searchanalytics/query) pode não incluir todos os dados, inclusive em respostas aparentemente completas. O contrato **não deve produzir conclusões absolutas de crescimento ou queda de tráfego**.

## Segurança e liberação

As funções são puras e aceitam dados sintéticos sem verificar sua procedência. `authorizesClientReporting()=false` permanece fixo. Nenhum HTTP, OAuth Google, token, portal autenticado, propriedade real, SQL operacional, route, deploy, merge `main` ou nova despesa foi realizada.

Um serviço de backend confiável precisará aplicar MFA e grants por empresa/propriedade, buscar dados diretamente da API Google com tokens em cofre e registrar procedência/auditoria antes de exibir qualquer comparativo a clientes. Auditoria AppSec/DBA externa e homologação VPS são exigências independentes.
