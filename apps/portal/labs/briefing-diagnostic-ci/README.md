# Candidata privada única — diagnóstico ordenado do briefing

Pai único exigido: #945 `3d222e95627e8153614a44633bad69464ae16446` (árvore publicada `2695548b34a328fb894c173175fe05c479b039e2`). Base planejada `test/onepage-briefing-pg-final-v1`; nova branch exclusiva `test/onepage-briefing-diagnostic-v1`. Este pacote não criou nenhuma ref, commit público, PR ou execução. É uma árvore privada de 17 adições: 16 arquivos deste laboratório e `.github/workflows/onepage-briefing-diagnostic.yml`. Não altera o laboratório/workflow #945 ou os pacotes anteriores.

## Eventos, bytes e fontes

Workflow novo somente pull_request opened, draft, run_attempt=1, mesmo repositório rafhael100/imagine-portal, par literal das branches, base SHA exato, checkout no head do evento, pai único #945, árvore limpa e diff de exatamente 17 adições 100644. O preflight verifica também os blobs herdados #941/#944/#945, inclusive os 16 arquivos publicados #945, antes do tooling. Não há synchronize, reopened, workflow_dispatch, secrets, persistência de credenciais ou artifacts. Outros workflows amplos podem executar independentemente.

A coordenação deve registrar externamente patch/manifesto, árvore completa e HEAD revisados antes de criar a ref. SHA do evento não é aprovação independente. Nenhum arquivo exige o hash do próprio commit. O hash de árvore aditiva no manifesto representa somente estas 17 adições, não a árvore completa futura do repositório.

## Dois defeitos de observabilidade sustentados

1. No #945, scope só repassava os marcos se o worker retornasse sucesso completo. Reprodução anterior com filho inofensivo comprovou que um marco válido seguido de falha desaparecia do log. Logo o log não demonstra ausência de manutenção/DDL.
2. Na reprodução desta rodada, o PHPUnit do vendor local correspondente ao lock executou runBare, mas capturou o echo do método em seu buffer. A chamada direta não foi demonstrada inválida por falta de configuração; essa hipótese foi descartada pelo modelo. Um teste inteiramente inofensivo comprovou que o emitter novo, com fwrite(STDOUT) de literal permitido, chega ao pipe enquanto echo continua no buffer. Isso corrige uma barreira determinística à sequência de sucesso, mas NÃO prova qual foi a primeira falha no run #945.

Trace.php admite somente a lista ordenada de 13 marcos e fases com posições compatíveis. O stdout continua sendo o pipe PRIVADO capturado pelo supervisor, não FD público do workflow. fwrite verifica comprimento; uma falha de escrita/malformação não é tratada como erro SQL nem como sucesso. Não imprime objetos, mensagens de exceção, SQLSTATE, consultas, IDs, nomes de pessoas, parâmetros ou material de autenticação.

## Ordem observável no worker

AUTOLOAD_READY → BOOTSTRAP_READY → MAINTENANCE_CONFIG_READY → MAINTENANCE_BEFORE_DDL → DATABASE_CREATED → TEST_ENTERED → NEW_DATABASE_GATE_PASSED → LARAVEL_SQL_BEFORE_DDL → MIGRATIONS_COMPLETE → SCHEMA_COMPLETE → FIXTURES_READY → COMMIT_OWNER_REPLAY_REVOCATION → WORKER_COMPLETE.

Fases de recusa são literais: AUTOLOAD, BOOTSTRAP, MAINTENANCE_CONFIG, MAINTENANCE_GATE, CREATE_DATABASE, TEST_BOOTSTRAP, NEW_DATABASE_GATE, ADMIN_GATE, MIGRATIONS, SECURITY_DDL, FIXTURES, CASES, TEST_TEARDOWN e FINALIZE. Cada uma aceita somente a quantidade exata de marcos anteriores. O marcador combinado LARAVEL_SQL_BEFORE_DDL continua ocorrendo apenas DEPOIS das duas atestações integrais. As migrations e os DDL diretos não foram antecipados.

scope e launch validam toda a sequência e propagam apenas prefixos exatos com fase compatível. Saída adicional, prefixo incompleto, duplicado/reordenado, erro fora do contrato ou falha da captura produzem BRIEFING_WORKER_CAPTURE_REFUSED; isso descreve uma falha do protocolo/captura, não a causa da aplicação. Nenhuma recusa pode ser promovida a sucesso. Falhas antes do catch não geram causa presumida. O filtro externo aceita somente esses literais e os marcadores fixos de supervisor/cleanup.

## Isolamento e autoridade preservados

CiGate.php, root_wrapper.py, processes.py, Fixture.php e store.sql.in permanecem byte a byte os aprovados. O finally de scope é AST-idêntico. O teste nativo, retiradas somente as linhas de Trace e restaurados seus dois echo antigos, é byte-idêntico ao #945. Não se retirou asserção, gate ou caso negativo; PDO admin continua readonly, mesma atestação SQL que Laravel, mesma comparação de cluster, e o PDO Laravel é conferido antes das escritas. Nenhuma alteração de roles, grants, autorização, preço, dueDate ou status do pedido.

Mantidos PG18 foreground e worker separados, net+mount namespaces novas, sysfs/lo e observação PID1 por root antes do drop, PHP não-root sem caps/NNP, GitHub explícito, gates Laravel/PDO/SQL antes de DDL e ausência de fallback. Mantida a prova obrigatória de startup recusado em cluster próprio antes do cluster do briefing. Cleanup só se afirma com recolhimento/parada/remoção comprovados; timeout externo e kill forçado não bastam. FD3 continua fechado nos subprocessos dos quatro passos shell.

## Preparação do runtime e limites da prova local

O lock local é blob `13e6715405e4719ab5dc56b8f22dcce1c855a66d`, igual ao #945; os metadados version/reference dos 102 pacotes instalados coincidem com ele. Isso não atesta checkout integral nem todos os bytes do vendor ou a ini/binários do job. Portanto não foi feito nem alegado boot Laravel local equivalente. O modelo PHPUnit usa biblioteca real, porém não Laravel, conexão, banco, navegador ou MFA.

Achado adicional: prepare.py antes permitia scan do ini ambiente enquanto o worker o desativava. Os checks agora também usam PHP_INI_SCAN_DIR vazio; a mudança fortalece a equivalência e pode recusar módulo ausente ANTES de PG. Não é apresentada como causa comprovada da falha. Provisionamento autenticado PG e ações oficiais mantêm o precedente aprovado; nada foi instalado nesta rodada.

## Executado nesta revisão

38 checks novos de fase/prefixo/captura, 31 do filtro, quatro filhos inofensivos reais, cinco checks novos da ordem PHP Trace, igualdade PHP/Python do contrato, prova real de buffering do PHPUnit e passagem do Trace, 16 casos do novo evento one-shot, seis lints PHP, sete AST Python e sintaxe/FD dos quatro blocos shell. Conferidos os 17 caminhos exatos, gates/fixture/cleanup preservados e corpo do teste sem mudanças funcionais. As suítes anteriores não foram reexecutadas aqui.

Não executado: bootstrap Laravel equivalente, SQL/PG/initdb, migrations/casos de briefing, namespace/root wrapper, provisionamento/Composer ou CI. A execução futura autorizada é que poderá localizar a primeira falha de aplicação e comprovar a jornada. Esta candidata exige revisão independente e autorização pública específica para uma única abertura do draft filho de #945. Sem Asaas/VPS/dados reais/produção/merge/deploy.
