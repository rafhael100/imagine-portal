# BI de atividades

Acesso: menu **BI · Atividades** ou `/admin?view=tasks-bi`. Exclusivo do administrador sem perfil de colaborador. Atendimento, Produção, Financeiro e clientes não acessam os endpoints JSON nem CSV. Não depende da ativação do Asaas.

## Leituras disponíveis

- Fila atual: abertas, atrasadas, bloqueadas, vencendo hoje, sem responsável ativo e de prioridade alta/urgente.
- Entregas: concluídas, canceladas, arquivadas, pontualidade em relação ao prazo atual, média e mediana do ciclo.
- Distribuição por situação, prioridade, tipo e idade das abertas.
- Criações e conclusões por mês, da seleção atual.
- Carga por colaborador: abertas, atrasadas, bloqueadas, urgentes, concluídas, ciclo e pontualidade.
- Ocorrências para agir primeiro, com acesso ao pedido e relatório detalhado com última observação.

## Filtros e interpretação

Período de até três anos, por criação, prazo atual ou conclusão; colaborador (inclui administrador e desativados), cliente, serviço, jornada atual, tipo, situação, prioridade, atenção, arquivo e busca por texto/ID. Atalhos para mês, atrasadas, bloqueadas e concluídas. Gráficos e nomes de colaboradores permitem aplicar filtros.

Todas as agregações são feitas sobre `work_items` completo (automáticas e manuais), sem o limite de mil registros da fila operacional. A tabela tem 40 linhas por página; o CSV inclui toda a seleção. CSV com BOM UTF-8, separador ponto e vírgula, escape de aspas e proteção contra fórmulas. Impressão/PDF usa a tabela da página atual; para todas as linhas, use CSV.

O período seleciona uma coorte de atividades. Os indicadores mostram o estado atual dessa coorte, e não o estado que cada atividade tinha no passado. Os gráficos mensais contam criações e conclusões da coorte dentro do período. Não são um histórico diário de backlog, nem uma reconstrução de cancelamentos ou atribuições passadas.

Atraso é calculado em dias corridos pelo prazo atual e data de Brasília. Concluídas, canceladas e arquivadas não entram no backlog. Sem responsável ativo inclui assignee nulo ou colaborador desativado. Atividades internas são incluídas, mas saem ao filtrar cliente, produto ou jornada.

## Conclusão e qualidade dos dados

Conclusões manuais usam o evento de progresso registrado. Edições, arquivo e restauração não mudam sua data. Reabertura seguida de nova conclusão usa o início da sequência final de conclusão. A migração aditiva 9 cria `task_completion_events` e registra novas conclusões automáticas, sem alterar dados históricos. Sincronizações seguintes não duplicam o evento.

Sem evento explícito, a conclusão histórica é aproximada pela última atualização conhecida do estado concluído, anterior a edições gerenciais quando disponível. Esses registros são identificados como aproximados e excluídos da média, mediana e pontualidade. Os gráficos e tabela ainda os incluem, com essa limitação declarada na tela.

Ciclo significa tempo corrido entre criação e conclusão, não horas efetivamente trabalhadas. Pontualidade compara com o prazo atual, que pode ter sido renegociado. Alterações de prazo só contam snapshots de gestão; atualizações antigas por outras telas podem não ter snapshots estruturados. Nenhum cálculo infere esforço, custo, lucro, horas ou complexidade.

Atribuições são atuais. Não usar contagem simples de tarefas como avaliação isolada de desempenho, pois as tarefas têm complexidades diferentes. Em demonstração local, os registros existentes são fictícios; em produção, os mesmos cálculos usarão os registros reais do portal.

## API e validação

- `GET /api/admin/tasks-bi`: filtros, agregações e página detalhada.
- `GET /api/admin/tasks-bi.csv`: todas as linhas dos mesmos filtros.
- Testes: coortes, datas inválidas, cancelamento/arquivo, conclusão editada, pontualidade, filtros cruzados, 1.050 tarefas, paginação, CSV e isolamento de acesso.

Módulo introduzido na versão 0.13.0. Implantação no servidor ainda pendente; consulte os pull requests e releases no GitHub para o histórico do código.
