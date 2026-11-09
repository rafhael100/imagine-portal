# PORTAL-F36 — bloqueio por deriva do catálogo da confirmação de revogações

**09/11/2026 · Somente laboratório Laravel/PHP e PostgreSQL 18 efêmero.**

## Motivo

A F35 registra uma confirmação do evento no banco testemunha **depois**
de verificar o estado revogado no banco primário. Antes da F36, essa
confirmação dependia de uma role SQL restrita, porém o leitor PHP não
verificava se o RLS, a policy SELECT, os grants ou a role tinham sido
alterados após a criação da tabela de `ack`.

A F36 adiciona uma **verificação negativa de deriva**, sem apresentar a
checagem do catálogo PostgreSQL como uma autoridade externa confiável.

## Implementação

- `App\Labs\F36\F36AckContractInspector` consulta `pg_catalog` pela
  própria conta SQL de confirmação. Exige tabela
  `f35.applied_event_ack` com `ENABLE/FORCE RLS`, `relkind=r`,
  owner do laboratório `postgres`, exatamente uma policy
  `f35_ack_reader` do tipo SELECT, role explícita
  `f35_ack_worker` e predicado
  `session_user='f35_ack_worker'`.
- Exige uma única concessão de tabela externa ao owner:
  SELECT para `f35_ack_worker`. Rejeita grants de escrita, acesso
  a PUBLIC e roles adicionais.
- Exige role `f35_ack_worker` LOGIN/NOINHERIT, sem superuser,
  BYPASSRLS ou relações de membership com outras roles.
- `F35WitnessAckStore` agora consulta o inspector **antes**
  tanto de ler ack quanto de chamar o registrador de ack F35.
  Retorna `null` se houver deriva, que o reconciliador interpreta
  como `ack_unavailable`, **nunca** como fila vazia nem
  confirmação válida.
- `F36AckCatalogDriftTest.php` faz 10 testes com mudanças
  no **banco testemunha descartável**: baseline, RLS desabilitado,
  FORCE removido, policy `USING TRUE`, policy TO PUBLIC, policy
  extra permissiva, GRANT UPDATE indevido, GRANT SELECT PUBLIC,
  role INHERIT, troca de proprietário. Cada teste usa
  `finally` para restaurar grants/owner e exige recuperação.
- Os jobs GitHub Actions PHP 8.3/8.4 carregam o mesmo
  PostgreSQL 18 temporário de F31–F35 e executam F36
  antes do ensaio `pg_dump/pg_restore` fictício F32.

## Limitações fundamentais

**A F36 não certifica a identidade, autoridade ou durabilidade de um
witness real.** Um superusuário com acesso a banco e código pode
falsificar o catálogo e a implementação simultaneamente. A comparação
do predicado é textual e serve somente ao schema e versão de PG18 da
fixture; não é uma prova semântica geral de RLS.

F36 não demonstra que a função `SECURITY DEFINER` F35 valida commits
reais na primária; o banco testemunha não consegue fazer essa
validação de forma independente no laboratório. A ordem
`primary verify -> ack` existe apenas no código de reconciliação,
que pode estar sujeito a falhas após a checagem.

Os limites operacionais herdados continuam: testemunha sob o mesmo
domínio administrativo do runner, sem outbox e fila duráveis, sem
agendamento real, entrega garantida, MFA, identidade Portal oficial,
revogação linearizável, proteção de desastre conjunto, monitoramento
ou homologação AppSec/DBA. F34/F35 continuam limitados a 20
eventos sem paginação.

**Estado:** PR DRAFT e sem merge/deploy, sem VPS, Cloudflare,
main/dev, clientes, dados pessoais ou contrato real.
SEC-R03/Z4, P0/D02/D03/T3/OPEN continuam pendentes. O backend
principal do Portal permanece Laravel/PHP; Node.js apenas CI/legado.
