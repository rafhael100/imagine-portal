# Onepage: prova proposta de vínculo durável sintético

Base local `f354edd4132800723d92d5bce1057fc8e9a9186c`, árvore
`b980c3e386777f0f6bd200b8503d4bdf10a72148`, equivalente aos arquivos do #925
validado. A especificação de vetores `a7206055` orienta resultados,
não é importada como autoridade. Nada muda no #925: o runner separado
valida os pins e compõe o gancho somente em diretório temporário.

A execução futura reutiliza a inicialização e atestação de processo, PDO,
configuração Laravel/SQL, cluster e papéis da composição #925. A fixture
só é alcançada após a leitura nativa positiva e o CAS de reserva
`fixture_charge_reservations`. Bootstrap é a única fonte de testemunhos,
derivados do snapshot nativo atestado e do plano, não de uma cópia da
reserva corrente. Não existem comprovantes/API de pagamento aqui. Escopo
congela empresa/pedido/contrato/revisão/digests, data, valor, cliente,
conta e referência; moeda BRL é a invariante fixa do plano Onepage.
O runtime recebe só EXECUTE de capacidade de fixture no cluster efêmero,
sem acesso direto ou novos usuários, papéis permanentes ou grants
operacionais.

O vínculo é append-only com duas chaves únicas: pedido/parcela e
conta/ambiente/ID. A função relê a identidade e a elegibilidade nativas
na mesma transação, segura o `FOR SHARE` nas linhas de autoridade nativa
e na reserva tentada, compara testemunho completo e só então tenta inserir.
Replay exato não altera a linha. Teste proposto: ausente/valor
divergente/escopo errado, comprador do mesmo tenant e de outro tenant,
linha não tentada, rollback sem visibilidade parcial, sobreposição de duas
conexões, replay independente, ID distinto para a mesma linha e mesmo ID
em outra linha, bloqueio concorrente da revogação nativa (lock timeout) e
recusa de link/replay após a revogação original do #925 ser confirmada.
Nenhuma baixa, POST, liberação Sites, rota, worker ou migration.

`run.py --check` valida composição/pins e sintaxe PHP e shell sem banco.
O futuro `--run` exige mesmas variáveis limpas, opt-in, PHP 8.4/PDO e PG18 da
execução #925, mais autorização explícita para este teste descartável.
SQL/ACL/RLS, locks concorrentes e cleanup não foram executados neste
checkpoint. Nenhum workflow, instrução de implantação ou credencial.
