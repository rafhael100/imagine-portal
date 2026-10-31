# BI da Imagine

Área restrita ao administrador completo. Colaboradores Atendimento, Produção e Financeiro não têm acesso ao endpoint de BI, mesmo por URL direta. O banco completo é consultado; os indicadores não usam a lista operacional limitada a 500 pedidos.

- Filtros de datas e serviço, até três anos por consulta.
- Recebido bruto: somente RECEIVED e RECEIVED_IN_CASH, pela data de pagamento.
- Líquido e taxas: apenas valores informados; ausentes são sinalizados. Não são lucro.
- Confirmado, pendente e vencido: situação atual das cobranças com vencimento no período.
- Contratado: valores dos pedidos com aceitação registrada no período; pedidos ainda sem aceitação entram apenas na contagem de pedidos pela data de criação.
- Variação do recebido: período imediatamente anterior, com o mesmo número de dias. Sem base anterior, não exibe uma porcentagem.
- Gráfico mensal de recebimentos separados entre projetos e assinatura, receita por serviço e principais clientes.
- Produção e atividades: posição atual, mesmo quando o filtro histórico está selecionado. Conclusões de tarefas no período usam a última atualização; não há medição de horas ou produtividade ponderada por complexidade.
- Recorrência estimada: última cobrança conhecida de cada assinatura ativa. Sem valor de todas as assinaturas, não estima o total.
- Previsão 30/60/90 dias: cumulativa, somente cobranças pendentes ou confirmadas já cadastradas com vencimento futuro; exclui atrasadas. Aplica serviço, não período histórico.

Não há fonte de leads, despesas, salários, custo por projeto ou histórico de churn suficientes para CAC, LTV, margem ou lucro. Esses indicadores não são inventados. A tela mostra a última informação registrada no portal; não força sincronização com Asaas. No ambiente local, os dados demonstrativos continuam fictícios.

O prazo inicial vencido é um alerta de conferência, não uma conclusão automática de atraso contratual: feriados, alterações de escopo e acordos devem ser avaliados no pedido.
