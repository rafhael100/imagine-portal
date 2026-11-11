# GSC-F122 — Plano offline de consulta de desempenho do Search Console

## Contrato

A API Google Search Analytics utiliza `POST https://www.googleapis.com/webmasters/v3/sites/{siteUrl}/searchAnalytics/query` mesmo para **consultar dados somente leitura**, conforme [documentação oficial](https://developers.google.com/webmaster-tools/v1/searchanalytics/query). A F122 prepara um **objeto de requisição em memória** sem fazer o POST ou fornecer qualquer credencial.

O endereço do serviço fica fixo em `www.googleapis.com`. O parâmetro `siteUrl`, validado pelo componente F120, é codificado como **um único segmento de caminho**, preservando a diferença entre `sc-domain:...` e propriedades `http(s)://...`. Nenhum host externo, parâmetro de API key, bearer, segredo, filtro arbitrário ou token entra no plano.

A consulta possui `startDate` / `endDate` válidos no calendário, período máximo conservador de 92 dias, `rowLimit` de 1 a 1000, dimensão permitida (até três diferentes dentre `date`, `page`, `query`, `country`, `device`), `type=web`, `aggregationType=auto` e `dataState=final` para evitar dados recentes incompletos. As datas da API Google são interpretadas em horário do Pacífico; a classe só valida a data, não converte fusos.

## Limites

`buildRequestShape()` somente produz método, URL e corpo previstos. Não há cliente HTTP, request em execução, autenticação de usuário/MFA, consulta a banco, verificação Google de permissão de propriedade, token em cofre, resposta real nem dashboard habilitado. O método `authorizesOutboundRequest()` é sempre `false`. O futuro emissor terá que obter `siteUrl` de autoridade Google autenticada, conferir empresa e grant atual, aplicar rate limits, paginação, auditoria, segurança HTTP e privacidade antes de enviar requisições.

Sem custos, produção, DNS, merge main ou deploy. Auditoria independente AppSec/DBA e homologação real permanecem pendentes.
