# Preparação local descartável P20

Esta ferramenta corrige a falha de procedimento observada no PR #910: o PostgreSQL pode devolver `inet_server_addr()::text` como `127.0.0.1/32`, e uma exceção tratada pelo bootstrap Laravel pode encerrar um script com código zero. A preparação agora exige prova estruturada antes da etapa de migrations e conclusão explícita antes de devolver zero ao shell.

Escopo: Linux, PHP CLI com `pdo_pgsql`, `posix` e `proc_open`, PostgreSQL 18 local, dependências travadas do Portal. O usuário que iniciou o cluster precisa poder ler seu diretório, `postmaster.pid`, `/proc/<pid>/cmdline` e executar o `pg_controldata` oficial da instalação. Não funciona contra serviços remotos, containers com PID/diretório invisível ao processo PHP ou bancos compartilhados. Não muda workflows existentes; sua adoção em CI exige outra alteração autorizada.

## Uso autorizado

Crie/inicie previamente um cluster **novo e descartável**, em diretório próprio desta tarefa, escutando exclusivamente em `127.0.0.1`. Crie nele o banco sintético `portal_automation_p20_ci`. Os argumentos abaixo devem identificar esse cluster e o binário oficial; não use caminhos de uma instalação operacional. Esta ferramenta não descobre servidores, cria clusters ou concede autorização para usar um banco.

Use o ambiente sintético do workflow P20, incluindo `APP_ENV=testing`, `CI=true`, `P20_AUTHORITY_PG_CI=1`, `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_DATABASE=portal_automation_p20_ci`, `DB_USERNAME=postgres` e a senha exclusivamente local. Defina `DB_PORT` com a porta do cluster e passe a mesma porta como último argumento. Configure `APP_KEY` previamente pelo procedimento local habitual. Não registre senhas na evidência.

A partir de `apps/portal`, com `P20_DATA_DIR`, `P20_CONTROL_BINARY` e `P20_PORT` definidos pelo operador:

```sh
# Somente leitura do destino; não concede permissão reutilizável para migrar.
php tools/p20/prepare.php --check "$P20_DATA_DIR" "$P20_CONTROL_BINARY" "$P20_PORT"

# Revalida tudo e chama o comando Laravel migrate no mesmo processo e conexão.
php tools/p20/prepare.php --migrate "$P20_DATA_DIR" "$P20_CONTROL_BINARY" "$P20_PORT"
```

Não use `--check; php artisan migrate`: a segunda invocação perderia a vinculação entre configuração/conexão e verificação. Para guardar logs com `tee`, use `set -o pipefail` em Bash. Sucesso exige exit code zero **e** a linha JSON final com `schema=P20_PREPARATION_RESULT_V1`, `completed=true` e o comprovante `target`; um log anterior não autoriza outra execução. A fixture de role e os testes de autoridade não são executados por esta ferramenta.

## Ordem das verificações

1. Carrega a configuração efetiva do Laravel (inclusive configuração em cache) antes de inicializar os providers. Exige `testing`, conexão `pgsql`, banco dedicado, owner sintético `postgres`, host literal `127.0.0.1` e porta exata. Recusa hostname, lista de hosts, URL, read/write routing, DSN alternativo e opções adicionais. Depois do bootstrap, exige a mesma configuração. Não abre conexão SQL nessa fase.
2. Confere diretório canônico, arquivo PID, processo PostgreSQL vivo com `-D` igual ao diretório esperado, porta e listener. Executa `pg_controldata` sem shell, com locale C; exige código zero e exatamente um identificador de sistema. Saída vazia/duplicada ou falha do subprocesso não libera SQL nem migrations.
3. Confere também a configuração resolvida do objeto de conexão Laravel antes de abrir PDO. Pela conexão de escrita, consulta banco, endereço/porta, `data_directory`, início do postmaster e `system_identifier`. Cruza esses valores com a evidência local. Somente o endereço SQL exato `127.0.0.1` ou `127.0.0.1/32` é aceito; redes como `/24` ou formas ambíguas são recusadas.
4. Constrói e valida `P20_DISPOSABLE_TARGET_V1`, reconfere configuração e identidade local e exige escrita completa do comprovante JSON. A etapa seguinte recebe esse comprovante; não depende de procurar uma palavra em stdout.
5. `--migrate` chama o Kernel Laravel com `migrate --database=pgsql --force --no-interaction`, no mesmo processo e objeto PDO verificado. Reconexão automática é proibida. Código de erro ou exceção não produz resultado de sucesso. Nenhum arquivo de migration é editado.
6. O entrypoint captura `Throwable` e exige conclusão estruturada. Um shutdown prematuro, inclusive `exit(0)` durante o bootstrap, devolve 1. Mensagens de exceção e credenciais não são impressas. A localização do código que negou a execução é exibida para diagnóstico.

A garantia é sobre falhas **da verificação antes de iniciar migrations**. Uma falha durante uma migration devolve erro e pode deixar alterações já aplicadas naquele banco descartável; não há promessa de rollback global. O código das migrations e do bootstrap continua sendo código local confiável, revisado no commit escolhido.

## Testes e evidência desta mudança

```sh
php vendor/bin/phpunit --filter=DisposablePreparationTest --no-progress
```

Os testes usam processos PHP filhos e uma etapa sentinela no lugar das migrations. Configurações inválidas são rejeitadas antes da callback de consulta; divergências SQL/cluster, mudança de configuração, provas ausentes, falhas de subprocesso, exceção e saída zero prematura terminam com código não zero sem criar a sentinela. As consultas desses testes são falsas: nenhum host, inclusive o endereço documental `192.0.2.1`, é acessado.

Validação local em PHP 8.4.24 / PHPUnit 12.5.43 / PostgreSQL 18.7:

- 38 testes / 156 assertions; formatos SQL simples e `/32` aprovados.
- `--check` aprovado no cluster descartável anterior em porta 5432.
- Novo cluster descartável em porta 55432: um substituto local de `pg_controldata` que sai com 7 e outro que sai com 0 sem comprovante foram ambos recusados com exit 1. Consulta local após as recusas confirmou ausência da tabela `migrations`.
- Com o binário oficial, a mesma ferramenta comprovou Laravel/SQL/PID/diretório/início/identificador, executou as 19 migrations existentes e produziu resultado estruturado de sucesso. Cluster encerrado ao final.
- A suíte de autoridade e o ensaio entre aplicações não fazem parte desta validação; PRs #908/#910 permanecem preservados.
