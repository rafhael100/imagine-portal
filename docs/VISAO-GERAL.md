# Visão geral da administração

Resumo exclusivo do administrador completo. Colaboradores mantêm a visão de trabalho permitida pela sua função. API /api/admin/overview usa o banco completo e o fuso America/Sao_Paulo.

Alertas atuais: tarefas atrasadas, vencendo hoje, bloqueadas e sem responsável ativo; pedidos cuja última mensagem foi enviada pelo cliente; reclamações explicitamente abertas; materiais enviados para análise; prévias em revisão; sites aprovados para publicação; previsão inicial de produção vencendo hoje; novos pedidos do dia; eventos financeiros pendentes.

Recebido hoje soma apenas RECEIVED e RECEIVED_IN_CASH pela data de pagamento. Confirmados não entram nesse valor. Líquido é informado, não estimado; faltantes são sinalizados. A receber hoje inclui cobranças com vencimento hoje ainda PENDING, OVERDUE ou CONFIRMED. Total vencido considera toda a carteira atual. Gráfico cobre sete dias corridos e mostra zeros quando não há recebimentos. Registros sem data ficam fora dos indicadores diários.

Fila de atenção prioriza reclamação alta, tarefa atrasada, demais reclamações e mensagens. Um pedido pode aparecer por mais de um motivo. Próximas decisões mostram publicações, conferência de materiais e revisões pendentes. Marcos do dia incluem pagamentos, pedidos novos, reclamações e transições de produção registradas. Listas resumidas têm limite de 10/12 itens; os atalhos levam às listas operacionais completas.

Atualização automática a cada minuto enquanto a aba Visão geral estiver ativa, sem chamadas adicionais ao Asaas. A troca de seção cancela o agendamento. O botão Atualizar agora consulta imediatamente o banco. Os dados refletem a última sincronização disponível; não são saldo bancário, lucro ou evidência automática de descumprimento de prazo. Na demonstração, os registros continuam fictícios.
