# PORTAL-F102 — Proprietário SQL restrito e revogação entre conexões (10/11/2026)

## Contexto e alteração implementada
F100/F101 demonstraram, com PostgreSQL 18 descartável e dados exclusivamente fictícios, que uma sessão com permissão expressa só pode ler o resultado booleano de autorização para um recurso ativo da empresa correta. O primeiro desenho das funções `SECURITY DEFINER` tinha como proprietário o papel `postgres` (superuser do runner). F102 remove essa necessidade **na execução das funções depois da instalação**.

O script `labs/identity-f102/restricted_definer_owner.sql`, aplicado apenas no CI efêmero, cria um papel `portal_f102_security_owner` com:
- `NOLOGIN, NOSUPERUSER, NOCREATEDB, NOCREATEROLE, NOINHERIT`;
- `BYPASSRLS` **explicitamente necessário nesse ensaio** porque todas as tabelas ainda utilizam `FORCE ROW LEVEL SECURITY` e nenhuma policy de leitura foi autorizada;
- `SELECT` apenas sobre seis tabelas de identidade e recursos fictícios;
- permissão `CREATE` temporária no schema `public`, revogada **na mesma transação antes de COMMIT** após a transferência da titularidade das funções F76/F101.

As ACLs das funções continuam bloqueando `PUBLIC`: `portal_f76_reader` pode executar somente o predicado F76; `portal_f101_reader` somente o F101, não o F76. Nenhum papel pode consultar diretamente dados de sessão, identidade ou recurso pelo caminho de leitura comum. O `search_path` dos predicados fica travado em `pg_catalog, pg_temp`.

**Alerta técnico:** um papel não superuser com `BYPASSRLS` continua tendo uma capacidade sensível e não deve ser adotado em produção sem desenho alternativo/isolamento e aprovação independente de DBA/AppSec. Este PR não configura um novo serviço confiável de autenticação.

## Matriz de testes
O workflow F102 prepara PHP 8.3 e 8.4 com PostgreSQL 18 em runner descartável e executa, na ordem: migrations fechadas F66/F76; predicados F76/F101; transferência de titularidade SQL F102; regressões F100 e F101; e novo teste `test_pg_restricted_owner_and_revocation.php`. Confere, por leitura de metadados PostgreSQL, ausência de login/superuser/CREATE, permissões de tabela e função estritamente limitadas, RLS FORCE e zero policies permissivas.

A prova de concorrência usa **duas conexões**: uma conexão administradora abre transação e altera um recurso, sua propriedade, a revisão do membership e a situação de uma sessão. Outra conexão, no papel execute-only, confirma que a alteração ainda não comprometida permanece invisível e que a **primeira consulta iniciada após o COMMIT** nega acesso. Testa também a recusa quando o proprietário da função perde a única permissão `SELECT` necessária e restauração somente após um grant explícito.

A prova mostra comportamento `READ COMMITTED` entre instruções e **não** garante linearização de uma operação que já tenha começado antes do commit. Uma implementação real exige barreira transacional comprovada contra TOCTOU, sessões revogadas, cache obsoleto, fila de eventos, replays e regressão de privilégios.

## Escopo e NO-GO
- Nenhuma função ou papel F102 pertence às migrations ou imagens Docker de aplicação. Tudo reside em `apps/portal/labs/identity-f102/`.
- A autoridade Laravel F98 permanece `DenyingPortalAuthorities`; rota HTTP de negócio continua indisponível, sendo `/up` apenas saúde.
- Migrations, test fixtures, função, role e credenciais são descartados ao encerrar o runner. Sem banco real, CRM/SSO, MFA de clientes, anúncios, cobrança, deploy, domínio ou alterações de VPS.
- Revisão independente de segurança e LGPD, DBA, origem de ator, sessão HTTP segura, revogação linearizável, RLS multiempresa real, cofre de segredos e testemunha SEC-R03 continuam pendentes.
- Este é um **PR draft, nunca uma autorização de produção**. Não mesclar em `main` nem usar para habilitar usuários reais. Sem novos custos de fornecedores.
