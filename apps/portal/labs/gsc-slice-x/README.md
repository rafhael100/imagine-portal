# GSC Slice X — um único plano de troca por callback consumido

A etapa W assegurou que o resultado da callback fosse emitido pelo coordenador **após o consumo atômico do state no PostgreSQL**. Faltava restringir quantas vezes esse resultado poderia gerar um plano de POST de token OAuth.

## Implementado

- `GoogleSearchConsoleOAuthCallbackCoordinator` mantém uma permissão privada e efêmera em `WeakMap` para cada objeto de callback genuinamente consumido. O registro começa como **não utilizado**.
- `claimTokenPlanOnce($outcome)` marca esse registro como utilizado exatamente uma vez; uma chamada repetida ou um objeto fabricado/clonado falha.
- `GoogleSearchConsoleOAuthTokenExchangePlan::prepare` verifica configuração, código, verificador e origem primeiro; **só então consome** a permissão. Entradas de configuração inválidas são recusadas sem queimar uma callback ainda válida.
- Depois do primeiro plano válido, novas chamadas para o mesmo objeto retornam `null`, reduzindo o risco de duplicação de tentativa de troca do mesmo código.

Os testes com **PostgreSQL 16 descartável** cobrem callback emitido pelo coordenador, primeiro plano válido, repetição negada, adulteração da configuração seguida de primeira tentativa válida, clonagem e recusa de objetos forjados. Os estados OAuth continuam vinculados à empresa e ao operador.

## Restrições

`WeakMap` é um controle local ao processo PHP e não autoriza HTTP. O código OAuth é de uso único também no Google, mas o serviço real de transporte, retries, token vault, sessão MFA/RBAC, grants e revogação **não estão operacionais** nesta revisão. O objeto de requisição contém segredos somente para backend e não pode ser serializado ao cliente.

**DRAFT/NO-GO:** nenhum merge em dev/main, deploy, dados de clientes, credenciais Google reais ou custos. Revisão AppSec/DBA/LGPD e testes autenticados em staging seguem necessários.
