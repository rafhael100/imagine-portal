# GSC Slice P — revogação de grants do Search Console

O Lote P acrescenta a **revogação segura de permissões já registradas** na tabela de grants do Lote O, sem introduzir uma funcionalidade para conceder permissões ou chamar a API Google.

## Componentes implementados

`GoogleSearchConsolePropertyGrantRevocation` oferece dois comandos restritos a backend de confiança:

- `revokeExact(tenant, actor, siteUrl, now)`: atualiza atomicamente um único registro **ativo, não revogado** para `revoked`, filtrando por empresa, operador, impressão digital da propriedade exata e escopo somente de leitura. O retorno é apenas booleano. Uma segunda revogação não reativa o registro nem informa dados privados.
- `revokeActorBatch(tenant, actor, now, limit)`: revoga no máximo 250 grants do operador e empresa informados, de forma transacional por operação SQL com `FOR UPDATE SKIP LOCKED`. Pode ser repetido por um futuro job interno ao remover uma assinatura/operador; não altera outros operadores ou empresas.

Os dois métodos recusam timestamps inadequados, referências malformadas e dados que não correspondam a registros já existentes. **Não aceitam propriedade autodeclarada como prova de autorização**, não geram credenciais e não removem grants de outras empresas.

## Testes reais de PostgreSQL

Em `imagine_gsc_ci_test` descartável, o script testa registro sintético anterior à revogação, revogação exata e repetida, erro por ator/empresa, divergência de propriedades `sc-domain:` e `https:`, batch com limite 1, preservação de outro tenant/ator e recusa de timestamps inválidos. Não há rede Google nem cliente real.

## Limitações e dependências

Esta é **revogação do registro local**, e não revogação remota do acesso OAuth no Google. O acionamento operacional precisa vir de um serviço autenticado do Portal após MFA/RBAC e verificação confiável da empresa, além de registrar o evento em auditoria sem dados privados. Não há controller/rota, agendamento, autenticação, transporte remoto ou token vault neste lote.

**DRAFT/NO-GO:** CI, revisão independente AppSec/DBA/LGPD, staging autenticado, fluxo de cancelamento e políticas de retenção são pré-condições para a promoção. Nenhum merge em dev/main, deploy, DNS/301 ou custo realizado.
