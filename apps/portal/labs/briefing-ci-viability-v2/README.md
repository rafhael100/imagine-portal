# Viabilidade CI v2 — PID1 observado somente por root

Base Portal #941 `0e0ccc06755b6ddd938635e5c4c3625ff6497e2c`. Pacote privado independente: oito adições sob briefing-ci-viability-v2 e workflow novo. Preserva v1, seus hashes/manifesto, Gate privado, candidato de briefing, gates CI anteriores e composição Comercial→Portal→Sites. Nenhum PG, namespace, mount, workflow ou POST foi executado aqui.

## Correção e caminho de proveniência

A v1 exigia readlink(/proc/1/ns/net) no PHP após retirar privilégios. Isso pode ser negado mesmo com leitura da própria namespace permitida. A v2 REMOVE apenas essa leitura do PHP; não ignora a prova de diferença nem concede ptrace/capabilities.

O supervisor não fornece baselines de topologia. Sob sudo, root_wrapper.py confere EUID0 e UID/GID originais não-root contra SUDO_UID/GID, captura self net/mount/PID e PID1 net/PID, e exige self PID namespace igual ao PID namespace do PID1. Falha de leitura recusa. Os cinco identificadores ficam em pipe anônimo root-owned, não em arquivo, env, argv de PHP, segredo ou log.

O wrapper exec unshare --net --mount --propagation private (sem --pid), reentra em modo interno como root e consome seu pipe. Confere fstat owner0/FIFO e limite de2048 bytes; valida campos exatos e formatos de namespace. Lê novamente self e PID1: PID namespace não mudou e continua o de PID1; PID1 net não mudou; self net difere tanto da net externa como de PID1; self mount difere do externo.

Só então prepara lo/sysfs, recolhe os mesmos fatos outra vez e exige igualdade com o snapshot pós-unshare. Qualquer drift de PID1, PID, net ou mount recusa antes do drop. Cria um segundo pipe anônimo root-owned contendo somente oito identificadores (parent net/mount/PID, init net/PID, child net/mount/PID). Fecha o lado escritor; só o leitor FD9 é herdado pelo setpriv/PHP. FD9 é canal interno recém-criado, não descritor de saída pública. Não recebe dados do consumidor.

setpriv retira UID/GID root, grupos adicionais e todos os conjuntos de capabilities, com no-new-privileges. PHP confere o próprio fstat do FD9 (owner root e FIFO), limite e chaves exatas; lê autonomamente /proc/self/ns/{net,mnt,pid}; exige igualdade com a topologia pós-unshare entregue e diferença dos baselines externos/PID1. Mantém prova autônoma de real/effective/saved/fs UID/GID, cinco conjuntos de caps zero, groups vazio, NNP1, sysfs/proc somente lo, lo UP/index1, rotas apenas loopback, sockets IP vazios e mount sysfs readonly/private. NÃO lê /proc/1 e não roda como root.

O pipe não é uma credencial nem uma assinatura. Sua proveniência depende do wrapper root revisado e do descriptor root-owned recebido pelo caminho fixo. Um processo root comprometido está fora dessa prova; flags/JSON de usuário ou variáveis de ambiente não substituem o pipe. Nenhum identificador de namespace/PID/UID é impresso.

## Superfície privilegiada mínima para revisão

Comandos root concretos, sem shell expansível:
1. sudo -n timeout (20s, grace2s) executa /usr/bin/python3 -I root_wrapper.py com UID/GID e caminhos das ferramentas oficiais revisadas.
2. Python lê links proc, fstat e cria pipes anônimos; exec /usr/bin/unshare --net --mount --propagation private reentra no mesmo arquivo revisado. Não cria PID namespace.
3. subprocessos fixos e limitados a3s, stdout/stderr descartados: mount --make-rprivate /; ip link set lo up; umount --recursive /sys; mount -t sysfs -o ro,nosuid,nodev,noexec sysfs /sys. Remoção/montagem de sysfs ocorre SOMENTE depois de provar mount namespace privada, sem propagação ao host. Nenhum mount de procfs é criado.
4. exec /usr/bin/setpriv com clear-groups, bounding/inh/ambient caps=-all e no-new-privs; PHP e módulo posix só são executados depois do drop. Não há initdb, PostgreSQL, instalação APT, ptrace, serviço, escrita em /proc, alteração host networking ou grant de capability ao PHP.

Se sudo/root não puder ler PID1, o wrapper falha; não há fallback para /proc/self, parâmetro de caller ou marcador presumido. Isolamento/caminhos/binários ainda precisam ser comprovados por uma execução futura autorizada. O Python isolado -I evita imports/env de usuário na fase root; integridade do arquivo root e do workflow depende da revisão externa de bytes antes da ref, não de nomes de arquivos.

## Workflow e aprovação sem hash circular

Branch exclusiva `test/onepage-briefing-ci-viability-v2`, base `feature/portal-native-onepage-sites-2026-11-18`, pai único no pin #941, mesmo repo, draft, pull_request opened, run_attempt1, árvore limpa, diff de exatamente oito adições100644. Checkout é head do evento, não merge. Actions checkout/setup-php mantêm os SHAs imutáveis usados anteriormente, PHP8.4/posix, contents:read, sem secrets, persist-credentials, serviços ou artifacts.

A coordenação deve conferir externamente SHA-256 do patch/manifesto e bytes, construir/conferir árvore e commit propostos e registrar o HEAD/árvore aprovados ANTES de criar a ref. O SHA do evento não é pin independente. preflight confirma a correspondência operacional do checkout/evento/pai/diff; não substitui a revisão externa de bytes. Nenhum arquivo contém o SHA do próprio commit. Alterações posteriores invalidam aprovação. Outros workflows amplos do repositório ainda podem disparar: revisão pública futura deve considerar esse efeito.

## Saída, prazo e limpeza

O supervisor permanece não-root, fecha descritores herdados e limita captura a4096 bytes. O wrapper root recebe20s+2s de timeout; supervisor30s com tentativas limitadas de reap. Só aceita a linha exata PASS PHP_UID_NAMESPACE_FACTS, exit0 e ausência do seu grupo original. O fluxo confiável é síncrono/exec, sem background intencional nem namespaces nomeadas/persistidas. Uma falha no caminho privilegiado é sempre cleanup=UNVERIFIED, mesmo que o filho direto tenha sido recolhido: não inferir ausência de descendentes root a partir de permissão insuficiente. Não há banco ou scratch de dados para apagar.

Workflow fecha FD3 em todos os subprocessos; o FD9 nasce posteriormente dentro do wrapper apenas como entrada de topologia. O filtro externo imprime só marcadores fixos e remove seu log privado. Sucesso completo: VIABILITY_EVENT_SCOPE → PHP_UID_NAMESPACE_FACTS → NAMESPACE_CHILD_REAPED → VIABILITY_ONLY_NO_DATABASE → VIABILITY_STAGE_ONLY_NO_BRIEFING. Timeout externo não prova limpeza. Saída duplicada, inesperada, excessiva ou status nãozero recusa.

## Evidência realmente executada

Checks offline novos/revisados: 26 predicados PHP com provenance/parent/PID negativos; 12 modelos Python de topologia/proveniência root, incluindo drift pós-unshare e pipe de owner errado/tipo errado; 11 eventos; 6 subprocessos inofensivos para saída/timeout/reap; 1 fechamento de FD herdável; PHP lint e Python AST/sintaxe do shell do workflow. Os testes não chamam snapshot(), collectFacts(), pipe_out(), main() root ou sudo; fstat inválido é simulado nos modelos.

Nenhum teste confirma a capacidade root/PHP real, a semântica de preservação de FD9 por unshare/setpriv, leitura de PID1, sysfs ou teardown privilegiado. Esses são fatos que o único run futuro deve medir e recusar se indisponíveis. SQL/Laravel/cluster/ACL/briefing permanecem fora deste job sem banco; viabilidade não é briefing aprovado. Nenhuma instalação/publicação/CI/PG/VPS/Asaas/credencial real foi usada nesta rodada.
