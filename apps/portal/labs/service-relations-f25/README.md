# PORTAL-F25 — ensaio de concorrência leitura x revogação com advisory locks

**09/11/2026 — PostgreSQL 18 descartável. NÃO é protocolo de revogação homologado.**

## Problema

No F24, o serviço PHP lê a sessão/membership e serviços dentro de uma
transação de leitura `REPEATABLE READ`. Entretanto, um revogador pode
completar uma alteração de autorização enquanto uma leitura guarda snapshot
antigo. F25 cria um **ensaio cooperativo de sincronização** para reduzir
essa corrida no banco de laboratório.

## Contrato experimental

1. O serviço `F24SyntheticAuthorizedReader` fornece uma prova sintética
   válida ao adaptador PHP F22.
2. Antes de iniciar sua transação `REPEATABLE READ, READ ONLY`, o
   adaptador adquire `pg_advisory_lock_shared` **de sessão** para uma
   chave fixa do laboratório `org-a` ou `org-b`. Os dois números são
   identificadores públicos, nunca segredos nem controle de acesso.
3. Durante a transação, F22/F23 verifica `session_user`, não-superuser,
   FORCE RLS e `security_invoker`; F24 verifica assinatura do fixture,
   revisões e estado atual do membership/sessão; depois lê somente a view
   F21. Concluída ou negada a leitura, libera a trava de sessão no
   `finally`. Um `lock_timeout` local curto impede espera ilimitada
   por locks em testes.
4. `schema.sql` acrescenta triggers **apenas nas tabelas de fixture F24**
   que exigem `pg_advisory_xact_lock` exclusivo antes de qualquer
   INSERT/UPDATE/DELETE em sessão ou membership. Uma revogação cooperativa
   aguarda leituras com trava compartilhada concluírem; uma leitura que
   venha após uma revogação confirmada deve adquirir lock e então criar
   seu próprio snapshot atualizado.
5. Testes PHPUnit fazem leitura com a trava liberada, verificam ausência
   de lock remanescente, e **mantêm uma trava compartilhada de teste aberta**
   enquanto outro usuário SQL tenta revogar sessão ou alterar membership.
   Espera-se falha por `lock_timeout`. Depois da liberação, a alteração
   deve poder ser concluída e uma nova leitura deve negar a prova antiga.
6. O workflow herda jobs PHP 8.3 e PHP 8.4 contra container
   `postgres:18` em porta aleatória de loopback, sem qualquer
   contato com servidor do cliente ou VPS.

## O que F25 NÃO prova

- Esses advisory locks são **voluntários** no caminho de leitura. Os
  triggers do laboratório obrigam mudanças DML normais a obedecerem,
  mas administradores com privilégios podem desabilitar triggers ou
  alterar funções. Outros fluxos de escrita reais não foram inventariados.
- Um processo que nunca adquira o lock de leitura continua fora da
  barreira. F22 sem prova F24 permanece disponível **somente no
  laboratório**, para testes antigos, e não é rota HTTP operacional.
- A aquisição do lock está fora da transação para garantir que o
  snapshot REPEATABLE READ seja criado **depois** de a trava
  compartilhada ser obtida. Isso introduz risco de falha de liberação
  ou conexões interrompidas, exigindo teste de pool/timeout/limpeza
  antes de qualquer uso real.
- A revogação não pode desfazer uma resposta já entregue ao cliente;
  o protocolo de decisão externa, filas, jobs, cache, outbox,
  tokens e motores Imagine continua **não implementado**.
- Não há Z4 durável, witness externo monotônico, proteção contra
  restauração conjunta de estado, operação multirregião, RPO/RTO,
  IAM verdadeiro ou administração segregada.
- O caso `PG_TRIGGERS_VALID` não é atestado externamente; uma
  alteração maliciosa no próprio banco pode falsear as garantias.
  A segurança produtiva requer uma abordagem independente de
  revogação, com revalidação em todos os writers/readers e AppSec/DBA.
- Os testes são com duas empresas fictícias, sem documentos jurídicos,
  contratos reais, cartão, login, SSO ou elegibilidade Universidade.

## Status e gates

**PR DRAFT, sem merge, deploy ou mudanças de infraestrutura.** A avaliação
é de um PostgreSQL efêmero. Não altera `main`, `dev`, servidores
Leaseweb, Cloudflare, R2, serviços externos ou dados reais. Não cria
cobrança de fornecedor externo; CI pode consumir minutos do GitHub.

SEC-R03, P0, D02-SEC, D03-RLS, AppSec/DBA, T3 e OPEN permanecem
**abertos**. Nenhum PASS desta PoC é autorização operacional.
