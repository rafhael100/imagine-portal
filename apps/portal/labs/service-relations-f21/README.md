# PORTAL-F21 — laboratório PostgreSQL 18 descartável para leitura multiempresa

**09/11/2026. Estado:** PoC de laboratório, **sem implementação operacional do Portal**.

## Propósito

Verificar em PostgreSQL real e efêmero que o desenho de relacionamento 0..N do
F20 pode ser modelado sem multiplicar contagens por joins, misturar registros
entre empresas ou fornecer permissões de escrita às identidades de leitura.

F20 é PHP/Laravel com fixtures in-memory, não se comunica com este banco.
Este F21 não cria Eloquent Models, migrations nem conexões SQL da aplicação.

## Estrutura sintética

- Tabelas sob o schema **f21**, isolado: `service`, `request`, `contract`.
- Cada linha e cada chave primária e estrangeira tem **organization_id**
  como parte da chave; impede referência cruzada entre empresas.
- Roles de laboratório `f21_org_a` e `f21_org_b`, com LOGIN restrito
  a sessões locais descartáveis e sem herança; somente SELECT,
  sem direitos de INSERT/UPDATE/DELETE/DDL. Os testes conectam
  como cada role real, em vez de usar uma sessão superusuária.
- Políticas RLS específicas por role e `session_user`, com ENABLE + FORCE
  RLS nas três tabelas. **Não** confiam em `current_setting('app.tenant_id')`
  fornecido por um chamador.
- View `service_summary` com `security_invoker=true`, preservando RLS
  das tabelas de origem. Solicitações e contratos são agregados **antes** da
  associação ao serviço, para impedir a contagem duplicada quando há N×M.
- Fixtures: organização A com quatro serviços (inclusive um com zero
  solicitações/contratos), três solicitações e três contratos; organização B
  com um serviço, uma solicitação e um contrato, inacessíveis para A.

## Provas executadas por validate.sh

1. Conferir FORCE RLS em todas as três tabelas e a opção
   `security_invoker=true` na visão.
2. Conectar como identidade sintética A e conferir quatro serviços,
   contagens 0..N e nenhuma linha da organização B, inclusive na view.
3. Identidade B deve ver somente o próprio serviço e suas relações.
4. Forçar a GUC `app.tenant_id='org-b'` dentro da sessão A: não deve
   alterar o resultado de RLS.
5. INSERT, DELETE, UPDATE, ALTER TABLE, `SET ROLE f21_org_b` e
   `SET SESSION AUTHORIZATION f21_org_b` partindo de A devem falhar.
6. Uma tentativa de criar solicitação A referenciando serviço B falha
   na chave estrangeira composta, mesmo quando executada pelo owner
   do laboratório.
7. Conferir após as provas negativas que a quantidade total de
   linhas não mudou.

## Execução CI

O workflow `.github/workflows/portal-f21-pg-service-read-isolation.yml`
inicia `postgres:18` via Docker na ação GitHub, com **`--network none`**,
sem porta mapeada, sem volume persistente intencional e senha aleatória
gerada no runner. `docker exec` roda `psql` dentro do container e
`docker rm -fv` remove estado/volume no cleanup. Não usa VPS, clientes,
segredos de organizações reais, rede externa, Cloudflare ou serviços pagos.

Para executar manualmente no ambiente descartável, a variável
`F21_PG_CONTAINER` deve identificar um container postgres:18 sob seu
controle exclusivo e o script deve ser invocado da raiz do repositório.
**Nunca rodar contra um servidor existente**; `schema.sql` cria roles
e schema e pressupõe superusuário de teste.

## Restrições cruciais

- O runner usa superusuário de testes para executar
  a conexão local `postgres` **exclusivamente para criar as fixtures**.
  Os testes do usuário A e B se conectam como roles SQL distintos com
  privilégios de leitura. A conexão local depende da confiança do
  container descartável; isso não autentica usuário real, nem prova que
  uma aplicação comprometida não possa escolher outra identidade no serviço.
- Um banco de produção exige identidade derivada de um emissor confiável,
  conexão sem SUPERUSER/BYPASSRLS, política de pool, escopo por tenant,
  segregação de owner/migrator e revisão de AppSec/DBA.
- A prova não cobre RLS com todas as tabelas reais, fontes externas,
  gateways e backends dos motores Sites/CRM/Performance, logins, MFA,
  concorrência entre writers nem anti-rollback SEC-R03.
- Os estados de contrato são artificiais e não demonstram contrato
  assinado, pagamento, elegibilidade, mensalidade ou emissão de notas.
- Nenhum resultado desse lab autoriza leitura de cliente real, schema
  operacional, migração, conexão ao Portal, merge ou deploy.
- **Gates de produção P0, D02-SEC, D03-RLS, T3 e SEC-R03 permanecem
  ABERTOS**. Esta PoC é filha do PR F20, sem alterações em DEV/main.

## Evidência e critérios

Somente registrar PASS depois de conferir na HEAD exata: workflow F21,
testes anteriores PHP 8.3/8.4 e Node 22/24, logs de negação SQL e
destruição dos recursos. Se um workflow for SKIPPED, não contabilizar
como execução.

Nenhum serviço real é disponibilizado por este laboratório.
