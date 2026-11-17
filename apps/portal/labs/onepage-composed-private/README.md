# Ensaio privado composto, Onepage

Base local `c451ca52b7efd5333b0ed0f19a1ce04e8163b15b`, árvore #926
`56a89c0424e5ff2f365e8b180a1a5b9b7b29ae75`. Não altera o runner, os
scripts, as migrations, as rotas ou as autoridades de #926/#925/#923.
Compõe temporariamente o runner pinado e exige o marcador Comercial
além dos marcadores #926 e encerramento. Sem workflow novo.

| Fronteira | Estado neste pacote |
|---|---|
| Comercial→MFA→leitura própria #923 | Gancho executável proposto imediatamente depois de `$review=$orders->review($own,$quote)` e de seu guard, antes do `accept`. Usa `CommercialSelectionIntent`, `OwnReview` e `pg-increment.php` dos bytes de `133afe06` (descendente de `20635ec2`). As requisições são do próprio comprador e de outro comprador **com sessão/MFA nativa válida da mesma empresa**, com binding local apto. Compara os digests da mesma cotação e verifica que nenhuma linha desse pedido existe nem muda durante a leitura. A consulta bootstrap apenas compara snapshots/digests da fixture, nunca fornece ID de cobrança. |
| Cotação → pedido → reserva/vínculo | O mesmo `$quote` e o `$order['id']` seguem nas fixtures originais do #926. Reserva/CAS e vínculo/transação/replay/revogação não são duplicados. O #926 continua sintético. |
| Vínculo → observação → condição financeira | Arquivos de observação `c198c054`, avaliador/calendarização `60db48be` e projeção `11677f7e` copiados sem alterações, pinados e disponíveis para lint; **não executados nem plugados** por este runner. Nenhum código fabrica um testemunho financeiro ou reutiliza o acesso privilegiado da fixture #926 como fonte do ID. |

**Próximo leitor restrito:** uma capacidade descartável execute-only
`read_bound_charge_line(session_hash, order_uuid, setup_line)`, separada
do app, deve revalidar a mesma sessão/empresa/compra nativas na própria
transação e retornar apenas a linha já persistida do par pedido/parcela,
com chave/escopo congelados e ID efetivamente vinculado. Confrontar a
reserva tentada, contrato/revisão/oferta, conta/ambiente/cliente,
referência, moeda/valor/vencimento e negar sem vínculo, para outro comprador,
empresa ou após revogação. Sem SELECT amplo, UPDATE, baixa ou autorização.
O #926 não expõe leitura de ID ao runtime e não grava `bound_at`; observar
um instante de vínculo confiável em fixture aditiva é necessário antes de
consumir a exigência de horário de `c198c054`. Não fabricar timestamp de
COMMIT a partir de GET, data civil, tempo atual ou payload sintético.
Depois disso, para o teste comum, ainda se exigem as evidências sintéticas
independentes da entrada e do estado em aberto das demais parcelas no
mesmo pedido, sem tratar a saída como liquidação/autoridade.

`--check` somente verifica SHA-256, composição e sintaxe em temporário
privado. O `--run` futuro depende de autorização e da mesma preflight
efetiva PHP/PDO+PG18 descartável, sem rede de negócio. Nenhuma execução
PG, SQL, API, workflow ou validação financeira ocorreu nesta rodada.

### Extensão de leitura após vínculo

Este derivado privado substitui o checkpoint financeiro ainda não ligado
descrito acima. A composição em temporário deriva a fixture SQL #926:
`bound_at` é registrado pelo banco **no INSERT da mesma transação**,
não representa o instante exato de COMMIT. Uma conexão runtime distinta
prova visibilidade apenas após COMMIT; após replay, ID/instante e latch não
mudam. Bootstrap pode consultar catálogo e contagens do ensaio, mas não
carrega o ID para a observação.

`fixture_read_bound_charge(session_hash,order_uuid,setup_line)` é
execute-only no laboratório. Reusa a autoridade nativa dentro da transação,
confere mesma reserva tentada e escopo congelado, junta conta/ambiente:
não recebe ID, conta ou testemunho do chamador, não é endpoint. Ausência,
outra linha, comprador diverso da mesma empresa, empresa distinta,
revogação e leitura em outro backend antes de COMMIT devem ser recusadas.
O teste inclui ACL, replay, rollback/conflito herdados, e divergência
sintética de conta na projeção.

Depois do COMMIT a projeção fixa o **ID e timestamp de vínculo lidos** em
runtime. As observações continuam integralmente testadas em memória:
`paid_at`, `read_at` e `as_of` são tempos de fixture ordenados de propósito
(vínculo +1s, +2s, +3s). Isso satisfaz o contrato sintético sem atribuir
horário ou causalidade ao PSP. As outras parcelas recebem estados em aberto
explicitamente sintéticos, nunca inferidos da ausência de vínculo. A única
saída é um marcador sanitizado, não ID/digest exposto, decisão persistida,
baixa, evento, outbox, aprovação ou prévia Sites.

Somente composição, pins e lints foram executados. SQL, ACL, isolamento,
visibilidade, revogação e cálculo encadeado ainda exigem CI descartável
explicitamente aprovado. O workflow original #926 e suas fontes não mudam.

### Gravação durável de projeção sintética (sem autoridade)

O mesmo runner propõe um testemunho controlado e um ledger append-only para a fixture: o
testemunho é semeado só por bootstrap com **o cálculo sintético já efetuado**
e o ID/instante já lidos pelo runtime (bootstrap não os lê do ledger);
a função privada runtime recebe só sessão e pedido. Não confere origem
real do testemunho e não permite que a própria requisição selecione ID,
digest, versão ou decisão. A testemunha expira em 60 s pelo relógio SQL
de semeadura, prazo **só da fixture**, não prazo de política financeira.
Vazio, ambiguidade, expiração, referência ou escopo divergente não gravam.

Na mesma transação, o writer volta a obter a autoridade de pedido nativa,
releitura execute-only do vínculo da entrada e as três linhas realmente
reservadas (referências, valores e datas; sem mover o latch). Só registra
a projeção fixture do mesmo pedido com versão, instante de avaliação e
digest. Novos efeitos são impedidos no replay e chaves concorrentes
impedem sobrescrita; o ensaio propõe verificar rollback/visibilidade,
correspondência exata após COMMIT, replay imutável e revogação. Também
confere ACL/SECURITY DEFINER, FORCE RLS e recusa para outro comprador da
mesma empresa e para outra empresa, mesmo com testemunho válido. Não
executa corrida de dois snapshots. A SQL só checa formato do digest e
escopo, não refaz a prova criptográfica do cálculo PHP. O login
runtime não lê as tabelas diretamente. READ COMMITTED e autocommit não
preservam autoridade para qualquer etapa futura: o lock da identidade/
membership/empresa termina no COMMIT; uma emissão posterior teria de
atestar e revalidar de novo. Nenhum cancelamento concorrente é provado.

O menor delta posterior para Sites, **não implementado**, é levar a prova
nativa versionada ao emissor e exigir confirmação independente de empresa/
decisão/estado atual no receiver; reusar nonce/admissão HTTP do #462, e
no ponto de materialização privada provar o mesmo ID/hash antes do ACK
durável em nova entrega. #488 comprova restore isolado de namespace, não
essa passagem, nem a restauração de dados dev atuais. Não repetir mocks
de replay. Este ensaio não cria baixa, autoridade, liberação, evento,
outbox real ou conexão Sites.

Pendente para um único futuro CI aprovado: executor PHP 8.4/PDO e
PG18 efêmero, guard/preflight originais; executar este `run.py --run` com
ambiente scrub e opt-in original, comprovar marcadores prévios, gravação
sintética, conflitos/replay/revogação e parada do cluster. Nada disso foi
executado aqui. Nenhum workflow novo faz parte deste delta.
