# Pedidos e reclamações

A administração usa a lista completa do banco (sem o limite de 500 da lista antiga), paginada em 25 linhas. Produção só acessa pedidos com tarefas atribuídas; Atendimento não recebe situação financeira; Financeiro consulta seus dados financeiros e não trata reclamações.

Filtros: texto (incluindo assunto das reclamações), serviço, jornada, responsável por tarefa aberta, sinal de atenção, pagamento e data de criação. A ordenação por atenção prioriza reclamações de alta prioridade, demais reclamações, prazo inicial vencido, bloqueios, mensagens pendentes e atividades atrasadas. Os botões da jornada indicam todos os pedidos acessíveis; os indicadores e a tabela refletem os filtros.

A evolução representa marcos registrados, não dias decorridos. O prazo inicial vencido considera a previsão de produção original e só sinaliza preparação/produção; prazos de ajustes precisam ser alinhados no pedido. Responsáveis exibidos são os de atividades abertas, não um gerente permanente do projeto. A próxima ação é uma sugestão de triagem com regras transparentes, sem executar o trabalho automaticamente.

O cliente titular ou Administração/Atendimento registra uma reclamação explícita no pedido, com assunto, descrição e prioridade. Mensagens comuns não são classificadas automaticamente como reclamações. Administração/Atendimento pode registrar atendimento, resolução ou reabrir, sempre com observação e controle de versão. Autor, datas e histórico ficam preservados, sem exclusão pela interface. Cliente, Produção e Financeiro não podem encerrar a reclamação.

Cada reclamação aberta gera uma tarefa do tipo Tratar reclamação. Configure responsável e prazo em Equipe e acessos. A resolução encerra a tarefa; a reabertura retoma a tarefa existente. Concluir uma tarefa não resolve a reclamação. Não há envio externo de mensagens nem publicação/cobrança por esse fluxo.

Migração aditiva 5: complaints e complaint_events. Dados demonstrativos são fictícios.
