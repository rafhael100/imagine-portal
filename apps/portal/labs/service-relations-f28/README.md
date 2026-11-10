# PORTAL-F28 — Integridade negativa dos gatilhos de revogação

**09/11/2026 — laboratório exclusivamente sintético PHP/Laravel + PostgreSQL 18.**

## Contexto

F25 criou uma barreira cooperativa de concorrência:
o leitor obtém um `pg_advisory_lock_shared` por empresa antes do snapshot
`REPEATABLE READ` e os gatilhos de escrita de sessão/membership
obtêm `pg_advisory_xact_lock` exclusivo no mesmo identificador.

**Falha encontrada:** até F27, o leitor verificava RLS, identidade SQL,
estado da sessão e membership, porém **não verificava se esses gatilhos de
escrita ainda existiam e estavam ativados**. Um operador com privilégios de
alteração do schema poderia desabilitar a coordenação sem que a leitura
percebesse. Isso não é uma prova de exploração em produção: o laboratório
não está implantado.

## Correção proposta

`App\Labs\F28\F28RevocationFenceInspector` é um verificador negativo
do contrato F25 na mesma transação do leitor:

1. Consulta `pg_catalog.pg_trigger`, `pg_class`,
   `pg_namespace` e `pg_proc` via PDO já autenticado na identidade
   sintética da empresa.
2. Exige **exatamente dois gatilhos não internos**, um para cada tabela
   `f24.session_fixture` e `f24.membership_fixture`, com os nomes
   `f25_session_write` e `f25_membership_write` respectivamente.
3. Exige ambos ativos no modo **O** (normal), BEFORE ROW para os
   três eventos INSERT/UPDATE/DELETE (bitmask do PostgreSQL = 31),
   sem condição `WHEN`.
4. Exige função `f24.synthetic_revocation_writer_gate` em modo
   SECURITY INVOKER, com corpo exatamente correspondente à fonte
   PL/pgSQL versionada pelo laboratório F25. Espaços somente nas
   bordas são normalizados; alterações internas bloqueiam.
5. Em qualquer desvio, **ANTES de consultar os dados fictícios**
   F24/F21, a leitura retorna
   `DemoServiceRelationPage::blocked('unsafe_revocation_fence')`,
   sem linhas, totais, cursor, permissões, contratação ou acesso.
6. A trava compartilhada obtida por F25 é mantida durante a checagem
   e liberada em `finally`, como já ocorria nas etapas anteriores.

O caminho F22 **sem** prova de sessão mantém os testes antigos e não
aciona esse verificador: F22 e F24 são classes não registradas em
rotas/providers, **não representam um endpoint de autorização real**.

## Testes CI

`tests/Integration/F28RevocationFenceIntegrityTest.php` acrescenta oito
cenários no container efêmero PostgreSQL 18 com senhas temporárias e
duas empresas sintéticas:

- baseline com funções e gatilhos íntegros nas duas empresas;
- gatilho de sessão desativado;
- gatilho de membership desativado;
- modo de execução alterado para REPLICA;
- função de trava substituída por corpo que não obtém lock, mesmo
  preservando o nome do gatilho;
- função alterada para SECURITY DEFINER;
- gatilho de membership excluído;
- gatilho adicional inesperado.

Cada teste de alteração usa o admin **somente do banco descartável**,
exige DENY, restaura o estado original em `finally` e comprova que
a leitura de fixture volta a funcionar. Nenhum arquivo, senha ou
dado de cliente é usado. Workflow de integração F22 inclui esses
testes nos jobs PHP 8.3/8.4; GitHub Actions pode consumir minutos
conforme o plano.

## Limites críticos

**Isto não é uma atestação independente, nem uma proteção contra
administrador malicioso ou rollback conjunto de banco e código.**
Um operador que controla banco e código pode atualizar simultaneamente
o corpo da função e o valor esperado. Outros writers podem não
participar de advisory locks, e outros leitores podem ignorar essa
verificação. O laboratório não valida todas as policies, triggers,
RLS, roles, grant chains ou configurações de replicação do Portal real.

Não há garantia de revogação linearizável global, anti-rollback
SEC-R03/Z4, emissor confiável de identidade, MFA, integração Ed25519
AUTH-PERF, serviços externos ou sessão real de cliente. Qualquer migração,
mesclagem em `dev`/`main`, infraestrutura, Cloudflare, VPS ou deploy
exige gate separado e revisão AppSec/DBA. A checagem F28 não substitui
uma autoridade de assinatura e trilha de auditoria externa.

**Estado:** PR DRAFT, filho de F27/F26/F25, com foco em
detecção de deriva e testes negativos. P0, D02-SEC, D03-RLS,
SEC-R03-X01..X24, T3 e OPEN permanecem bloqueados.
