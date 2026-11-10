# PORTAL-F104 — Funções SQL sob FORCE RLS sem BYPASSRLS

**Data:** 10/11/2026. **Status:** prova PostgreSQL 18 descartável; não operacional.

## Escopo e melhoria de segurança

A F102 tinha transferido a propriedade das funções F76/F101 de `postgres` para `portal_f102_security_owner`, um papel `NOLOGIN` e `NOSUPERUSER`, mas o papel precisava de `BYPASSRLS` no laboratório que não tinha nenhuma policy RLS. A F103 reduziu os SELECT desse papel a uma lista explícita de colunas, sem acesso a hashes de senha, segredo de MFA ou dados descritivos.

A F104 testa um passo adicional: **remove BYPASSRLS** do proprietário SQL e instala seis `POLICY FOR SELECT`, uma por tabela de identidade/recurso, cujo único destinatário é `portal_f102_security_owner`. As seis tabelas continuam `ENABLE ROW LEVEL SECURITY` e `FORCE ROW LEVEL SECURITY`, sem policies para `PUBLIC` ou para os papéis de execução dos usuários. As funções F76/F101 têm `row_security=on` e `search_path` explícito.

As policies do experimento usam `USING (true)` **somente para o proprietário técnico NOLOGIN**. Isso **não é** uma política RLS por empresa: a checagem de empresa, concessão, sessão revogada e proprietário do recurso continua nos predicados SQL F76 e F101. Um papel privilegiado que execute funções de segurança mal escritas ainda pode representar risco. Jamais copiar essas policies diretamente para produção.

## Evidências e testes

- `labs/identity-f104/rls_without_bypass.sql` aplica a mudança na mesma transação, sem migrations normais e sem alteração de tabelas operacionais.
- `labs/identity-f104/test_pg_rls_without_bypass.php` verifica `NOBYPASSRLS`, `NOLOGIN`, `NOSUPERUSER`, seis policies exatas e nenhum vínculo de membro do papel técnico a papéis de aplicação, além do escopo estrito de `search_path` e `row_security=on`.
- O teste PHP confirma que os leitores F76/F101 continuam sem SELECT direto; sessão sintética com MFA e permissão explícita passa somente para o recurso da organização correta.
- Mutação de policy de recurso para `USING (false)` deve negar F101 mantendo F76; mutação da policy de sessões para `USING (false)` deve negar F76 **e** F101; restauração explícita reestabelece os testes sintéticos.
- Transferência de propriedade e revogação de sessão continuam recusadas.
- CI executa as regressões F100–F103, aplica F104 em PostgreSQL 18 descartável (PHP 8.3/8.4) e depois remove as seis policies, funções, tabelas, papel técnico e migrations. O PR executa também os testes Laravel e o smoke Docker privado.

## Segurança ainda não aprovada

Sem sessão/autenticação real, recuperação de credenciais, MFA operacional, RLS por empresa em banco persistente, políticas de propriedade transacional, auditoria independente, ledger externo SEC-R03, homologação na VPS ou conexão CRM/Ads. A função `SECURITY DEFINER` permanece um ponto sensível; o campo `session_sha256` é equivalente a segredo bearer e não pode ser exposto. A aplicação Laravel continua com `DenyingPortalAuthorities`, e a única rota HTTP permitida é `/up`.

**NO-GO de produção.** Nenhum merge em `main`, implantação, cliente, dinheiro ou gasto extra.
