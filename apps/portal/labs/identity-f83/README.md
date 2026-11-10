# PORTAL-F83 — autoridade SQL com proprietário não-superusuário

## Alteração arquitetural testada

Na prova F76, a função `SECURITY DEFINER` estava criada com proprietário `postgres`, um superusuário do banco descartável. Isso era um risco importante caso o mecanismo fosse copiado para a aplicação. A F83 testa uma alternativa de privilégio mínimo:

- `portal_f83_authority_owner` é papel **NOLOGIN, NOINHERIT, NOSUPERUSER, NOBYPASSRLS**, sem privilégios de administração. Não é usuário de conexão.
- Esse papel recebe **somente SELECT** nas cinco tabelas de identidade, sessão, organização, vínculo e grants do Portal. São criadas cinco policies `FOR SELECT TO portal_f83_authority_owner` explícitas e isoladas; não há `USING(true)` concedido ao `PUBLIC` nem políticas que autorizem a role de conexão.
- `portal_f83_reader` é conexão não-superuser **sem SELECT**, com apenas `EXECUTE` da função de autorização `portal_f83_permission_check`. O privilégio `PUBLIC EXECUTE` é removido na mesma transação de criação.
- A função continua com `SECURITY DEFINER`, tabelas `public.*` explicitamente qualificadas, `search_path = pg_catalog, pg_temp`, e confere sessão, hash, email/MFA, organização, membership, revisões e grant como na F76.

No CI PostgreSQL18 PHP8.3/8.4, roda primeiro a suíte completa F82 e **somente depois** instala as políticas de F83, para não interferir com as provas anteriores de RLS sem policies. O teste F83 valida a owner real no catálogo `pg_proc`, ausência de LOGIN/SUPERUSER/BYPASSRLS, cinco policies somente para essa role, sem SELECT para a role de leitura, e negação de senha, tenant, scope, revogação, permissões e alterações de segurança. Ao final remove a função, policies, grants, roles e migrações do PostgreSQL descartável.

## Limite crítico

O novo proprietário não pode se autenticar, mas seu acesso `SELECT` de escopo amplo dentro da função é intencional para verificar o vínculo por tenant. Uma função `SECURITY DEFINER` vulnerável ainda representaria risco de exposição de dados. Por isso esta é uma **prova de arquitetura** e não uma concessão para dados de clientes ou deploy. Revisão DBA/AppSec independente, estudo de risco de owner, KMS, S2S, auditoria, SEC-R03 externo antirollback e IAM operacional continuam obrigatórios.

Nenhuma rota, provider, integração externa, VPS ou custo foi alterado. PR DRAFT, sem merge ou produção.
