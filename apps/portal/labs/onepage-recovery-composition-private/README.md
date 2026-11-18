# Recuperação Onepage — candidato privado para revisão

Não publicado, sem CI, PostgreSQL ou PSP executados nesta preparação. Não é
confirmação financeira, liberação de briefing, recorrência ou autorização de
produção. O próximo passo é revisão independente deste diff.

## Proveniência e reconstrução

Base nominal exata: #939 `4464e3220e077118f05928ee20a48f33ea6440ec`.
O workspace restaurado perdeu o checkout/patch anterior e a gravação de
`preflight.py`; este pacote foi reconstruído do histórico desta tarefa e dos
candidatos privados preservados. Os hashes anteriores não identificam esta versão.

Checkout local separado: `d23cd74103fb204112b046ca0047c9acb438c344`, derivado de
`onepage-native-kernel-private`. **Não é checkout nem equivalência integral de
#939.** `pins.json` confere 71 blobs pertinentes com a árvore oficial completa
(não truncada) de #939: preparação, autoridade, contratos e dependências diretas.
Outros laboratórios/workflows não foram importados ou modificados. O patch contém
somente adições e é destinado à base nominal; a admissão real no CI exige essa
base, não permite executar sobre este checkout local diferente.

Reuso literal dos candidatos:

- `Recovery.php`: `2e5ef83d5f28e79a022819713597bf9991a8660a`.
- `first-attempt.php`: `58c9d4a76b247208ab0d99a2966efa0b7f8f7e2b`.
- `attempt-read.sql.inc`: trecho literal de `reserve.sql` do segundo candidato.
  A função de leitura inteira falta em #939, não somente o campo `tuple_xid`.
- `fixture_claim_charge`, `fixture_bind_charge`, `fixture_read_bound_charge`:
  fontes verificadas da base, compostas apenas no SQL temporário. `bound_at`
  segue a composição anterior e não representa quitação.

Workflow derivado dos passos de #939 nesse pin, blob oficial
`ec5ff5e8dc6f1de90b742a55ee6e261265cbe1d2`. Ações por SHA, PHP8.4,
Composer2.8.12, verificação da chave PGDG, APT autenticado em diretórios privados,
extração PG18 sem instalação de pacotes e namespace somente loopback preservados.
O workflow original não é alterado. Nenhum tooling foi instalado agora.

## Novo ensaio, sem repetir as suítes verdes

A preparação mínima autentica o comprador e aceita um pedido nativo sintético.
Substitui-se somente o hook das antigas asserções por esta composição. Reserva
atômica das três linhas existentes; apenas a entrada é usada. Sem envio de
outras parcelas ou implementação da manutenção D+90.

- Abort antes do commit não envia. Após commit, transporte totalmente falso
  armazena cobrança fictícia e perde a resposta; contador permanece em um envio.
- Processo PHP novo relê a reserva e eventual vínculo em conexões novas,
  atesta Laravel/SQL/processo/roles e executa `Recovery::inspect` com listagem
  e consulta individual simuladas. Não carrega `SandboxHttpReads` nem usa rede.
  O snapshot falso do provedor passa em pipe privado; não é PSP real nem falha
  de rede efetivamente reproduzida.
- Ausência/ambiguidade não geram candidato ou vínculo. Bootstrap de teste
  transforma o candidato verificado em testemunha sintética. A função SQL
  existente é o único escritor do vínculo e revalida o contexto na transação;
  comprador não fornece JSON, ID ou autoridade financeira.
- Antes do commit e após rollback: vínculo invisível. Após commit: conexão
  nova observa o ID exato, replay é estável, comprador vizinho é recusado.
  Divergência de ID é recusada na recuperação e no escritor sem trocar o vínculo.
- Pedido permanece exatamente pending_payment, settled=false,
  preview_admitted=false. Nenhuma decisão financeira, confirmação, next step
  ou rota de briefing é instalada. Não prova briefing operacional.

As provas de unicidade/ACL mais amplas existentes não são repetidas; o novo
ensaio verifica ausência de privilégios diretos de escrita e os efeitos da
composição. As provas SQL aqui descritas ainda dependem de execução.

## Deadlines e limpeza

`ChildProcess.php`: I/O não bloqueante, entrada e stdout até 64 KiB, stderr até
1 KiB, deadline 15 s, TERM e grace de 250 ms, KILL apenas do filho direto ainda
não recolhido, seguido de proc_close. Nenhum kill de grupo. Erros são fixos;
exceções, SQL, PDO, payloads e credenciais não são impressos.

`bounded.py`: envolve somente cada chamada PHP da cópia temporária do worker,
com 120 s, saída até 1 MiB e TERM/KILL/recolhimento do próprio filho. O supervisor
do lançador usa 300 s e grace de limpeza de 180 s; envia TERM somente ao shell
próprio, cuja cópia temporária mantém EXIT cleanup e acrescenta TERM/INT -> exit.
**Nunca SIGKILL no shell de limpeza ou grupo do PostgreSQL.** Timeout/overflow
sempre recusam o ensaio. Exceder a grace sem término deixa cleanup não verificado;
o job de 20 minutos é último recurso externo, nunca prova de teardown.
Uninterruptible kernel I/O também não recebe promessa de prazo ou limpeza.

A verificação de sucesso exige todos os marcadores fixos, únicos e ordenados,
exit zero e `PASS ORDER_CLUSTER_STOPPED`. Falha não publica sucesso parcial.
Os scripts do workflow exibem somente PASS/REFUSED fixos e apagam seus logs
privados; as ações checkout/setup-php mantêm seus logs normais de ferramentas.
Nenhum artifact com log bruto, dump, input ou segredo é publicado.

## Critérios do futuro draft experimental

Somente após autorização de publicação separada:

- repo `rafhael100/imagine-portal`, draft, evento `pull_request: opened`;
- base `test/onepage-confirmation-pg-lab` no SHA nominal acima;
- filha exclusiva `test/onepage-recovery-loss-composition-v1`;
- exatamente um commit com único pai igual à base, checkout do head do evento;
- exatamente 15 adições: 14 arquivos desta pasta e
  `.github/workflows/onepage-recovery-loss-pg-lab.yml`, modo 100644, árvore limpa;
- gate antes do setup/provisioning, permissions somente contents:read,
  persist-credentials:false, nenhum segredo ou pull_request_target;
- dependências travadas sem scripts/plugins; checks Python, lint de todo PHP
  pertinente e child-check PHP antes de iniciar banco;
- ambiente estéril, namespace sem interface externa/rota default, drop de UID
  e grupos; sem fallback caso namespace seja recusado;
- preflights herdados de configuração efetiva Laravel e identidade SQL em
  loopback antes de qualquer migration; nenhum host ou role alternativo.

Isto não impede os workflows amplos de push/pull_request já existentes no repo.
Não abrir PR, dar push, disparar CI ou alterar main como consequência deste pacote.

## Resultados reais nesta preparação

Executados localmente, sem banco/rede: `python3 -B check.py` (7 testes),
`python3 -B bounded-check.py` (5 testes), `python3 -B run.py --check`
(pins/composição/bash -n), AST Python, YAML/estrutura do workflow e bash -n
nos quatro blocos shell, diff --check e aplicação do patch --check.
Os testes do gate usam eventos e um repositório Git temporário fictícios;
o teste de cleanup observa um arquivo escrito pelo EXIT trap de um shell
fictício e o recolhimento desse shell. Isso não prova limpeza PostgreSQL.

Não executados: PHP lint, child-check PHP, subprocesso PHP da recuperação,
PostgreSQL/ACL/transações/visibilidade, provisioning, namespace real, Composer,
workflow/CI, Asaas ou VPS. PHP e PostgreSQL não estão no PATH; nada instalado.
O runner recusa --run sem PHP e exige esses checks antes de iniciar banco.
O comando --check sem PHP informa NOT_RUN, nunca sucesso PHP.

Próximo passo mínimo: revisão independente do pacote final. Depois, se houver
aprovação pública específica para estes 15 arquivos, aplicar em checkout exato
#939, conferir base/pai/diff e abrir um único novo draft experimental. Esta
entrega não executa esse passo e não avança para recorrência ou novo gate.
