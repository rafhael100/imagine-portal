# P0 — adaptador PHP que utiliza comandos SQL com capacidade por cliente

## Entrega

A PR #707 demonstrou, em PostgreSQL 18 descartável, que um login SQL compartilhado pode perder todos os grants diretos de tabela e depender de funções `SECURITY DEFINER` com HMAC de escopo restrito. Esta entrega cria **duas classes PHP reais**, porém limitadas explicitamente à CI:

- `App\Homologation\SyntheticOwnerSqlCapability`: emite HMAC SHA256, com chave **injetada** e `#[SensitiveParameter]`, somente para ação, empresa, cliente, pedido e expiração de 60 segundos. Recusa ações, identidades e recursos inesperados.
- `App\Homologation\SyntheticCapabilityOrderStore`: adaptador PDO para `create_owned_order`, `list_owned_orders` e `approve_owned_order`. Confere por consulta que está conectado ao banco `portal_capability_ci` e ao login SQL restrito `portal_capability_ci`. Não possui métodos SQL para ler tabelas diretamente.

O novo teste `test_php_capability_order_store_pg18.php` exercita as classes com **dois clientes fictícios da mesma empresa e outro de empresa diferente**, usando a mesma credencial SQL. Confere idempotência, alteração de produto recusada, leitura e aprovação independentes, impossibilidade de SELECT direto, chave HMAC incorreta negada, inputs inesperados e nova conexão de banco. A CI existente da PR #707 roda a fixture em PHP 8.3 e 8.4 com PostgreSQL 18.

## Limitações obrigatórias

A chave de HMAC, identidades e login SQL são **de laboratório**. Não há código para obter a identidade do cliente por MFA, sessão real e vínculo empresarial, nem witness anti-rollback. Um serviço com a chave HMAC também precisa garantir que nenhum código de rota possa solicitar capacidades para outros clientes. A solução atual `CustomerPreviewStore` **permanece inalterada** e ainda possui GRANT SELECT amplo; as novas classes não são registradas no Laravel runtime. A auditoria da PR #711 continua marcando **NO-GO** para dados pessoais.

**Próxima integração**, depois de revisão de segurança: custody/rotação da chave, política de expiração e anti-replay, identidade de usuário vinda de servidor confiável, funções SQL com owner não-superuser, transações de pagamento/briefing, migração dos pedidos sem dono e testes com bancos reais. Não fazer transição automática do SQL de fixture para a VPS.

**Status:** DRAFT / NO-MERGE / NO-DEPLOY. Uso com clientes reais não autorizado.
