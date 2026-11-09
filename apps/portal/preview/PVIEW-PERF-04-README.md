# PVIEW-PERF-04 — Performance AI demonstrativo dentro do Portal (offline)

**Data-base:** 08/11/2026
**Issue:** #83
**Base:** `feat/portal-pview-03-blade-visual-2026-11-08` (PR #75, draft)
**Status:** alteração de UI em PR filho **draft**, sem merge nem deploy.

## Objetivo
Aproveitar a navegação Blade já existente e substituir exclusivamente o placeholder da seção `Performance` por um componente visual de apresentação, sem duplicar o Portal ou criar uma aplicação paralela.

## Alterações
- `apps/portal/resources/views/components/portal/pview-performance-detail.blade.php`: cards ilustrativos, canais Google Ads e Meta (Instagram e/ou Facebook), campanhas sob gestão ativas e pausadas, capacidade não homologada, aprovação humana simulada e métricas sem origem real.
- `apps/portal/resources/views/portal/demo/pview-navigation.blade.php`: somente a seção `Performance` passa a renderizar esse componente.
- `apps/portal/tests/Feature/PviewPerformanceDetailTest.php`: assertivas de conteúdo DEMO, oito seções preservadas e nenhuma rota HTTP privada habilitada.
- Workflow dedicado para revalidar Laravel Blade + Chromium de forma isolada e sem usar VPS, banco, APIs externas, agentes ou credenciais.

## Regras de produto e segurança
- Campanhas ativas **e pausadas** contam capacidade, mas **nesta interface só há exemplos**; números e estados não são entidades reais.
- Limites quantitativos, preços, faturas, planos reais, verbas e resultados não foram homologados. A tela mostra capacidade indefinida e nenhum valor financeiro real.
- Aprovar não é possível nesta tela; uma aprovação operacional exigirá identidade, alçada, vínculo, versão, auditoria durável e confirmação humana em endpoint autenticado futuro.
- A tela usa `<details>/<summary>` nativos para abrir informações, sem código JavaScript extra. O único JS continua sendo o navegador local do PVIEW-03.
- Nenhuma chamada Portal–Performance, Google/Meta, CRM, banco ou PSP é feita. O motor Performance permanece independente.
- **Não** copiar CSP `unsafe-inline` da prévia para a aplicação real.

## Gates e aceite
- Executar testes Feature com `composer install` de lock já existente e exportar Blade em arquivo temporário do runner.
- Checar navegabilidade em desktop, tablet, celular, teclado e **zero HTTP externo** via script PVIEW-03.
- Confirmar CI no head SHA antes de declarar testado.
- PR draft com base PVIEW-03, sem merge; não alterar main, VPS ou produção.
- Identidade real, RLS/tenancy, P0, PRIV/LGPD, pagamento e homologação Portal–Performance permanecem abertos.

Links de contexto: Performance PR #16 (Laravel/PostgreSQL), #18 (eventos mock), #21 (campanhas), #23 (aprovações/relatórios).
