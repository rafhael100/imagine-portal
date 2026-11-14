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
