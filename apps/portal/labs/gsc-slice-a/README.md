# Portal / SEO — Lote A independente (GSC-F155, F156, F159 e F160)

## Por que este lote existe

A cadeia anterior de revisão do Portal possui **545 caminhos alterados e mais de 700 commits à frente de dev**. Não é seguro promover toda a cadeia apenas porque os testes sintéticos passam. Este lote foi extraído **diretamente de `dev/fast-track-portal-2026-11-05`**, sem carregar a ancestralidade acumulada dos PRs de revisão. Ele implementa somente um consumidor PHP de dados GSC offline, testes Node/PHP e prévia HTML bloqueada para clientes.

## Conteúdo

- `OfflineGscQueryPageReviewPreview.php`: verificador estrito para o contrato `seo-portal-query-page-review-offline-v1` criado no Imagine SEO RC32.
- F155: testes adversariais de escopo, paginação, privilégio, ocultação e cobertura.
- F156: Node RC32 SHA `f46b65aaef9b10e32d02e415907cd916a43b548e` gera seis lotes sintéticos; PHP valida cada relatório.
- F159: prévia HTML somente CLI, com CSP que proíbe rede e execução, `noindex,nofollow` e contagens agregadas.
- F160: rejeição de symlink, JSON malformado, permissões falsificadas, execução fora do laboratório e argumentos extras.
- Workflow isolado `portal-gsc-slice-a-ci.yml` com checkout **imutável do SEO RC32**, conferência de `git rev-parse HEAD` e matriz Node 22/24 × PHP 8.3/8.4.

## Política NO-GO

**Este PR não aplica merge em dev/main**, não cria endpoints, autenticação, migrations, grants, conexões Google, armazenamento, automação de publicação, redirecionamentos ou deploy. Contratos contêm identificadores sintáticos fixos (`imagine-demo`, `sc-domain:example.com`), que **não representam usuário autenticado ou propriedade verificada**. O workflow confere que o diff permanece nesse escopo.

Para publicação futura: AppSec/DBA/LGPD independentes, autenticação real/MFA/RBAC, OAuth/consentimento com propriedade verificada, teste multitenant com banco de homologação, backup/rollback e autorização específica de release. A PR [#394](https://github.com/rafhael100/imagine-portal/pull/394) permanece como laboratório de integração mais amplo; este lote é **alternativa separável** para revisão menor. Não aprovar as duas linhas cumulativamente sem deduplicação dos mesmos caminhos.

**Status:** DRAFT — NO-GO operacional. O CI automatizado prova somente os cenários de teste.
