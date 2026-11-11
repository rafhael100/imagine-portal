# GSC-F144 — Planejamento de consultas paginadas para a API Search Analytics

O `GoogleSearchConsoleQueryPagePaginationPlan` compõe o planejador estrito F122 para gerar exclusivamente o **formato** da chamada Google Search Analytics: POST para `www.googleapis.com`, propriedade codificada como um único segmento, dimensões `['query','page']`, `aggregationType='byPage'`, `type='web'` e `dataState='final'`. Parâmetros `rowLimit` (1–1.000) e `startRow` são validados como no máximo quatro páginas consecutivas, compatíveis com o agregador SEO-F59.

Referência oficial: https://developers.google.com/webmaster-tools/v1/searchanalytics/query. O Google suporta paginação por `startRow` e agregação por página, mas também informa que os resultados podem omitir linhas; nunca presumir 100% do tráfego.

**Este componente não faz chamadas HTTP** nem autentica Google ou empresa. A geração sintática de um URL Google não equivale à aprovação de OAuth. O backend produtivo precisará verificar sessão first-party, MFA, tenant/grants em transação, propriedade exata, scope readonly, token vault seguro, rate limits, logs e revisão independente.

A implementação está apenas em PR de desenvolvimento; sem chave OAuth, dados reais, token, migração de DB, registro de rota, DNS, deploy ou merge `main`. Sem despesas novas.
