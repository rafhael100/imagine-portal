# Onepage: contrato de preparação de vínculo (fixture isolada)

Base Portal #924, `ed9333f0b7fe8f2c1207ef13ffb4302755e2ef6c`.
A entrada usa a forma do `AtomicReader`: pedido próprio pendente, atomicamente
aceito no laboratório, sem binding nem baixa, política ainda `undecided`.
O teste monta só um envelope sintético; não chama o leitor SQL nem comprova
proveniência. `offer_digest` e `terms_digest` são os digests do JSONB nativo:
este consumidor guarda o valor de origem, não reserializa PHP como se fosse
o mesmo preimage. O texto do contrato é conferido pelos bytes UTF-8.

A partir do catálogo e de `BillingSchedule`, confere a cotação Onepage e
projeta só as três linhas de implantação D0/D30/D60 com a data civil de São
Paulo (sem D+90, assinatura, mídia ou agrupamento Asaas). As três referências
são geradas internamente, estáveis e distintas, usando namespace de fixture e
digest de escopo nativo opaco + contrato/revisão/oferta + linha/valor/data +
conta/ambiente. Nenhum comprador, nome, e-mail ou ID de cliente compõe a
referência. Referência não é autorização, segredo ou garantia de idempotência
do provedor.

A montagem exige três recibos sintéticos de criação individual, da mesma
conta/ambiente/cliente, com IDs de cobrança e referências exatas e distintas,
centavos e vencimentos exatos. Preserva as linhas na ordem do contrato,
independente da ordem dos recibos. Resultado incompleto, grupo, assinatura,
dados conflitantes ou ID não sintético recusam o lote inteiro. Não julga
`status`, não dá baixa e não cancela cobranças. O parser exige decimal
preservado ou inteiro; não confunde o decoder atual do gateway com uma
projeção sem perda. Referências e IDs históricos genéricos nunca entram.

O gateway legado do #924 já tem criação de uma cobrança individual, mas este
ensaio não o instancia e não chama HTTP. #901/#916 são leitores de baixa/vínculo
integral, não produtores de um lote de três itens. O avaliador local 60db48be
reutiliza o mesmo catálogo e calendário; nenhum dos dois chama o outro.
Faltam: produtor autorizado obter o envelope nativo e o cliente correto,
persistir escopo sob controle de concorrência **antes** de criar, projetar
e verificar recibos de origem, reconciliar falhas parciais pela referência
antes de repetir e garantir vínculo/ID únicos no armazenamento. Nenhuma dessas
provas foi feita aqui. Não infere ID tenant Sites do UUID de empresa Portal.

Verificação offline: `php apps/portal/labs/onepage-binding-producer/test.php`.
