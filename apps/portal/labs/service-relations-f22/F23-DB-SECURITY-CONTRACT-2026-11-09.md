# PORTAL-F23 — Filtro negativo para deriva de segurança no PostgreSQL

**09/11/2026. Laboratório PHP + PostgreSQL sintético; sem autorização operacional.**

## Motivação

O F22 passou nos testes com duas conexões reais PDO de laboratório, senhas
SCRAM distintas e uma view `f21.service_summary` com RLS em PostgreSQL.
Apenas comparar `session_user` com o ID da empresa fictícia seria
insuficiente se um administrador, migração ou restauração modificasse as
políticas, desativasse RLS ou mudasse a view para `security_definer`.

## Implementação

`app/Labs/F22/SyntheticPgServiceReadAdapter.php` agora realiza uma verificação
de segurança **antes** de consultar a view, na mesma transação de leitura
`REPEATABLE READ / READ ONLY`. Os metadados são lidos de
`pg_catalog.pg_class`, `pg_namespace` e `pg_roles`:

1. Exige que as três tabelas fictícias `f21.service`, `f21.request`
   e `f21.contract` sejam tabelas regulares e tenham
   `relrowsecurity=true` **e** `relforcerowsecurity=true`.
2. Exige que a view `f21.service_summary` seja uma view regular
   (`relkind='v'`) com `security_invoker=true`.
3. Exige que a role da sessão SQL tenha `LOGIN`, **não** tenha
   `SUPERUSER` e **não** tenha `BYPASSRLS`.
4. Se algum controle não estiver presente ou o metadado não puder ser
   consultado, o adaptador não lê a view e devolve a decisão
   **`unsafe_db_schema` com todas as linhas e contagens zeradas**.
   Exceções de conexão/consulta devolvem `source_unavailable`,
   também sem exposição parcial.
5. A verificação é complementada por controle de origem do usuário SQL,
   Prepared Statements, timeout, transação read-only e limites F22.

## Testes adversariais

A suíte F22 adiciona quatro cenários negativos reais PostgreSQL, sempre
restaurando a configuração no bloco `finally`:

- `ALTER TABLE f21.service NO FORCE ROW LEVEL SECURITY`;
- `ALTER TABLE f21.contract DISABLE ROW LEVEL SECURITY`;
- `ALTER VIEW f21.service_summary SET (security_invoker=false)`;
- `ALTER ROLE f21_org_a BYPASSRLS`.

Cada modificação deve bloquear a leitura; após restauração da fixture,
a leitura de laboratório deve voltar a funcionar. A role A não possui
permissão para aplicar essas alterações; o ensaio as executa apenas com
a identidade administrativa do **banco descartável**, nunca em produção.

## Risco residual

**Metadados são um controle negativo, não atestação forte.** A checagem não
valida o conteúdo completo das políticas SQL, o SQL da view, os grants de
todas as tabelas, a autenticidade do emissor da conexão, tokens de sessão
Laravel, o estado da Z4 ou o isolamento de administradores. Um ator que
controla o banco/runner pode restaurar uma configuração antiga e enganar
essas consultas. Uma mudança concorrente depois da checagem pode exigir
barreiras adicionais. Também não há contrato para leitura paginada segura
sob mutações entre requisições; F22 mantém um cursor DEMO sem assinatura.

Revisão AppSec/DBA, dados persistentes reais, autenticação e relação
segura usuário/empresa continuam **BLOQUEADAS**. Nenhum código F23 é
ligado a rotas, serviços ativos, Login/CRM, pagamentos ou produção. O
código reside em `App\Labs` e só é habilitado via flag de fixture
controlável `F22_ENABLE_LAB`, que **não é mecanismo de segurança**.

**PR filho do F22, DRAFT/sem merge.** GitHub Actions usa exclusivamente
PostgreSQL descartável em porta aleatória restrita ao loopback. Nenhuma
VPS/Cloudflare ou fornecedor de cobrança é ativado. Minutos de CI
podem ser contabilizados conforme o plano GitHub.
