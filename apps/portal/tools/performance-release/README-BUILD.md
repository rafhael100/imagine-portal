# Recibo das três imagens locais — Phase 13

`build_receipt.py` registra a associação observada entre os metadados das três
builds já existentes, as imagens utilizadas pelos containers do ensaio e as
fontes observadas antes e depois dessas builds. O resultado é um JSON pequeno,
com campos permitidos explicitamente. Ele complementa `sources.json` e o
preflight; não muda as condições de aprovação do preflight.

O recibo não executa builds, inicia containers, exporta imagens, publica em
registry ou se conecta a um VPS. Os comandos Docker que ele executa são
consultas ao contexto local, ao projeto Compose existente e a projeções mínimas
dos três containers e de suas imagens.

## Contrato com o ensaio existente

O fixture exige `GITHUB_ACTIONS=true`, `ADMIN_PHASE7_CI=1`,
`ADMIN_PHASE13_BUILD_CI=1`, identificadores numéricos de run e tentativa e o
projeto `imagine-performance-p7-ci-$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT`.
`GITHUB_WORKSPACE` é a raiz canônica do Portal; o checkout do engine fica em
`$GITHUB_WORKSPACE/engine`.

A revisão esperada do Portal é `GITHUB_SHA`. O engine permanece preso ao commit
`15aad7bee3ac2dbade72c5fab6808d8e3044ac59`. As três referências locais esperadas
são `p7-portal-ci`, `p7-web-ci` e `p7-receiver-ci`, também conferidas contra as
variáveis `P6_PORTAL_IMAGE`, `P6_PORTAL_WEB_IMAGE` e `P6_RECEIVER_IMAGE`.

Antes das builds, após definir essas variáveis:

```bash
export P13_PRIVATE="$RUNNER_TEMP/phase13-build-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"
bash apps/portal/tests/Fixtures/phase13-build/run.sh capture
```

`capture` executa os testes offline, cria um diretório novo com modo `0700` e
grava `source-inputs.json` com modo `0600`, sem sobrescrever arquivo existente.
O comando recusa fontes rastreadas modificadas e arquivos não ignorados fora do
controle do Git nos escopos já definidos pelo preflight. Também confere arquivos
críticos, HEAD e árvores Git. Nenhum metadado de build existe nessa etapa.

As três invocações `docker build` existentes recebem os seguintes argumentos:

| Serviço | Argumento adicional |
| --- | --- |
| `portal` | `--metadata-file "$P13_PRIVATE/portal.metadata.json"` |
| `portal-web` | `--metadata-file "$P13_PRIVATE/portal-web.metadata.json"` |
| `receiver` | `--metadata-file "$P13_PRIVATE/receiver.metadata.json"` |

Depois das verificações de runtime, enquanto os containers continuam presentes:

```bash
bash apps/portal/tests/Fixtures/phase13-build/run.sh verify
```

O único arquivo destinado ao artifact é:

```text
$RUNNER_TEMP/phase13-build-evidence/checks.json
```

O trap de limpeza do Phase 7 também chama:

```bash
bash apps/portal/tests/Fixtures/phase13-build/run.sh cleanup
```

A limpeza remove somente os quatro nomes privados conhecidos e depois tenta
remover o diretório vazio. Arquivos adicionais e links simbólicos não são removidos. Isso
também cobre a interrupção entre `capture` e `verify`. O diretório de evidência
não faz parte dessa limpeza. Metadados brutos e imagens não integram o artifact.
O trap global só deve acionar essa limpeza após um `capture` próprio ter
terminado com sucesso: se o `mkdir` recusar um diretório preexistente, esse
diretório não pertence à execução atual e deve ser preservado.

## O que o recibo confere

O resultado só recebe `LOCAL_BUILD_ASSOCIATION_OBSERVED` se todas as três
associações passarem. O programa reobserva os commits esperados, as árvores Git
e os hashes do conjunto crítico usado pelo preflight, conferindo-os com a
captura feita antes das builds. O JSON público inclui os commits, árvores,
hash de cada Dockerfile, hash do Compose, hash do `.dockerignore`, quantidade
de arquivos críticos e hash canônico do mapa de hashes desses arquivos.

Para cada serviço, o programa consulta `docker compose ps -q`, exige um único
container e observa apenas seu ID de imagem, referência configurada e os labels
`com.docker.compose.project` e `com.docker.compose.service`. Os dois labels e a
referência devem corresponder ao projeto e serviço esperados. Uma consulta à
imagem por seu ID confirma a identidade local, sem exportar labels, variáveis
de ambiente ou a configuração completa.

O campo obrigatório `containerimage.config.digest` dos metadados deve coincidir
com o ID da imagem usada pelo container. Esse é o digest da configuração da
imagem. O campo opcional `containerimage.digest` é registrado com o nome neutro
`reported_image_digest`. `reported_image_digest_matches_config` registra se ele
coincide com o digest da configuração, ou fica `null` quando esse campo não foi
observado. Essa igualdade não é obrigatória nem estabelece o tipo do objeto.

O exporter observado no ensaio retornou esse valor igual ao config digest nas
três builds, sem descriptor. Por isso o recibo não classifica o valor como
manifest ou índice com base na chave: `reported_image_digest_type` permanece
`not_established`. O programa não lê nem valida os bytes desse objeto.

O digest do descriptor também é observado separadamente, quando presente. Se
ele e `containerimage.digest` estão presentes, devem concordar entre si. A
annotation `config.digest`, quando presente, deve concordar com o digest da
configuração. Essas verificações não autenticam um manifest remoto nem
identificam uma publicação em registry; `registry_manifest_verified` continua
`false`. As chaves de origem estão documentadas em
[`--metadata-file` no Docker Buildx](https://docs.docker.com/reference/cli/docker/buildx/build/#write-build-result-metadata-to-a-file---metadata-file).

## Materiais e saneamento

O parser lê apenas as projeções `materials` do formato SLSA 0.2 e
`buildDefinition.resolvedDependencies` do formato SLSA v1. Ele não é um validador
completo dessas especificações. Para as bases esperadas de cada Dockerfile,
somente nomes da lista `php`, `composer`, `nginx` e digests SHA-256 válidos podem
aparecer no resultado. Tags, URI original, plataforma, argumentos, caminhos,
referências do builder, avisos e campos adicionais não são copiados. Materiais
fora dessa lista só aumentam uma contagem.

Provenance ou materiais ausentes ficam como `absent`; um formato de objeto
desconhecido fica como `unrecognized`. Uma base sem digest fica com lista vazia
e `digest_observed=false`. Digests de bases não são deduzidos de tags mutáveis.
O nome observado de uma base não prova sozinho que todos os materiais usados
na build foram capturados. `material_set_complete_verified` permanece `false`,
mesmo quando as bases esperadas têm digests. O modo e a quantidade de dados
disponíveis dependem do Buildx; a documentação de
[provenance do Docker](https://docs.docker.com/build/metadata/attestations/slsa-provenance/)
descreve os formatos e os campos de cada modo.

Cada metadado bruto é limitado a 1 MiB. Objetos com chaves duplicadas, números
JSON não finitos, links simbólicos, arquivos alterados durante a leitura e estruturas
inválidas nos campos observados são recusados. O resultado inclui o SHA-256 dos
bytes de cada arquivo bruto, sem incluí-los. Erros emitem somente status e um
código fixo; argumentos e mensagens de erro de subprocessos não são copiados.

## Limites das afirmações

O vínculo é uma observação do mesmo ensaio de CI confiável. O diretório privado
novo impede reutilização acidental de metadados de outra execução. Os arquivos
de entrada e de BuildKit não são assinados; alguém com controle do runner pode
modificá-los. O recibo não valida uma attestation externa, não prova reprodução
bit a bit e não prova correspondência entre cada arquivo fonte e os bytes
embutidos nas camadas da imagem.

Uma árvore Git sem alterações aparentes não descreve todo o contexto Docker:
arquivos ignorados pelo Git podem existir no checkout. O `.dockerignore` exclui
`auth.json`, arquivos de ambiente e chaves também em subdiretórios, além dos
dados de `storage/app`. A prova focal descrita abaixo não constitui uma
auditoria de todos os arquivos ignorados nem do conteúdo integral de cada
contexto. Por isso `build_context_content_verified` e
`embedded_source_content_verified` permanecem `false`.

Também permanecem `false`: `build_provenance_verified`,
`authenticated_provenance_verified`, `human_approval_verified`,
`registry_release_published` e `deployment_authorized`. A captura de novas
evidências não concede essas aprovações nem altera a política do preflight.

## Prova focal do contexto

O helper CI `tests/Fixtures/phase13-build/context-canaries.py` recebe uma ação
`prepare`, `verify` ou `cleanup`. O Phase7 o executa depois da captura de fontes:
cria arquivos sintéticos exclusivos antes das três builds existentes, verifica
o container Portal já iniciado e limpa os arquivos antes do recibo e do
preflight. Não cria outra build ou container para essa prova.

As canaries negativas cobrem `auth.json` na raiz e em subdiretório, variantes de
`.env`, arquivos PEM/key/P12/PFX/htpasswd aninhados e um arquivo em `storage/app`.
Um marker público positivo confirma que o diretório de controle entrou na
imagem. A leitura ocorre como UID/GID33; pais inacessíveis, links e mounts que
mascarem os caminhos de imagem são recusados. `storage/app` é observado pela
vista do volume em runtime, portanto `storage_image_layers_verified` permanece
`false`. Nenhuma camada completa de imagem é exportada ou inspecionada.

A criação recusa caminhos preexistentes e reverte apenas suas próprias criações
se falhar. O registro de propriedade fica em um diretório novo e privado fora
do checkout; a limpeza confere identidade, modos e conteúdo sintético antes de
remover cada arquivo próprio. Não há remoção recursiva de arquivos desconhecidos.
O trap global só chama essa limpeza após um `prepare` próprio bem-sucedido.

O resultado público fica em `$P7_EVIDENCE/build-context-checks.json`, junto das
outras evidências Phase7. Registra contagens, exclusões observadas, imagem usada
e hash do `.dockerignore`; não contém os metadados brutos de build nem materiais
reais de acesso. O estado privado da canary não integra o artifact.

## Verificação offline

```bash
python3 apps/portal/tests/Fixtures/phase13-build/test_build_receipt.py
python3 apps/portal/tests/Fixtures/phase13-build/test_context_canaries.py
bash -n apps/portal/tests/Fixtures/phase13-build/run.sh
```

Os testes injetam apenas leituras de Git e Docker. Cobrem trocas de metadata
entre serviços, divergência de digests e labels, múltiplos containers, fontes
alteradas, materiais ausentes ou desconhecidos, contexto remoto, limites de
leitura e ausência de um canary privado no JSON emitido. Eles não substituem a
execução do fixture nos três builds e containers reais do CI.
