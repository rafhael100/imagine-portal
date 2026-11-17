# Leitor atômico do pedido próprio — somente laboratório

Autorização vigente: função de leitura do próprio pedido com MFA/vínculo ativos, permissão apenas para executá-la no banco descartável. Base #923 3533509746c93991a47819ff0d14c40fc80db3e5 preservada. Nenhuma política financeira ou de preview alterada.

`read.sql`: candidato local NÃO aplicado. Guarda SQL de destino descartável igual à base, depois função `read_pending_order(text,uuid)` SECURITY DEFINER/owner interno existente, search_path fixo e row_security=on. Só EXECUTE para onepage_buyer_runtime; PUBLIC revogado; nenhum SELECT direto, EXECUTE principal, role/membership ou grant operacional novo. Principal nativo antes/depois conserva locks e todas as regras existentes. Predicados id+company+buyer+pending_payment, ausência genérica. Projeção explícita sem request_hash/segredos. Corpo sem DML/writer/SQL dinâmico; implantação da função/grant é preparação separada, não operação runtime.

`AtomicReader.php`: uma consulta parametrizada sessão server-side+pedido, sem principal fornecido pelo cliente. Factory guarded reaproveita Guard::runtime e barrier efetiva Laravel/identidade SQL do harness; antes/depois por chamada. Constructor público é seam para spies, não autoridade alternativa operacional; usar factory guarded no PG. Nenhuma rota/provider. Sem cache/replay writer, PSP ou preview; retorno pendente mantém settled=false, preview_admitted=false, binding=null. Locks do principal significam que não se promete transação PostgreSQL READ ONLY; não remover locks.

`offline.php`: 10 checks incrementais realmente executados com PHP8.4.24, spies de PDO/ateste e inspeção do corpo SQL. Consulta única parametrizada, ordem de ateste, replay, null, guard negador, status inesperado, ausência DML, dupla revalidação/predicados, único grant e projeção sem segredos. Inspeção textual NÃO compila SQL nem prova ACL/RLS. Não repetidos 21/42/25 checks anteriores.

`pg-checks.php`: hook incremental preparado, NÃO executado; deve ser chamado no mesmo ciclo de vida de um cluster efêmero já atestado, com pedido persistido e sessões nativas próprias/segundo comprador ativo na mesma empresa/fixture expirada do mesmo comprador. Não chamar o runner #923 e depois tentar reutilizá-lo: seu EXIT já encerra o cluster; não repetir suas 42 provas para alimentar este hook. A composição abaixo fornece essa montagem sem executar os 42 casos antigos. Não cria pedido, não usa admin para leitura runtime; bootstrap somente prepara função e verifica fixtures/ACL/snapshots. A mutação de vínculo suspenso é exclusivamente fixture negativa, após controle ativo, guard e callback; não fica no corpo do leitor. Não restaura autoridade revogada.

Hook testa leitura própria/repetição sem alterar tabela, segundo comprador ativo mesma empresa, inexistente com mesma recusa, SELECT direto/principal negados especificamente SQLSTATE42501, sessão expirada do mesmo comprador controlada por SQL, revogação persistida de vínculo após controle positivo e snapshot de pedidos intacto. O schema nativo tem status/revisão de membership e expires_at de sessão: não inventamos expiração de membership inexistente. Autoridade expirada é testada via sessão nativa. Nenhum resultado PG é alegado.

Comandos offline na raiz (executados):

```sh
php apps/portal/labs/onepage-atomic-read/offline.php
php -l apps/portal/labs/onepage-atomic-read/AtomicReader.php
php -l apps/portal/labs/onepage-atomic-read/offline.php
php -l apps/portal/labs/onepage-atomic-read/pg-checks.php
git diff --check
```

Impedimento material: executor com socket bloqueado; não iniciado PG nem contornado. Próximo passo: revisar este pacote e executar a composição curta em ambiente descartável permitido; conferir SQL/ACL/locks/snapshots/cleanup reais. O comando de composição e workflow abaixo são candidatos locais; nenhuma execução CI ou publicação foi autorizada por este pacote. GET Sandbox permanece separado: termos financeiros autorizados + binding imutável + conta/escopo + pagamento de teste já existente + diagnóstico GET-only autorizado/credencial fora do chat. Pedido pendente não atende esses requisitos.

## Composição curta sobre #923, preparada sem execução SQL

`run.py` confere SHA256 de 11 arquivos base (#923/#922), extrai somente o prefixo de preparação de pg-cases.php antes das antigas fixtures, e compõe em diretório temporário privado o launcher/worker fixados. Alterações nas cópias temporárias: localização da base preservada e etapa pg-cases substituída pela fixture incremental. Nenhum arquivo original é editado. prepare.php, ProcessPreflight e Guard são usados intactos; drift de hash/estrutura aborta. Não há download nessa composição.

`fixture-tail.php.inc` cria duas empresas sintéticas, dois compradores ativos na mesma empresa e um na outra. Senha/TOTP/sessões positivas são emitidas pelo fluxo nativo. Cria UMA cotação/termos/pedido pendente usando os adapters já validados: esse writer é apenas preparação da fixture antes da fase de leitura. A fase nova não chama accept_order. Seed apenas da sessão expirada negativa; suspensão de membership como fixture negativa é avaliada pelo principal nativo, não se alega endpoint nativo de suspensão. Sem política financeira.

O hook agora exige positivo com TODOS os campos persistidos comparados, catálogo owner/SECURITY DEFINER/PUBLIC/runtime/ausência de grantees extras e privilégios diretos. Cada negativa funcional executa adicionalmente SELECT parametrizado direto no runtime atestado, com sucesso e NULL: exceção PDO/SQL não satisfaz recusa. As duas negativas de privilégio exigem SQLSTATE42501. São 16 verificações incrementais previstas, SQL NÃO executado. Snapshot de pedidos antes/depois cobre somente a fase de leitura/recusas; fixtures anteriores escrevem no cluster sintético como preparação.

O runner exige locale C, ambiente mínimo, opt-in e PHP absoluto; herda do #923 PHP -n/extensões enumeradas, recusa dotenv/cache/PG* e ateste efetivo Laravel/SQL antes de schema/fixtures. Captura saída bruta em arquivo temporário privado descartado e só imprime lista fixa de marcadores depois de validar todos, incluindo os 16 casos exatos, conclusão SQL e parada do cluster. Qualquer exit não zero/marker ausente/duplicado falha. Não publica logs brutos, dump, PGDATA ou artefatos. O cleanup reutilizado para o servidor; diretório privado com dados sintéticos permanece no disco efêmero do runner até seu descarte, como no #923, não se alega apagamento de PGDATA.

Comandos disponíveis (primeiros dois executados offline, último NÃO executado):

```sh
python3 apps/portal/labs/onepage-atomic-read/test-runner.py
env -i PATH=/usr/bin:/bin LC_ALL=C PHP_BIN=/caminho/php \
 /usr/bin/python3 apps/portal/labs/onepage-atomic-read/run.py --check
# Somente após aprovação de execução em ambiente efêmero permitido:
env -i PATH=/usr/bin:/bin LC_ALL=C PHP_BIN=/caminho/php PG_BIN=/caminho/pg18/bin \
 ONEPAGE_PG_RUNTIME_LIB=/caminho/pg18/lib ONEPAGE_ORDER_ALLOW_LOCAL_START=SYNTHETIC_ONLY \
 /usr/bin/python3 apps/portal/labs/onepage-atomic-read/run.py --run
```

--check verifica pins, compõe e faz bash -n/lint PHP do arquivo resultante: não chama aplicação/PDO/PG. Resultado real nesta rodada: 11 checks novos do runner offline, composição/lints e YAML/blocos shell aprovados. Os 10 checks do adaptador anteriores são reaproveitados, não rerodados aqui. Locale ausente inicialmente foi recusado; comando corrigido exige LC_ALL=C explícito, sem relaxar allowlist.

Único workflow novo local: `.github/workflows/onepage-atomic-read-pg-lab.yml`. Draft do mesmo repo, head test/onepage-atomic-read-lab-2026-11-17, base branch #923 e SHA3533509746c93991a47819ff0d14c40fc80db3e5. contents:read, checkout sem credencial persistida, Ubuntu24.04 efêmero, timeout15min, release/PSP disabled; extração PG18 autenticada copiada do procedimento #923, Composer lock sem scripts/plugins. Zero credenciais de negócio/PSP e nenhum workflow anterior alterado. Não executado/publicado.

Inventário consolidado: oito arquivos neste diretório (AtomicReader.php, README.md, offline.php, pg-checks.php, read.sql, fixture-tail.php.inc, run.py, test-runner.py) e o único workflow novo. Patch seleciona apenas esses nove arquivos sobre #923; não publicar o histórico local com documentos/experimentos anteriores.

## Extensão local candidata: reserva antes da emissão simulada

`reserve.sql` e `reserve-checks.php` só entram depois do ateste efetivo do
runner original, do ateste SQL do runtime e da comparação do snapshot nativo
com o pedido persistido. Não rodar ou importar a SQL isoladamente. Bootstrap
insere o plano sintético D0/D30/D60 de d776fb em uma única transação; somente
a fixture sabe escrever as linhas. O mesmo runtime lab/buyer, com papel nativo
restrito, ganha só EXECUTE para CAS, e o CAS repete a verificação do pedido
exato (inclusive revogação) antes da tentativa. Sem papel novo, sem permissão
direta para SELECT/DML no ledger e sem tocar roles de produção. Não se propõe
reutilizar esse papel de comprador para uma futura emissão operacional.

O latch `attempted` é de mão única: só depois de COMMIT uma vitória pode contar
um fake-send local. Perda antes de COMMIT mantém o direito de tentar; perda
de conexão **depois** de COMMIT e antes de send não reabre tentativa. A fixture
não faz GET, não grava recibo de criação, não dá baixa, não autoriza produção.
Não é uma política de retry nem uma fila operacional. Resultado esperado do
runner só sai depois do marcador da reserva, dos 16 casos originais e do
encerramento do cluster. Use apenas o mesmo runner original, em executor
disponível e expressamente autorizado para esta fixture, com PHP 8.4 limpo e
PostgreSQL 18 descartável, sem PSP, segredo, serviço ou workflow novo.
