# PORTAL-F101 — Prova de autorização por recurso no PostgreSQL 18

**Data:** 10/11/2026. **Natureza:** laboratório CI com identidade, sessão e recursos fictícios; NO-GO de produção.

## O que mudou

A F100 verificou em PHP um predicado F76 de sessão e permissão em uma empresa. A F101 introduz uma prova adicional: **a permissão concedida não basta para consultar recursos de outra organização**.

No PostgreSQL 18 descartável, `labs/identity-f101/resource_owner_gate.sql` cria uma tabela de recursos fictícios com identificador UUID, proprietário (organização) e estado active/archived. A tabela tem RLS ENABLE e FORCE com zero policies permissivas. Uma função `portal_f101_resource_check(hash, organization, resource, permission)` é instalada por superuser **somente no runner de teste**, verifica o predicado F76 e consulta o proprietário do recurso diretamente no servidor. O resultado é apenas booleano.

A função é SECURITY DEFINER com search_path fechado (pg_catalog, pg_temp), tabelas qualificadas com schema, e permissão EXECUTE revogada de PUBLIC na mesma transação de criação. Somente o papel isolado `portal_f101_reader` obtém EXECUTE. Esse papel não tem SELECT em tabelas, não pode invocar diretamente a função F76, não é superuser nem BYPASSRLS.

`PortalF101OfflineResourceProbe.php`, fora de `app/` e da imagem Docker, verifica papel/db de origem e formato canônico de token sintético 256 bits, organização UUID, recurso UUID e permissão fechada services.view/services.manage; usa prepared statement para invocar a função com SHA-256 calculado no servidor. Nunca retorna token, digest, identidade, objeto de recurso ou detalhes de erro.

## Prova adversarial

A suíte `test_pg_resource_owner.php`, combinada com a suíte F100 no workflow PHP 8.3/8.4 × PostgreSQL 18, cobre:

- concessão sintética apenas com sessão MFA válida, permissão explícita e recurso ativo da empresa correta;
- recusa de recursos de outras empresas, seleção de empresa estrangeira, recurso inexistente, billing/wildcard, token CRM, bearer desconhecido e argumentos SQL-shaped;
- recusa imediata de recurso arquivado, transferência de propriedade, revisionamento de membership/organização, sessão revogada/expirada e retirada de grant;
- incapacidade da role do aplicativo de ler linhas de recursos ou sessões ou invocar diretamente o predicado F76;
- rejeição de conexão superuser, ausência do gate `APP_ENV=testing`, e preservação de RLS sem policy de liberação.

O runner elimina função/tabela F101 e função F76, e executa rollback das migrations F66/F76. O PR draft também deve passar nos testes gerais de Laravel e smoke Docker; não considerar o resultado aprovado até a HEAD exata estar verde.

## Proibições

A tabela F101 **não é um esquema de recursos de cliente** e não deve virar migration de produção. A função usa SECURITY DEFINER administrada por superuser em CI: exige desenho real de papéis, proprietário restrito, auditoria DBA/AppSec, monitoramento, controle de concorrência e revisão de vazamento de informações antes de qualquer integração operacional.

A camada Laravel continua associada à autoridade `DenyingPortalAuthorities` (F98) e HTTP somente `/up`. Não há login real, sessão persistente, MFA de clientes, RLS permissivo, CRUD real, compartilhamento CRM, conexão Ads, faturamento, deploy ou alteração em `main`. O conjunto permanece **NO-GO** até revisão independente e homologação.
