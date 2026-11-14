# Vínculo individual da compra — recorte sintético

Implementado: decisão de propriedade por par organização/cliente, com referência imutável do pedido. Outro cliente na mesma empresa e o mesmo identificador em outra empresa são recusados. Não há privilégio administrativo implícito.

Ainda não ligado às rotas nem ao PostgreSQL. O ambiente publicado continua usando a empresa fictícia compartilhada. Não usar esta classe como prova de identidade ou autorização HTTP.

Integração necessária:
1. Obter cliente e organização da sessão validada e do vínculo ativo no servidor.
2. Persistir proprietário junto da criação do pedido na mesma transação; chave idempotente deve incluir proprietário.
3. Aplicar o vínculo na leitura, listagem, pagamento de teste e briefing; nenhuma identidade aceita de campos HTTP.
4. Manter propriedade desconhecida nos pedidos antigos: não atribuir automaticamente ao primeiro usuário.
5. Testar dois clientes, troca de identificador na URL, listagens, revogação e reinício do banco antes de publicar.
6. Cadastro e informações pessoais dependem dessa integração; produção permanece fora deste recorte.

Validação: `php apps/portal/tools/test_synthetic_purchase_ownership.php`.

## Evidência de persistência e isolamento no PostgreSQL 18 (laboratório)

O teste `apps/portal/tools/test_synthetic_purchase_ownership_pg18.php` usa **somente o PostgreSQL 18 descartável do GitHub Actions** (`portal_ownership_ci`) e falha se as variáveis explícitas de CI não estiverem presentes. Ele cria uma tabela e três logins PostgreSQL sem privilégios de administrador, com `FORCE ROW LEVEL SECURITY`, política vinculada ao usuário SQL autenticado e permissões de escrita limitadas à coluna de estado.

Cobertura: dois clientes da **mesma organização** com a mesma chave de idempotência criam pedidos distintos; um cliente de outra organização não consegue ler ou alterar seus registros; repetição pelo mesmo cliente não cria duplicatas; mesma chave com produto divergente é rejeitada; registros antigos com proprietário `NULL` permanecem invisíveis; cliente não pode forjar proprietário nem mudar o proprietário de um pedido; a recuperação após uma nova conexão mantém os dados e o isolamento. Esse recorte também prova que uma variável de sessão SQL arbitrária não altera o proprietário escolhido pela política.

Os logins do teste **não** são autenticação real de cliente. O banco temporário **não** é um migration do Laravel. A tabela operacional existente continua sem a coluna de proprietário. Antes de qualquer aplicação real, projetar e revisar migration/backfill **não automático**, relações de usuário/empresa comprovadas, enforcement em cada leitura/escrita do Portal, provas de concorrência/revogação e witness anti-rollback da issue #64. Proibido vincular uma compra antiga sem proprietário ao primeiro usuário que abrir a página.

CI: `.github/workflows/portal-synthetic-purchase-ownership.yml` com PHP 8.3/8.4 e PostgreSQL 18. Nenhuma VPS ou rota HTTP é tocada.
