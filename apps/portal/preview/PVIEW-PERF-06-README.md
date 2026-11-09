# PVIEW-PERF-06 — Laboratório de paridade de protocolo S2S fictício

Data: 08/11/2026 · Issue #89 · Branch filha do PR #88.

`apps/portal/preview/contracts/SyntheticS2SProtocol.php` e o teste adjacente são cópias idênticas **em bytes** dos arquivos de `contracts/lab/` do motor Performance F1-07, sem autoload operacional.

O teste usa a fixture estática `apps/portal/preview/fixtures/performance-dashboard-v1.json` e chave efêmera criada durante o job. Exercita 32 cenários, inclusive assinatura adulterada, multiempresa, serviço errado, nonce/request-id repetido, vencimento, revogação mock, schema incorreto e vínculo do digest. Não há HTTP, API, VPS, banco, token real, evento de cliente, operação de Google/Meta ou alteração do Portal.

**Não usar como prova de S2S autenticado:** chave ephemera e revisão de grant são entradas do teste, sem autoridade real; cache de replay é volátil. Essa etapa exige um gate separado e controles de identidade/revogação/segurança para eventual produção.

CI: PHP puro no runner público padrão, sem Composer/DB, com permissões `contents: read`. PR draft, sem merge automático. P0/HT-R01 permanecem abertos.
