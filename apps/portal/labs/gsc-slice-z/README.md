# GSC Slice Z — contexto RLS por transação PostgreSQL

A migration do Lote V adiciona políticas RLS padrão-negado às quatro tabelas GSC. Este lote cria `GoogleSearchConsoleTenantScopedTransaction` para aplicar o contexto do tenant **somente dentro de uma transação**, evitando que uma conexão reutilizada mantenha contexto de outra empresa.

## Contrato

`run(trustedContext, callback)` recebe exatamente `tenantRef` e `actorRef` de um **futuro backend já autenticado**. Valida a sintaxe desses identificadores, bloqueia transações já abertas, exige PostgreSQL e inspeciona a role da conexão para negar papéis com `SUPERUSER` ou `BYPASSRLS`.

O método inicia uma transação e executa `SELECT set_config('app.tenant_ref', :tenant, true)` **com parâmetro SQL**, equivalente à configuração local da transação. Só depois chama o trabalho interno (closure). Ao confirmar ou reverter a transação, PostgreSQL elimina o contexto local. Uma falha resulta em `null` e rollback sem expor mensagens SQL ou dados do tenant.

**Limite de segurança:** sintaxe válida de `tenantRef` ou `actorRef` não prova que um usuário faça parte da empresa. Este wrapper não autoriza o usuário, não cria sessão MFA, não valida propriedade Google e não impede que SQL arbitrário altere `app.tenant_ref`. O Portal precisará resolver membership, RBAC/MFA e usar apenas conexões restritas e SQL parametrizado.

## Testes reais

O teste de RLS do Lote V foi ampliado para usar o wrapper num papel PostgreSQL **não proprietário e NOBYPASSRLS**, em banco descartável. Confirma leituras de apenas uma empresa por transação, ausência de contexto entre transações, rollback em exceção, recusa de transação aninhada, entrada com sintaxe SQL hostil, tentativa de UPDATE cross-tenant e rejeição de conexão administrativa.

## Operação

Este helper fica isolado no domínio PHP, sem controller/rota/serviço em produção. A integração real precisa ocorrer exclusivamente a partir do middleware/backend autenticado do Portal, com roles de banco dedicadas, privilégios mínimos, testes de pooling/rollback e revisão AppSec/DBA/LGPD.

**DRAFT/NO-GO:** nenhum merge em dev/main, deploy, banco real de cliente, OAuth operacional, credenciais ou gastos.
