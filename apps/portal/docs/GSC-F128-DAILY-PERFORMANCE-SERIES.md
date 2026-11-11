# GSC-F128 — Série diária de desempenho, sem fabricar dias inexistentes

Esta etapa acrescenta ao domínio Laravel o construtor `GoogleSearchConsoleDailyPerformanceSeries`, para futuramente alimentar o gráfico de evolução diária do Imagine SEO. A F127 já inspeciona os dados e seus valores; a F128 reutiliza essa validação, exige a dimensão `date`, confere o intervalo e limita o período a 92 dias.

## Semântica e segurança

- As linhas recebidas podem estar ordenadas por cliques em vez de data. A série as **ordena cronologicamente**.
- O Google pode **omitir datas sem dados** na resposta. A série mantém somente datas efetivamente retornadas. Nunca insere cliques/impressões zero nos dias omitidos; devolve `unreportedDates` e `missingDatesNotBackfilled=true`.
- Datas fora do período, datas inválidas, duplicatas, respostas com campos secretos, métricas inválidas e `rowLimit` excedido são recusados.
- Somente data, cliques, impressões, CTR e posição de cada linha são devolvidos. Nenhum termo de pesquisa, URL, propriedade, credencial ou token faz parte da série.
- `coverage=returned_dates_only_not_complete` identifica que os dados **não são um inventário completo**; `googleOriginVerified=false` e `authorizesDashboardPublication()=false` permanecem explícitos.

Essa distinção está fundamentada na [Search Analytics API](https://developers.google.com/webmaster-tools/v1/searchanalytics/query), que adverte sobre datas sem registros e limitações de cobertura.

## Limites de integração

Os testes usam apenas fixtures sintéticas. A série pode ser construída por qualquer código PHP; isso **não verifica que o JSON veio do Google** ou que o solicitante é um cliente autorizado. Um backend real precisará sessão e MFA first-party do Portal, grants e propriedade exata por empresa, chamadas autenticadas ao Google com scope readonly, cofre de credenciais auditado, rate limit e aprovação de segurança. **Nenhuma rota, token, consulta real, migration aplicada, merge main, VPS ou deploy foi realizado.**
