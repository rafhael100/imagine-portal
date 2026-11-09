# PVIEW-PERF-05 — Consumidor estático do contrato Performance (somente offline)

**Data:** 08/11/2026. Issue #86. Branch `feat/portal-pview-perf-contract-2026-11-08` filha do PR #85.

## O que o protótipo faz
- Cópia estática da fixture JSON `perf.portal.snapshot.demo.v1` proveniente do F1-06 no repositório `imagine-performance-ai`, branch `dev/perf-f1-06-dashboard-snapshot-demo-2026-11-08`.
- Leitura **local, somente leitura**, no Blade da prévia offline. Números e estados agora dependem da fixture validada; alteração de origem/campos/status ou organização divergente resulta em **falha fechada** (nenhuma campanha exibida, aviso de indisponibilidade).
- Verifica chaves estritas, versão, origem, tenant/organização de demonstração, estados permitidos, IDs, referências cruzadas, duplicatas, preços/capacidades indefinidos, limites de valores e métricas sem verba de mídia.
- Campos textuais de campanhas são renderizados pelo Blade com escaping automático; sem HTML vindo diretamente de JSON.
- **Nenhum login, grant, SSO, rota, cliente, pagamento, SQL, API, websocket ou consulta de rede** foi criado. Isso é uma prova de contrato offline e **não** autenticação serviço-a-serviço.

## Rastreabilidade
Fixture `apps/portal/preview/fixtures/performance-dashboard-v1.json` deve permanecer **idêntica em bytes** a `contracts/demo/performance-dashboard-v1.json` do Performance. Revisão futura de esquema exige versionamento e teste cross-repo, não atualização silenciosa.
Git blob SHA das duas cópias verificado no GitHub: `9248b6681022b710e6db934add56ddcca48b52d0` (snapshot da criação).

## QA
`apps/portal/tests/Feature/PerformanceDemoSnapshotContractTest.php` verifica renderização, ausência de rotas externas e **30 mutações adversariais** que devem resultar em `null`.
O workflow PVIEW-PERF-05 executa essas verificações com dependências travadas, exporta HTML para `RUNNER_TEMP` e roda Chromium offline em 3 viewports × 8 seções.
Não afirmar PASS sem logs do SHA atual.

## Gates
PR em rascunho, filho de #85 → #75, sem merge automático. Sem alterações da `main` ou implantação. As revisões dos 91 P0, 15 HT-R01, privacidade, autenticação mútua, controles de revogação, auditoria real, infraestrutura e Ads continuam obrigatórias. A cópia de fixture **não habilita assinatura, faturamento, campanhas reais nem leitura de dados de clientes**.
