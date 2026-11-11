# PORTAL-INT-F154 — Vincular alterações de grants à revisão da associação no PostgreSQL

A F153 adicionou ao domínio uma condição necessária: a revisão do vínculo empresa/usuário capturada pela sessão precisa coincidir com a revisão atual de `portal_memberships`. Entretanto, o banco ainda não garantia que **inserir/excluir grants ou alterar papel/status** incrementasse essa revisão. Sem esse mecanismo, uma futura implementação operacional poderia deixar sessões antigas reaproveitáveis após conceder ou revogar um direito.

## Migração candidata para revisão independente

`2026_11_11_000012_fence_portal_membership_revision.php` acrescenta três funções e três triggers PostgreSQL, somente quando a fundação Portal já existe:

- `BEFORE UPDATE` em `portal_memberships`: impede troca de identidade/organização/id de um vínculo, obriga a revisão a ser monotônica e a incrementa após mudança de papel ou status.
- `AFTER INSERT OR DELETE` em `portal_membership_grants`: atualiza a revisão da associação correspondente **dentro da mesma transação**, sob lock da linha. Se a associação não puder ser atualizada, a alteração do grant falha sem deixar um estado parcialmente válido.
- `BEFORE UPDATE` em `portal_membership_grants`: proíbe modificar grants in-place; a revisão de um direito requer revogação e nova emissão audível.
- Todas as funções são **SECURITY INVOKER**, sem bypass de RLS, sem nova role, sem `GRANT`, sem RLS ALLOW e sem endpoints de login/CRM/GSC. O app continua vinculado às autoridades `DenyingPortalAuthorities`.
- Downgrade exige verificação administrativa com `row_security=off` e **recusa rollback quando existirem sessões, memberships ou grants**. Assim o downgrade não elimina silenciosamente o fence em presença de registros.

## Prova PostgreSQL 18 isolada

A CI `portal-int-f154-membership-grant-pg18.yml` cria PostgreSQL18 temporário e executa as migrações reais F66, vocabulário SEO e F154 em PHP 8.3/8.4. O teste `labs/identity-f154/test_pg_member_revision.php` verifica:

1. FORCE RLS e zero policies de aplicação;
2. revisão inicial 1, incrementada após inserir grant (2);
3. update direto do grant negado;
4. suspensão do usuário eleva revisão a 3 e mudança de papel a 4;
5. tentativa de retroceder revisão negada;
6. sessão sintética emitida na revisão 2 torna-se comprovadamente obsoleta depois de alterações de vínculo;
7. exclusão do grant incrementa revisão a 5;
8. rollback F154 com qualquer membro/sessão é recusado; depois da limpeza explícita dos fixtures, rollback e reversão do schema devem funcionar.

**Limites:** ainda não existe trusted issuer, sessão real, logs independentes, autorizações de tabela para papéis de aplicação, nem garantia de operações sob roles reais. Funções SECURITY INVOKER não elevam privilégios: a futura role de gravação precisará dos privilégios mínimos para atualizar a revisão, senão as operações continuarão negadas, como devem. Um DBA superuser pode modificar qualquer esquema; a CI não é uma auditoria independente. Essa migração **não foi executada em produção ou VPS**.

**NO-GO** até revisão DBA/AppSec/LGPD, testes com roles não-superuser, concorrência, idempotência e revogação, backup/rollback em staging autorizado e aprovação específica para promover a migration. Sem custos, credenciais de clientes ou publicação.

## Prova adicional — papel sem BYPASSRLS

A bateria PostgreSQL também cria um papel `NOLOGIN NOBYPASSRLS` temporário, concede permissões SQL explícitas de `SELECT/INSERT/UPDATE/DELETE` sobre memberships/grants e usa `SET ROLE` para verificar que, **mesmo assim**, `FORCE ROW LEVEL SECURITY` sem políticas ALLOW impede leitura e inserção de grants. O papel e seus grants são removidos no próprio runner, e a mutação recusada não deixa linha residual. Isso testa uma propriedade importante da configuração fechada, mas não substitui avaliação independente de uma futura role de aplicação quando esta for autorizada.
