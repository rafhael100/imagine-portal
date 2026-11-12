# Precisão temporal no laboratório de identidade

Proposta isolada baseada em `76250c0ae1a69edc55b1974b2b9b731c0c9d9a7e`. Sem merge, alteração de main, liberação HTTP, dados reais ou banco operacional.

## Problema e alteração

As migrations candidatas F66/F115 usam timestampTz sem precisão explícita. PostgreSQL timestamp(0) arredonda valores como 00:00:00.750000 para 00:00:01.000000. Comparações imediatas com clock_timestamp() podem, portanto, rejeitar uma confirmação ainda vista como futura. Na VPS, o F116 original falhou tanto na emissão do segredo de inscrição quanto na obtenção de evidência após MFA. Em três repetições sem instrumentação, houve dois sucessos e uma falha.

A proposta preserva seis casas decimais em dez colunas temporais de segurança. Não altera status, gates, grants, RLS, política MFA ou regras de expiração. Essas migrations são candidatas não homologadas; bancos já existentes exigem uma migration aditiva revisada. Alterar o arquivo histórico não atualiza uma base existente.

## Evidências do laboratório VPS

PHP 8.4, PostgreSQL 18 descartável sem portas públicas. Cópias efêmeras de aplicação; checkout e previews preservados.

- Roteiro F163: passou, incluindo bloqueio de reatribuição e rollback com sessões existentes, revogação terminal e leitura sem BYPASSRLS.
- Roteiro F82: passou; F66 27, F76 29, F77 29 e F82 35 verificações reportadas.
- F116 com precisão candidata: três execuções independentes passaram, cada uma incluindo F115, F113, F114 e F116.
- Exportação pg_dump e restauração pg_restore de cada banco em uma base separada: registro sintético recuperado.
- Adaptação do runner: compartilha o namespace de rede do PostgreSQL para respeitar PGHOST=127.0.0.1; adiciona bash, psql e jq. O git diff da CI é verificado no checkout do host, pois o contêiner não contém .git.
- Não executada a matriz completa PHPUnit/CI PHP8.3/8.4. Os scripts PostgreSQL existentes foram executados com vendor do lockfile da imagem F64, sem dependências dev.

A regressão nova `labs/identity-f116/test_pg_timestamp_precision.php` reproduz o arredondamento antigo e clona os tipos reais das dez colunas em tabelas temporárias. Após as migrations F66/F115 no banco descartável, executar com as mesmas variáveis de laboratório do F116.

## Limites

A proposta não oferece login, sessão, inscrição ou dados de cliente reais. Revisões AppSec/DBA/LGPD e fonte de identidade operacional continuam pendentes. Evidências completas e cópias de banco sintético permanecem em /srv/imagine/auth-lab na VPS; não contêm credenciais de produção.
