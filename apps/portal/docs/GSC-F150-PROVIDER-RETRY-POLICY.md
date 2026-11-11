# GSC-F150 — Classificação segura das respostas HTTP do Google Search Console

O componente `GoogleSearchConsoleProviderResponsePolicy` prepara o backend para tratar falhas da futura integração, sem ativar conexão: diferencia HTTP 401 (reatenticação), 403 (revisão de permissão), 404 (propriedade não localizada), 429 (limite de consultas) e 500/502/503/504 (falha transitória). A classificação é produzida com códigos fixos, sem ler corpo da resposta nem registrar mensagens do provedor.

O planejamento de nova tentativa é limitado a três tentativas totais, com espera conservadora e suporte somente a um header `Retry-After` numérico de 0–120 segundos em 429/503. Outros cabeçalhos, cookies, tokens, mensagens livres, números inválidos ou tentativas fora da faixa são recusados. Mesmo HTTP 200 significa apenas `HTTP_OK_CONTENT_UNVERIFIED`: não valida OAuth, conteúdo, tenant ou propriedade.

A implementação é pura, sem HTTP, agendamentos, persistência ou acesso a tokens. A integração real exigirá controles externos de quotas por empresa, identidade/MFA, concessão exata, vault, observabilidade sem PII, revisão independente AppSec/DBA/LGPD e homologação E2E. **HOLD:** nenhum deploy, DNS, dados reais em CI, merge `main`, custos ou ativação Google.
