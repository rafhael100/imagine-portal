# PORTAL-INT-F158 — Concorrência entre alterações de grants e estados de organização

## Risco mitigado

Os triggers F154 e F156 incrementam revisões que devem invalidar sessões antigas. Além dos testes de uma operação por vez, é necessário verificar que **duas conexões ao banco**, com transações simultâneas, não conseguem realizar alterações de status ou grants perdendo incrementos de revisão ou reutilizando estado anterior.

## Evidência executável

A CI `portal-int-f158-concurrent-pg18.yml` usa PHP8.3/8.4 e PostgreSQL18 descartável, aplica F66, F76, F154 e F156, e executa `labs/identity-f158/test_pg_revision_race.php`.

O teste cria dados 100% sintéticos e mantém duas conexões PDO independentes:

1. A conexão A abre transação, insere um grant, e o trigger F154 incrementa `membership_revision` de 1 para 2 com bloqueio de linha ainda ativo. A conexão B enxerga somente a revisão confirmada 1 e sua tentativa de suspender o membro dá **lock timeout (SQLSTATE 55P03)** — sem atualização parcial.
2. Após commit de A, B vê revisão 2, aplica sua alteração e a revisão alcança 3. Existe somente um grant, sem atualização perdida.
3. A conexão A abre nova transação e suspende a organização, elevando provisoriamente a revisão de 1 para 2. B enxerga somente a revisão anterior e não consegue encerrar a organização até a transação A terminar.
4. A dá rollback. A organização retorna a `active`, revisão 1. B pode então encerrar normalmente, chegando à revisão 2, sem herdar a mudança desfeita.
5. A sessão sintética criada com revisão da associação 1 e organização 1 fica desatualizada em **ambas** as dimensões após os commits. FORCE RLS segue ativo e sem políticas de aplicação.
6. O rollback da migração F156 (a última do lote) é recusado com registros; a CI verifica que **ambos os triggers F154/F156 e seus registros de migração continuam existentes**. Depois remove os fixtures e testa o rollback ordenado F156 → F154 → F76 → F66. A recusa direta do downgrade F154 é testada isoladamente pela CI F154; nunca se interpreta `Migration not found` do migrator como aprovação de segurança.

## Limites importantes

A prova utiliza duas conexões administrativas a um PostgreSQL descartável, **não** uma role de produção, autorização real, filas distribuídas ou carga elevada. Não mede tempo de bloqueios de alta concorrência, não prova ausência universal de deadlocks, não valida credenciais de usuário e não concede acesso. É um requisito de engenharia anterior à revisão AppSec/DBA/LGPD independente e à homologação autorizada.

**NO-GO:** nenhuma migração, sessão, OAuth, CRM, endpoint, deploy, despesa, alteração de `main` ou `dev` em produção.
