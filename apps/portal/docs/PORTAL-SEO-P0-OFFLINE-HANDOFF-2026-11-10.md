# PORTAL-SEO-P0 — Contrato de leitura de demonstração (10/11/2026)

Este arquivo registra o primeiro consumidor **estritamente local** para o contrato `seo-portal-review-handoff-v1` do Imagine SEO Engine (PR #31, F24).

Arquivos:
- `app/Domain/SeoReview/OfflineSeoHandoffPreview.php`
- `tests/Unit/OfflineSeoHandoffPreviewTest.php`

## Portão de segurança

O leitor PHP **somente funciona em CLI** com `APP_ENV=testing` e `PORTAL_SEO_HANDOFF_LAB=1`. Aceita apenas o tenant e os fingerprints inteiramente **sintéticos** definidos no teste; recusa campos desconhecidos, códigos de ação arbitrários, métricas incoerentes e quaisquer afirmações de autorização.

Não está registrado em `routes/web.php`, controllers, filas ou banco. Todas as funções de autorização, despacho e publicação retornam `false`. Não utiliza OAuth, Google, credenciais, clientes ou métricas reais.

## Caminho para produção (pendente)

São indispensáveis login e MFA reais, concessão por tenant, autorização verificável da propriedade GSC, provas LGPD/consentimento, política de retenção e auditabilidade, testes na VPS e revisão independente. Nada nesta PR autoriza exposição de relatórios ao cliente ou ativação de um módulo SaaS.

## Complemento do contrato GSC F40–F42 (somente laboratório)

O consumidor independente `app/Domain/SeoReview/OfflineGscBoundPlanPreview.php` aceita apenas DTO `seo-gsc-offline-bound-plan-v1` **com tenant e fingerprint totalmente sintéticos**, sob PHP CLI, `APP_ENV=testing` e `PORTAL_SEO_GSC_LAB=1`. Permissões OAuth/tenant/produção e flags de rede precisam ser `false`; mesmo diante de propriedade declarada e plano consistente, toda capacidade operacional retorna `false`.

Arquivo de teste `tests/Unit/OfflineGscBoundPlanPreviewTest.php`. Não cria controller, rota HTTP, migration, banco, token ou OAuth, e não substitui a revisão independente da propriedade Google real e autorização por tenant. Baseada no esquema da [SEO RC16 #61](https://github.com/rafhael100/imagine-seo-engine/pull/61).
