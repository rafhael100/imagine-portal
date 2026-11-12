# Portal SEO — Lote C: planejamento e continuação GSC sem HTTP

Continuação restrita dos lotes [A #398](https://github.com/rafhael100/imagine-portal/pull/398) e [B #399](https://github.com/rafhael100/imagine-portal/pull/399). Esta candidata acrescenta somente módulos offline testados de plano de requisição Google Search Analytics `query,page`, validação de propriedade sintática e decisão de **intenção não autorizada** de buscar próxima página.

## Entrega técnica

- `GoogleSearchConsolePropertyIdentifier`: identificadores exatos de propriedade e impressão SHA256 (não é verificação de propriedade).
- `GoogleSearchConsoleSearchAnalyticsPlan` e `GoogleSearchConsoleQueryPagePaginationPlan`: validação de período, dimensões, tamanho de página, offset e endereço de API, **sem executar chamadas**.
- `OfflineGscQueryPageOverlapPreview` / `OfflineGscQueryPagePaginationPreview`: revalidação de DTOs agregados, ocultação, paginação de até quatro páginas e flags de privilégio negativas.
- `GoogleSearchConsoleNextPageIntent`: intenção **sintática e apenas de revisão**, se a página final tiver o tamanho esperado; não cria job, HTTP, token ou concessão real.
- Fluxo Node→PHP: `emit_node_pagination.mjs` usa a implementação do Imagine SEO RC32 **fixada por SHA** para gerar lote sintético com duas páginas; PHP executa testes adversariais de plano/resultados e próxima página.
- CI dos lotes A+B+C, com Node 22/24, PHP 8.3/8.4 e recusa de arquivos fora do escopo nas PRs de revisão.

## Estado de segurança

**NO-GO operacional:** sem chamada de rede, OAuth real, usuário autenticado, permissão de propriedade, sessão, tenant em PostgreSQL, dados de cliente, rota HTTP, migração, publicação, deploy, DNS ou merge dev/main. O `url` construído por um plano é somente uma **representação inerte de requisição**; nenhuma função do módulo pode autorizar o acesso.

Próximo gate obrigatório para uso real: revisão independente AppSec/DBA/LGPD, autorização específica de integração e testes em staging com identidade, consentimento, segregação multiempresa e credenciais de produção fora do repositório.
