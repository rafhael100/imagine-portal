# SEC-R03-F09 — Ledger monotônico transacional separado do primário em laboratório

**Natureza:** experimento de persistência apenas em testes Laravel/PHP, em um arquivo SQLite criado num diretório descartável da CI. **Não** é witness externo de produção nem local seguro para chaves operacionais.

O componente `Tests\\Support\\DisposableSqliteWitnessLedger` registra eventos com UUID v4 em um arquivo SQLite *diferente* do PostgreSQL primário. Usa `BEGIN IMMEDIATE`, `journal_mode=WAL`, `synchronous=FULL` e um índice global de evento mais sequência única por empresa. Eventos são **idempotentes apenas quando todas as informações coincidem**; o mesmo UUID com empresa/geração/revisão diferente é negado. Atualização/remoção das linhas por SQL é bloqueada por triggers; append exige sequência crescente unitária, geração de backup não regressiva e pisos de sessão e membership não regressivos. Concorrência com lock de escrita resulta em falha fechada e permite um novo avanço após liberação do lock.

`DisposableSqliteWitnessLedgerTest` testa duas instâncias que reabrem o mesmo arquivo, simulação de restauração do primário com o witness mantido, tentativa de reativação de sessão, UUID repetido e adulterado, revogação de outro tenant, regressão de pisos, alteração SQL direta, lock concorrente, nome de arquivo fora de diretório efêmero e ausência de conhecimento sobre armazenamento realmente externo. O workflow Laravel instala `pdo_sqlite` e `sqlite3` em **PHP 8.3 e 8.4** para executar os testes sem banco primário, servidores remotos ou credenciais de cliente.

## Riscos não resolvidos

SQLite e seu arquivo WAL só fornecem persistência **local e transacional**; um snapshot do mesmo host/volume pode reverter ambos. Não há replicação externa, dispositivo imutável, separação de domínios de desastre, prova de publicação linearizável contra os commits PostgreSQL, política de chaves, quorum ou controles contra administrador com privilégios no mesmo armazenamento. O registro do *evento* não comprova que a aplicação principal o consumiu antes de confirmar logout. Não registrar esse fixture como serviço de produção ou usar o resultado para liberar HTTP/sessões.

Componente vive apenas sob `tests/Support`, com `APP_ENV=testing` e caminho restrito sob `/tmp/portal-witness-sqlite-lab-...`. Sem código de deploy, dados reais, custo novo ou alteração de VPS. **#64, #341 e revisão AppSec/DBA continuam NO-GO.**
