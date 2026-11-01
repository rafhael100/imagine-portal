# Central financeira do cliente

A aba Pagamentos reúne valores liquidados, cobranças abertas (incluindo vencidas), atraso e confirmação ainda não liquidada. O total pago é histórico nominal por status, não saldo após estornos. Vencimentos pendentes anteriores à data atual de Brasília são tratados como atrasados.

A prioridade é a cobrança vencida mais antiga ou o próximo vencimento aberto. Links de pagamento são aceitos somente no Asaas e apenas quando já cadastrados. Sem link, o cliente pode abrir a conversa do pedido. Não são gerados pagamentos por esta interface.

Histórico com filtros de projeto, tipo, situação, busca e vencimento, paginação de dez linhas e CSV de todos os resultados filtrados. Detalhes dão acesso ao pedido e às notas do projeto.

Assinaturas exibem a situação, primeiro vencimento, valor configurado e próxima cobrança já gerada. Não inventam datas futuras. Novos pedidos usam R$ 79,90/D90 com recorrência incluída na compra; contratos legados mantêm suas condições. A homologação real continua pendente.

Notas são consultadas pela API fiscal autenticada do próprio cliente, com distinção de homologação e produção e links disponibilizados pelo backend. Pagamento e emissão são processos separados; a implantação pode ter uma nota pelo serviço total e três parcelas.

Validação: 49 testes, incluindo classificação financeira, filtros combinados, isolamento fiscal existente e disponibilidade do script estático. Prévia local com dados fictícios; nenhuma cobrança ou nota real criada.


## IN-115 — Planejamento da aba de Orçamentos e pagamentos de serviços extras (APROVADO em 01/11/2026)

A nova **aba Orçamentos** será independente na navegação do Portal Imagine (visão de administração e visão autorizada de cada cliente), mas **não criará um segundo sistema financeiro**: propostas e seus aceites remetem à fonte canônica de cobranças, parcelas, estornos, notas e liquidação da **Central Financeira**. Para serviços adicionais pequenos, **pagamento integral antes da execução**; para projetos maiores, **parcelamento por orçamento específico aceito**, com entrada/marcos/condições explicitados. A distinção objetivo de pequeno e grande, meios e taxas ainda não foram aprovadas. O cliente deverá validar o orçamento no Portal e **autorizar os pagamentos aplicáveis separadamente**, sem emissão automática por simples aceite; status de pago depende de conciliação comprovada. As cobranças extras de sites pertencem ao Portal, não à assinatura paga do CRM Imagine. O aceite de orçamento não substitui o aceite de publicação da versão exata do site (IN-109). A criação da aba e dos fluxos financeiros depende de planejamento integral e posterior programação e homologação autorizadas.

[Documento da Central de Orçamentos](https://github.com/rafhael100/imagine-portal/blob/docs/producao-inteligente-v01/docs/ORCAMENTOS-SERVICOS-ADICIONAIS.md). **Nenhuma cobrança real ou mudança de produção foi realizada**.
