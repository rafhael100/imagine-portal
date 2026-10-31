# Ambiente de trabalho

A tela Atividades usa fila e detalhe, com prioridade, prazo, responsável, objetivo, instruções, checklist e histórico. A equipe só recebe atividades atribuídas ao próprio usuário. A administração vê toda a fila e pode filtrar por responsável. Indicadores no topo mostram todas as atividades acessíveis; a fila reflete os filtros.

Prioridades: Urgente, Alta, Normal e Baixa. Ordenação sugerida: prioridade, condição de atraso e prazo. Reclamações, cobrança vencida, ajustes e publicação começam com prioridade Alta; demais tipos com Normal. O administrador pode ajustar cada atividade e registrar o motivo. Isso não muda regras ou atividades futuras.

Prazos são datas, em dias corridos no fuso America/Sao_Paulo. Vence hoje não é atraso. No prazo significa data futura. Alterar o vencimento de uma atividade não modifica a previsão comercial do pedido. Não há escalonamento automático de prioridade.

Cada tipo recebe instruções e checklist de orientação, editáveis nas instruções pelo administrador. O colaborador marca os itens e salva o acompanhamento. A conclusão manual exige checklist completo e observação na interface. Bloqueios exigem explicação na interface. A API mantém validações de acesso, versão, conteúdo e conferência; alterações administrativas de prioridade, prazo e instruções são registradas no histórico.

O checklist não prova execução externa. A automação continua acompanhando as etapas reais do pedido e pode encerrar uma atividade independentemente do checklist; nesse caso, a lista preserva as marcações do colaborador. Concluir a atividade não publica site, não confirma pagamento nem resolve reclamação. Use o pedido para essas operações.

Migração aditiva 6: priority, instructions, goal e checklist em tasks. Aplica guias iniciais às tarefas existentes e preserva as edições nas próximas inicializações. Não há dados fictícios na migração; cenários de demonstração ficam apenas no banco local de testes.

## Solicitação específica

O detalhe começa pelo texto original associado à mensagem, reclamação, aprovação ou versão de ajustes que gerou a tarefa. Não resume nem reinterpreta o pedido do cliente. Se o registro original não for localizado, a interface informa essa ausência. Dados do briefing e anexos são a versão atual do pedido; ficam identificados como tal, sem fingir snapshot histórico. O financeiro não recebe briefing ou nomes de arquivos nesse contexto. Os downloads continuam autenticados e autorizados pela API. Orientações genéricas ficam no ícone ! (hover, foco ou clique); checklist em seção expansível.

## Atividades manuais

Nova atividade permite título, descrição, responsável, prazo, prioridade, resultado esperado e checklist opcional (até 15 itens). Pedido é opcional: tarefas internas não criam pedidos ou clientes fictícios. Administração pode atribuir a si ou a membro ativo e vê todas as atividades. Colaborador só cria para si e só recebe suas tarefas na API, incluindo as criadas pela administração. A criação por identificador único evita duplicação em reenvios. A administração pode reatribuir e o colaborador anterior deixa de visualizar a tarefa. Vincular pedido exige acesso prévio ao pedido. Atividades manuais não são encerradas automaticamente pela mudança de etapa do pedido.

Migração aditiva 7: manual_tasks, manual_task_updates e view work_items. Mantém as tarefas automáticas e seus históricos intactos. Visão geral inclui tarefas internas; BI inclui-as na carga geral da equipe quando nenhum serviço específico está filtrado.

## Editar, cancelar e arquivar

Administrador edita atividades, cancela abertas e arquiva concluídas/canceladas. Colaborador edita ou cancela somente atividades manuais criadas por ele, ainda atribuídas a ele e não arquivadas. A reatribuição fica exclusiva da administração. Todas as ações exigem motivo e versão atual. Arquivamento é exclusivo da administração; a restauração mantém a situação anterior, sem reativar automaticamente uma atividade cancelada.

Checklist preserva a conferência de passos com o mesmo texto. Novos passos ficam pendentes e não são adicionados a uma atividade concluída. O texto original do cliente não é alterado por edição da atividade. Canceladas/arquivadas saem das contagens de pendências e podem ser consultadas pelos filtros. Não há exclusão definitiva. A automação ignora canceladas e não duplica a mesma origem. Cancelar a tarefa não cancela pedido, cobrança ou reclamação.

Migração 8 adiciona archived_at/canceled_at às tabelas, recria a view work_items e registra snapshots antes/depois em task_management_history. As observações continuam disponíveis no histórico da atividade.
