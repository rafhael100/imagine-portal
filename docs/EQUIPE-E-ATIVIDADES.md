# Equipe e atividades

O administrador gerencia colaboradores em **Equipe e acessos**. As funções iniciais são Atendimento, Produção e Financeiro. Administradores completos continuam sendo criados pela ferramenta de gestão do servidor, não pela tela de colaboradores.

- Atendimento: pedidos, clientes, materiais e conversa; pode revisar o briefing. Sem dados financeiros.
- Produção: somente pedidos com tarefas atribuídas; pode registrar evolução, enviar prévias e registrar publicação autorizada. Sem dados financeiros. A aprovação continua exclusiva do cliente titular.
- Financeiro: pedidos e cobranças; pode sincronizar com Asaas. Não altera materiais, produção, catálogo ou equipe.
- Administrador: acesso completo, equipe e regras de distribuição.

As permissões são conferidas na API. Alterar função ou desativar um colaborador encerra suas sessões. Os registros e tarefas são preservados. O administrador deve reatribuir tarefas de colaboradores desativados. Convites por e-mail, recuperação de senha por e-mail, MFA e permissões personalizadas por campo ainda não estão implementados. A senha inicial deve ser entregue por um canal apropriado e pode ser alterada em Minha conta.

## Automação

A cada 30 segundos, enquanto o processo do servidor está ativo, e ao abrir Atividades, uma conciliação cria tarefas para materiais enviados, produção liberada, ajustes solicitados, publicação aprovada, cobrança vencida e última mensagem de cliente ainda sem resposta. Chaves únicas por evento evitam duplicação. Uma etapa já existente também é reconhecida ao instalar esta versão. Prazos são em dias corridos desde a criação da tarefa, não desde o evento histórico; não substituem o prazo comercial de sete dias úteis.

Regras atribuem um responsável fixo ativo por tipo e um prazo. Sem responsável, a atividade entra na fila sem atribuição. Salvar uma regra também distribui tarefas abertas ainda sem responsável; não redistribui silenciosamente tarefas já atribuídas. A conclusão efetiva da etapa encerra automaticamente a tarefa correspondente. Marcar uma tarefa concluída não aprova, publica nem cobra um pedido. Há controle de versão para atualizações concorrentes, auditoria de alterações e armazenamento das observações em task_updates. Não há disparos de WhatsApp/e-mail, publicação automática de sites ou chamadas adicionais ao Asaas nesta automação.

As atividades aparecem no painel; é necessário atualizar a tela para ver mudanças feitas pelo processamento em segundo plano. A lista limita-se às 1.000 tarefas mais recentes, priorizando abertas.

Migração aditiva 4: staff, tasks, task_rules e task_updates. Usuários internos têm uma associação em staff; contas sem associação preservam as regras anteriores. Backups devem incluir todas essas tabelas.
