# PVIEW-PERF-06 — Prévia do Portal com contrato de campanhas do PostgreSQL sintético

**Data-base:** 09/11/2026. Issue #117, branch filha do PR Portal #88 PVIEW-PERF-05. Integração visual offline, sem rotas ou backend novo.

## Entrega
O componente `pview-performance-storage.blade.php` aparece **dentro da tela Performance** da navegação offline existente do Portal (oito telas continuam intactas). Ele exibe contagens, canais, campanhas pausadas/rascunhos e solicitações fictícias do motor Performance geradas na suite PostgreSQL F1-22 a F1-24 e congeladas em JSON pelo F1-25. Campanhas pausadas permanecem sob gestão; aprovações internas nunca significam anúncio publicado.

`PerformanceStoragePreview::loadStatic()` lê **somente** `apps/portal/preview/fixtures/performance-storage-preview-v1.json` (arquivo local). `parse()` exige `perf.portal.storage.synthetic.v1`, producer/source fixos, empresa/tenant/serviço exatos, nenhuma contratação ou dispatch habilitado, preço/capacidade não homologados, clicks/media_spend null, listas limitadas a 20, refs e títulos demonstrativos allowlisted, status/manifestos restritos, contagens consistentes, referências cruzadas válidas e **nenhum campo extra**. Caso inválido, não exibe dados parciais. Não inclui scripts, formulários, botões de aprovação, endpoints, sessão, fetch, cookies ou API de Ads.

## Prova de procedência e qualidade
O workflow PVIEW-PERF-06 faz checkout do repositório público `rafhael100/imagine-performance-ai` **fixado no commit F1-25 `68ad8e26eb3ee7d93f1a1cb484d1b3b0b031d4dc`**, cujo CI já provou a paridade byte a byte do JSON gerado do PostgreSQL. Executa `cmp` de fixture motor ↔ cópia Portal, preservando o mesmo conteúdo sem rede de aplicação. Roda PHPUnit para o contrato antigo F1-06, novo F1-25 e testes prévios da navegação Portal, além de renderizar Blade e verificar a navegação com Chromium.

`PerformanceStoragePreviewContractTest` exerce o contrato e dezenas de alterações adversariais: organização/tenant/serviço estranho, schema, fonte, PII/token, budget, gasto de mídia inventado, estado operacional, duplicatas, targets desconhecidos, contagens adulteradas, aprovação externa, campos faltando e títulos HTML. Verifica rotas 404 e ausência de botões/formulários no protótipo.

## Bloqueios
O arquivo é uma **amostra estática de dados fictícios**; não há sincronização em tempo real, identidade, S2S operacional, contrato comercial, fonte Google/Meta, aprovação de usuário real, webhook, orçamento, pagamento ou execução de campanhas. A área de cliente em produção continua não implementada/sem autorização; gates P0, HT-R01, SEC-R03/T3 permanecem abertos.

PR draft sem merge ou deploy; main/fast-track/VPS intactos, sem custos novos ou dados reais.
