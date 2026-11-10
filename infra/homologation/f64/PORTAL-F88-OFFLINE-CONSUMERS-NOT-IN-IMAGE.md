# PORTAL-F88 — retirar consumidores de prévia offline da imagem PHP

Esta etapa amplia a correção F85. A F85 excluiu `app/Labs`, mas o Dockerfile ainda copiava os consumidores PHP `App\\Domain\\PerformanceDashboard\\OfflinePerformanceWorkbenchPreview`, `CredentialBoundPerformanceWorkbenchPreview` e `App\\Domain\\SeoReview\\OfflineSeoHandoffPreview`. Esses componentes só aceitam `PHP_SAPI=cli`, `APP_ENV=testing` e flags de laboratório e nunca são necessários para responder `GET /up`.

## Implementação real

O Dockerfile F64 da imagem de homologação remove explicitamente os **três arquivos PHP de prévia** antes de `composer dump-autoload --classmap-authoritative`, conservando-os no Git/CI para as verificações e para exportar relatórios estáticos. A aplicação em contêiner não deve incluí-los sequer no autoload.

O smoke Docker faz duas verificações negativas separadas: o filesystem não contém as classes, `app/Labs`, `tests`, `labs`, `database`, `tools` ou `.env`; o Composer não pode resolver nenhuma das classes de laboratório nem os três consumidores de demonstração. Também verifica `/up=200` e que todas as rotas de cliente/API/login retornam 404.

## Limites

Esta imagem não disponibiliza site comercial nem autenticação. Os demais contratos de domínio ainda exigem revisão de composição/escopo; remover estes três arquivos é medida de redução de superfície, não certificação da segurança geral do Laravel ou da sua futura implantação. Não altera PostgreSQL ou permissões, dados de clientes, DNS, VPS, campanhas ou pagamentos. As demonstrações HTML continuam disponíveis apenas por CI/offline.

**PR DRAFT/NO MERGE/NO DEPLOY.** Os testes de Docker devem ser analisados na SHA exata.