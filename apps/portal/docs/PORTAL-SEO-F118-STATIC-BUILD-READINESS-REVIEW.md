# Portal SEO F118 — leitura segura da prontidão técnica de build (laboratório)

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.**
Continua a [Portal F117 #855](https://github.com/rafhael100/imagine-portal/pull/855), usando o contrato do [SEO Engine F114 #162](https://github.com/rafhael100/imagine-seo-engine/pull/162) e a checagem de idiomas [F117 #167](https://github.com/rafhael100/imagine-seo-engine/pull/167).

## Objetivo
Preparar uma fronteira de leitura no Portal para receber **somente o diagnóstico resumido** de auditoria estática do SEO Engine. A saída do Engine vem de arquivos locais fornecidos pelo operador, não da VPS e não de uma sessão autenticada de cliente.

O Portal não deve supor que `auditedRealLocalFiles=true` comprove integridade do build em produção. Esse valor é um campo do DTO recebido, não uma prova assinada.

## Contrato fechado
`PortalSeoStaticBuildReadinessLab::review($dto)`:

- Exige execução PHP CLI, `APP_ENV=testing`, `CI=true` e `PORTAL_SEO_F118_LAB=1`.
- Aceita somente o contrato e campos exatos `seo-municipal-static-build-reconciliation-offline-v1`.
- Revalida integridade aritmética: total de entradas, páginas encontradas/ausentes, idioma incorreto, índices limitados e separados, erros e vazamentos privados.
- Rejeita indicadores de autenticação Google, publicação, acesso ao cliente e origem verificada, inclusive campos adicionais falsificados.
- Produz somente contagens e categorias de erro. Não replica URLs, nomes de cidades, códigos IBGE, caminhos, tokens, mensagens ou índices originais.
- O status é **REVIEW_BLOCKED** ou **HUMAN_REVIEW_REQUIRED**; nem um resultado sem erros concede permissão de deploy.

A saída tem obrigatoriamente:
`realSiteVerified=false`, `officialIbgeVerified=false`,
`portalIdentityVerified=false`, `tenantAccessVerified=false`,
`customerDisplayAuthorized=false`, `productionReadAuthorized=false`,
`publicationAuthorized=false`.

## Limites
Nenhuma rota HTTP, sessão de cliente, integração PostgreSQL, API Google, ingestão persistente, endpoint de relatório, publicação ou deploy foi habilitado. O adaptador não constitui fronteira de identidade/tenant autenticada: qualquer aplicação produtiva exige emissor de sessão real, isolamento por empresa, origem do relatório confiável, trilha de auditoria e revalidação independente da VPS.

O teste `npm test` no Engine e o PHP Laravel são provas diferentes; comparar com o **commit fixado** do Engine é a etapa recomendada antes de homologar a integração. Nenhum número do funil Visitas → Leads → Clientes é inferido de clique GSC.

## Testes
`cd apps/portal && php artisan test --filter=PortalSeoStaticBuildReadinessLabTest`.

**F118 é laboratório e não permite que cliente acesse dados SEO de outra empresa.**
