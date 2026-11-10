# PORTAL-F100 — Prova PHP + PostgreSQL 18 com papel EXECUTE-only

Data: 10/11/2026. Ambiente: laboratório descartável, dados e credenciais sintéticos.

## Entrega

A F100 aproxima o projeto da futura autorização operacional sem abrir acesso HTTP. Adiciona uma classe PHP de laboratório em `apps/portal/labs/identity-f100/PortalF100OfflinePermissionProbe.php` (fora do autoload de `app/` e fora da imagem Docker F97).

Essa classe recebe uma cadeia de bearer exclusivamente sintética, valida um formato canônico de 256 bits em Base64URL, verifica o escopo de empresa UUID e a permissão enumerada; calcula SHA-256 no lado do servidor e chama, com prepared statement PDO, a função PostgreSQL F76 `portal_f76_permission_check`. O papel de execução deve ser precisamente `portal_f76_reader`, em `portal_identity_lab`, sem privilégio SELECT nas tabelas de identidade. A consulta devolve **apenas booleano**, sem nome, identidade, hash, MFA ou detalhes de SQL. Erro de banco sempre resulta em negação.

A função F76 existente confirma que a sessão de laboratório é ativa, está dentro do prazo, tem MFA registrado, identidade ativa e email verificado, empresa ativa, membership ativa, revisões de segurança coincidentes e concessão explícita de permissão. Nenhuma policy RLS permissiva é introduzida.

## Testes e critérios

O teste `test_pg_php_permission_probe.php` insere uma identidade, duas empresas, membership, grant e sessão fictícios em PostgreSQL 18 descartável e verifica, por chamada PHP→PostgreSQL com papel não superuser:
- concessão exclusivamente sintética quando todos os fatores e revisões estão válidos;
- negação por empresa estrangeira, permissão não concedida, token desconhecido, token CRM, formato inválido e argumentos com aparência de SQL injection;
- negação imediata quando membership, identidade ou empresa mudam de revisão, sessão é revogada/expira, falta revisão de empresa, membership é suspensa ou o grant é removido;
- incapacidade de ler diretamente tabelas com papel EXECUTE-only, e recusa de uma conexão superuser usada acidentalmente;
- falha fechada quando `APP_ENV` não é testing, flag do laboratório está ausente ou o banco não é local descartável.

CI F100 executa matriz PHP 8.3/8.4 e PostgreSQL 18 descartável, migrations restritas de identidade F66/F76, função SQL F76, teste de regressão e rollback. PR draft executa também a CI geral e o smoke Docker fechado.

## Limitações e próximos gates

**Isto NÃO é autenticação ou autorização operacional**: o teste só comprova a integração de PHP com a prova SQL de um banco descartável e com dados sintéticos. A própria função F76 é SECURITY DEFINER instalada por superuser **somente no runner**; precisa de revisão independente de DBA/AppSec, owner dedicado, testes de concorrência, monitoramento e desenho de privileges antes de qualquer uso real. O digest do bearer é segredo equivalente ao bearer e jamais pode circular em browser, logs, query params ou APIs.

A implementação não resolve sessão HTTPS real, emissor seguro, MFA/revogação transacional, ledger externo SEC-R03, consulta de proprietário de recurso, isolamento real multiempresa, RLS operacional ou autorização de release. A F98 mantém os providers Laravel com **negação incondicional**; F100 não modifica seu registro. Não existe rota de cliente, CRM/SSO, Ads, billing, deploy na VPS ou alteração de `main`.

Sem novos custos, dados pessoais ou segredos reais.
