# Imagine Portal — próximas etapas no painel (lote UI incremental)

A área de **atividades recentes** do painel demonstrativo era um estado vazio que não ajudava a entender o andamento de Onepage, Landing Page e Performance AI. A nova seção **Próximas etapas — acompanhamento ilustrativo** apresenta a referência da solicitação, produto, estado atual, próxima etapa de exemplo e número da revisão.

O serviço `DemoActivityFeed` reutiliza a mesma reconciliação de três fontes tipadas (`DemoClientOverview`, `DemoRequestInbox`, `DemoContractOverview`), não reintroduzindo outra fonte de dados. Se existir referência de serviço desconhecida, status discrepante, empresa diferente, duplicidade ou contagem parcial, não apresenta qualquer etapa. As linhas são ordenadas por **revisão decrescente**, explicitamente **não por data**; não são inventados prazos, datas de atendimento nem ações realizadas.

A tela mantém logo original Imagine, cores e navegação leve já aprovadas, sem novas rotas de cliente, scripts, formulários, conexões a CRM/Sites, dados privados ou autorização operacional. O renderizador de prévia estática gera a atualização a partir do mesmo Blade, e os testes garantem consistência, teclado, marca, isolamento de tenant, ordenação determinística e ausência de endpoints.

**Evolução futura:** com autenticação, RLS, anti-rollback e AppSec/DBA aprovados, uma fonte real e versionada de eventos poderá ocupar o lugar das fixtures. Não confundir versão de solicitação com data nem indicador de exemplo com SLA real. PR em revisão, sem deploy, VPS, merge dev/main ou contas.
