# PORTAL-F96 — Reconciliação dos estados demonstrativos de Performance

Data: 10/11/2026. **Status: revisão de código, não produção.**

## Resultado

A linha consolidada F95 importou os consumidores offline HTML/CSV e a proteção de perímetro F73, mas não carregou o teste PHP independente dos estados de observação F61. Esta etapa recupera, sem modificações, o arquivo:

- apps/portal/tests/Unit/PerformanceObservationStateContractStandalone.php
- blob original (da branch F73): 093324a85f5be37c741256b97b62cf8af7bd7b6e.

O domínio com os enums PerformanceCampaignObservationState e PerformanceReviewObservationState **já existia** na linha F95; não foi sobrescrito. A F96 verifica o vocabulário fechado (draft/paused/archived DEMO e pending/approved/rejected DEMO), o label “DEMO”, o não reconhecimento de estados operacionais e a recusa de autorização de execução, confirmação real de fornecedor ou aprovação real.

O workflow portal-f96-performance-observation-ci.yml roda PHP 8.3/8.4 × Node 22/24, validando também o consumidor de dados F59, a tela offline F60 e a ausência de bindings de laboratório nas rotas/bootstraps. O PR draft de F96 desencadeia novamente os testes gerais Laravel e o smoke Docker da revisão F92.

## Decisão sobre divergências F73 × F95

A reconciliação F95 selecionou apenas 15 arquivos não conflitantes de ferramentas e testes F65–F73. A F96 acrescenta o único teste PHP faltante da observação F61. Os demais itens ausentes no diff são, predominantemente, workflows legados restritos a branches antigas e documentos históricos. Não copiar pipelines obsoletos por padrão; preservar a matriz consolidada. Os arquivos já presentes com conteúdo diferente (notadamente consumidores F60, asserções standalone e workflows F62–F64) exigem comparação específica, não substituição.

**Não declarar que todo o histórico das branches foi integrado.** F96 não faz merge de F73 com F95 e não cria rotas reais. O único HTTP autorizado na revisão F92/F95 é /up, validado no smoke descartável. Não há login autenticado, credenciais de anunciante, conexões Google/Meta, dinheiro real, sessão de cliente ou deploy na VPS.

## Gates ainda abertos

- IAM próprio do Portal com proveniência servidora de ator, empresa, papéis, revalidação e revogação;
- PostgreSQL isolado com RLS e testes multiempresa;
- AppSec, LGPD/DBA, gestão de segredos e verificação independente;
- SEC-R03 (testemunho externo, antirollback), homologação operacional na VPS e gates de release;
- integração funcional com Sites, CRM (login independente), Comercial e Performance.

CI verde nesta etapa prova apenas as invariantes testadas em ambiente sintético.
