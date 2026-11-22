# OnePage — recibos de confirmação e decisão versionados no Portal Node legado (candidata)

## Limite de autoridade

Este recorte é **apenas uma candidata de código no Portal Node/SQLite**, filha de #1089. Não implementa o leitor oficial do Portal Laravel #976, não exporta nenhuma revisão ao Sites #552/#1003, não autoriza produção e não representa homologação. O contrato Portal #976 **permanece fail-closed**. Não existe mapeamento comprovado da identidade corporativa, do pedido pago, de uma autorização operacional de site ou de um agente OnePage.

## Vínculos efetivamente persistidos

- A confirmação explícita `confirmed:true` do comprador titular, após a validação do briefing já existente, cria uma linha append-only por `(order_id, briefing_version)`, com `buyer_id`, `rights_confirmed=1`, os bytes UTF-8 **do JSON armazenado** (não os bytes originais da requisição) e SHA-256.
- A lista ordenada de anexos contém ID, tipo, MIME, tamanho, nome interno e **SHA-256 obtido da leitura dos bytes do arquivo** no envio. A revisão lê os bytes novamente e nega divergência, mesmo se o tamanho permanecer igual. O digest não é arquivo arquivado: uma fonte oficial ainda precisará preservar os bytes históricos e comprovar o armazenamento imutável, inclusive após revisão ou exclusão.
- A decisão do revisor autenticado com papel `admin` grava recibo append-only de `approved` ou `revision_requested`, ligado à mesma versão, SHA, pedido e ID do revisor. Antes de decidir, o serviço verifica o recibo do comprador, os dados atuais, o SHA e o manifesto de metadados; divergência nega com 409.
- O recibo é gravado na **mesma transação SQLite** que altera a versão/estado da respectiva operação. Em erro, a transação é revertida. Triggers recusam UPDATE e DELETE dos recibos.
- Após pedido de ajustes e novo envio, há confirmação própria e revisão própria; a versão antiga não pode ser aprovada como atual.

## Limitações e gates

1. **Não migrar nem promover automaticamente.** Migração `schema_version=4` é aditiva, mas a mudança de revisão nega historicamente submissões sem recibos: exige plano de compatibilidade e revisão antes de qualquer operação em base existente. Nenhuma base real foi alterada nesta entrega.
2. Atestado de bytes de anexos, origem oficial e imutabilidade do briefing Laravel/PostgreSQL, revisor autorizado corporativo, revogação operacional, CAS/epoch independente e leitor S2S continuam faltando. Não derivar autoridade de fixture, audit log genérico, hash isolado ou painel Node.
3. O SHA-256 de anexos é calculado em leitura de arquivo antes da transação SQLite e revalidado pela rota de revisão, sob o bloqueio por pedido da instância Node. Isso **não** é uma transação distribuída nem trava entre processos/servidores; mudanças fora desse fluxo não estão cobertas.
4. O aceite final do site pelo comprador, a autorização de publicação, o domínio e a entrega são etapas separadas. Este recorte não altera produção nem publicação e não transfere nenhuma prova para Landing Page.
5. Não há chamada OpenAI. O worker OnePage segue bloqueado até confirmação sanitizada de identidade, escopo, modelo, credencial específica e egress privado autorizado.
6. Sites #38 permanece NO-GO; sem merges, deploys, VPS, DNS/Cloudflare, dados reais, pagamentos, checkout ou credenciais.

## Evidência esperada

Teste HTTP com pedido e identidades fictícias: comprador correto confirma versão v1; outro comprador recebe 404; adulteração de bytes mantendo versão recusa revisão; revisão v1 para ajustes; novo envio v2 exige novo recibo e revisão separada; versão v1 obsoleta recusa; triggers impedem alteração/exclusão de histórico; alteração de um anexo mantendo tamanho/metadata recusa aprovação até restaurar os bytes; CI Node 22/24 deve ser validada **na SHA da candidata** antes de marcar PASS. Não confundir testes Node com autenticação corporativa Laravel/Sites ou preview final.
