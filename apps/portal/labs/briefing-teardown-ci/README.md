# Candidata privada — ciclo completo PHPUnit/Laravel após #946

Pai único: #946 `e5ef89b01b15c1815cfc9972961801ad470226b4`, árvore publicada `6220d4fddbc8f2124919ab961f8f79e380272a56`. Base planejada `test/onepage-briefing-diagnostic-v1`; filha exclusiva `test/onepage-briefing-teardown-v1`. Nenhuma branch, PR ou CI foi criada nesta preparação. Os pacotes e o workflow #946 permanecem intactos.

## Diagnóstico sustentado

O resultado informado do job 134919801015 chegou a COMMIT_OWNER_REPLAY_REVOCATION e depois recusou TEST_TEARDOWN com cleanup VERIFIED. Isso prova progresso das assertivas, NÃO sucesso do job ou ciclo integral.

O worker exato do pin usa diretamente TestCase::runBare(). Tests\\TestCase não tem override de teardown: herda o Laravel. A cadeia relevante é tearDown → tearDownTheTestEnvironment → flushState → HandleExceptions::flushState/flushHandlersState → PHPUnit ErrorHandler::instance → Configuration Registry::get. Este último requer a configuração inicializada pelo runner TextUI; a chamada manual isolada não faz essa inicialização.

Reproduzimos com Laravel13.40.0/PHPUnit12.5.43 do vendor local, cujo lock e metadados version/reference dos 102 pacotes coincidem com o lock #946 (blob13e6715405e4719ab5dc56b8f22dcce1c855a66d). O método inofensivo terminou, callbacks e teardown foram alcançados e a falha ocorreu com frames das classes Registry e HandleExceptions. O modelo não acessa PG, .env, dados, secrets ou rede e não faz bootstrap da aplicação de produto.

Uma segunda prova mostrou que trocar somente runBare pelo runner normal, mantendo o estado de handlers da aplicação de manutenção pré-existente, ainda não produzia sucesso. A transição explícita desse estado, depois de inicializar a configuração PHPUnit e antes do teste, resolveu o modelo. Não expomos mensagens/exceções para classificar a segunda falha além desse resultado observado.

O mecanismo é determinístico e compatível com a fase real, mas o log sanitizado do CI não fornece a exceção original; não afirmamos excluir outros defeitos nem declarar o #946 verde. O modelo PHPUnit puro anterior não executava o teardown Laravel, portanto não cobria este acesso ao Registry.

## Correção mínima de integração

worker.php usa PHPUnit\\TextUI\\Application::run somente sobre NativeBriefingPgTest.php. --no-configuration impede carregar o phpunit.xml genérico que alteraria o banco; --no-output impede relatórios brutos. Não habilita suíte adicional nem substitui asserções. Retorno nãozero é recusado; FINALIZE/WORKER_COMPLETE só são emitidos depois do retorno zero. Também recusa warning, risky, skipped, incomplete e suíte vazia. A sequência Trace continua exigindo todos os marcos do teste, de modo que nenhum teste executado/skip não pode simular sucesso.

RunnerBootstrap.php é carregado pela API normal --bootstrap DEPOIS de Registry::init (ordem conferida no código PHPUnit). Ele encerra apenas a aplicação de manutenção já utilizada e purgada, limpa suas Facades/handlers com a API Laravel, e deixa o runner iniciar o teste e TODOS os hooks nativos. Não lê PDO, não toca grants ou fixtures e não contém catch que absorva erro. Não chama nem omite tearDown do teste; esse pertence integralmente ao runner. Falha desse bootstrap continua recusada pelo processo/supervisor, sem saída bruta pública.

LifecycleModelTest.php reproduz esse ciclo em memória, inclusive uma aplicação externa de manutenção com handlers antes do runner. Antes de qualquer PG, prepare.py exige três processos-modelo com saída fixa:
1. Chamada manual: método concluído e falha esperada no Registry durante a limpeza real Laravel.
2. Runner normal + bootstrap: método, callback, flush, tearDown e tearDownAfterClass concluídos.
3. Callback deliberadamente falho: hooks/flush/afterClass ainda executam e o retorno do runner é nãozero; o modelo prova propagação, não tolerância a essa falha.

## Escopo preservado e evento único

19 adições: 18 arquivos em briefing-teardown-ci e um workflow novo. A cópia isolada evita editar qualquer arquivo #946. A mudança funcional fica no worker e no novo bootstrap; o novo modelo e seus checks exercitam somente lifecycle. CiGate.php, NativeBriefingPgTest.php, Fixture.php, store.sql.in, Trace.php, offline.php, scope.py, processes.py, root_wrapper.py e launch.py são byte-idênticos ao #946. Permanecem as duas atestações completas, readonly do admin, todos os casos e ACLs, sequência de marcos e cleanup obrigatório. Sem nova política, role, grant, alteração de status ou autorização.

Evento somente pull_request opened/draft/run_attempt1, mesmo repositório, par literal das branches, base SHA exato, commit filho único, checkout no HEAD do evento, árvore limpa e exatamente 19 adições 100644. Preflight verifica fontes herdadas, inclusive os 17 arquivos #946. Sem dispatch, synchronize, reopened, retries, secrets, persist-credentials ou publicação de logs/artifacts. Outros workflows amplos do repositório podem disparar independentemente.

A coordenação deve conferir bytes/manifesto e aprovar externamente a árvore completa/HEAD ANTES da ref. O SHA do evento não é aprovação independente; não existe hash autorreferente no commit. O hash de árvore aditiva do manifesto representa somente as adições, não o futuro repositório completo.

## Evidência e limitações

Executados: três modelos reais com PHPUnit/Laravel local e aplicação em memória, inclusive a aplicação externa e afterClass; seis checks novos de contrato/ordem do runner/bootstrap; nove casos do novo evento; oito lints PHP; sete AST Python; quatro parsings shell/FD; igualdade dos dez arquivos de segurança/teste/cleanup e dos hashes do pacote #946. O modelo local é idêntico ao candidato exceto pela resolução do autoload para o vendor já existente; a diferença está registrada no manifesto. Nenhuma suíte PG/briefing anterior foi repetida.

Não executados: aplicativo/briefing com banco, SQL/migrations/PG/initdb, namespace/root wrapper, provisioning/Composer, workflow ou CI. A comparação do lock/metadados não é atestação byte a byte de todo vendor nem do executor GitHub. A próxima execução somente poderá ocorrer após revisão independente e autorização pública específica para uma abertura do draft filho de #946. Sem Asaas, VPS, dados/credenciais reais, merge ou deploy.
