# GSC Slice R — revogação e auditoria na mesma transação PostgreSQL

A etapa R conecta os lotes **P (revogação local de grants)** e **Q (eventos de segurança sem segredos)** para evitar estados inconsistentes.

`GoogleSearchConsoleAuditedGrantRevocation` utiliza a **mesma conexão PDO** para executar `BEGIN`, atualizar um grant e inserir o evento de auditoria correspondente, confirmando ambos somente com `COMMIT`.

## Operações

- `revokeExact(tenant, actor, siteUrl, now)`: revoga um único registro da propriedade exata e grava `property_grant_revoked` com fingerprint pseudônimo. Se o grant não existir, já estiver revogado ou a auditoria falhar, a transação é revertida; nenhum resultado falsamente confirmado.
- `revokeActorBatch(tenant, actor, now, limit)`: utiliza a revogação em lote da fase P e registra um evento agregado `property_access_suspended` quando algum registro foi alterado. Se nenhum registro mudar, retorna zero sem inserir evento.
- Recusa participar de uma transação externa já aberta para não confirmar alterações de outro fluxo; erros de banco resultam em rollback e mensagens genéricas.
- Não recebe tokens OAuth, código de autorização, consultas ou domínio em texto livre na auditoria. Não prova identidade do usuário nem titularidade Google.

## Testes no banco descartável

O laboratório valida sucesso, repetição sem duplicar evento, isolamento entre empresas, revogação em lotes e **falha induzida de auditoria**: a tabela de eventos é temporariamente indisponibilizada e a revogação deve ser revertida. Também comprova que a operação não interfere em transação externa aberta pelo chamador.

## Gates de liberação

A conexão PDO deve vir de um backend autenticado com privilégio mínimo no PostgreSQL. Ainda faltam MFA/RBAC e memberships reais, validação Google do grant, integração com cancelamento e revogação remota de refresh tokens. O evento local e o estado de revogação **não comprovam** conclusão de revogação no provedor.

**DRAFT/NO-GO:** nenhum merge em dev/main, dados de clientes, deploy, Google HTTP ou cobrança; revisão AppSec/DBA/LGPD e homologação obrigatórias.
