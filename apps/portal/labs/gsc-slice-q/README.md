# GSC Slice Q — Auditoria de segurança sem payload sensível

Esta etapa adiciona uma trilha **mínima de eventos técnicos**, como preparação do PKCE, consumo de `state`, negativa do callback, rejeição de tokens e revogação de um grant.

O armazenamento contém **somente seis campos**: ID aleatório, tipo de evento enumerado, referência interna da empresa, referência interna do operador, fingerprint SHA-256 opcional da propriedade e timestamp inteiro. Não existe coluna para códigos OAuth, tokens, consultas, URL, IP, User-Agent ou corpo de requisição.

## Serviços implementados

`GoogleSearchConsoleSecurityEventAudit::record` exige um tipo em uma lista fixa e IDs pseudônimos com formato delimitado. O ID do evento é gerado com 16 bytes aleatórios e persistido por SQL parametrizado. A operação não recebe dados livres e retorna somente um booleano.

`purge(now, retentionDays, limit)` elimina eventos antigos em lotes de 1 a 500, com `FOR UPDATE SKIP LOCKED` e janela de retenção configurável de 7 a 365 dias. **A política específica de retenção ainda depende de revisão LGPD**; a existência do método não agenda limpeza automática.

A migration `2026_11_12_000003_create_portal_gsc_security_events.php`, não aplicada em produção, impõe código enumerado, identificadores sintáticos e fingerprint opcional no PostgreSQL.

## Testes

O laboratório descarta sua própria tabela no banco efêmero `imagine_gsc_ci_test`, confere o catálogo real de colunas, seis eventos permitidos, recusa tokens/URLs em identificadores, rejeita tipos não declarados, assegura expurgo limitado e preservação dos eventos recentes. O CI também executa a migration e reversão na instância PostgreSQL do GitHub Actions.

## Restrições

Os eventos só podem ser gerados por um futuro backend autenticado, que obtenha empresa/operador de uma sessão confiável e utilize um usuário de banco com permissões delimitadas. **Este código não autentica atores**, não faz OAuth, não dá acesso Google e não autoriza exibição de dados ou publicação.

**DRAFT/NO-GO:** sem merge em dev/main, deploy, banco de cliente, credenciais reais ou custo. Necessita AppSec/DBA/LGPD, política de retenção e homologação em staging.
