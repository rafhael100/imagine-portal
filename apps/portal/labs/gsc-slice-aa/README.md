# GSC Slice AA — gateway de permissões sob RLS com revogação e auditoria

A fase Z forneceu um wrapper transacional para a variável PostgreSQL `app.tenant_ref`. Este lote **passa a utilizar o wrapper nas operações reais do domínio PHP** que consultam e revogam registros locais de propriedade Google Search Console.

## `GoogleSearchConsoleTenantGrantGateway`

O gateway aceita um **contexto já autenticado pelo backend** com `tenantRef` e `actorRef`. Ele não autentica usuários, não verifica MFA e não aceita esse contexto como prova de autorização do Google.

- `inspectRecordedGrant(context, siteUrl, now)` executa `GoogleSearchConsoleRecordedPropertyGrant::inspect` dentro da transação com `SET LOCAL app.tenant_ref`, usando um papel PostgreSQL não administrador. Retorna o contrato conservador de registro recente; `propertyAuthorizationVerified=false` e `customerDisplayAuthorized=false` continuam negativos.
- `revokeRecordedGrantWithAudit(context, siteUrl, now)` usa **a mesma conexão e transação** para atualizar a propriedade exata e inserir o evento pseudônimo `property_grant_revoked`. Se uma das operações falhar, a transação sofre rollback. Uma segunda revogação não duplica o evento.
- O gateway recusa contexto malformado e propriedades inválidas. Não cria novos grants, não chama OAuth Google e não registra tokens ou URLs em claro no evento.

## Teste PostgreSQL 16 descartável

A suíte do Lote V foi ampliada com a integração AA:
1. Insere propriedade exata de `tenant-one` e `tenant-two` sob um usuário administrador **somente para preparar o banco de testes**.
2. Um papel não proprietário e sem BYPASSRLS consegue consultar somente seu próprio grant, com contexto de transação local; outro tenant ou ator recebe resultado vazio.
3. A revogação exata de `tenant-one` funciona e gera exatamente um evento; repetição não gera novo evento.
4. Quando é retirada a permissão `INSERT` do papel de testes na tabela de auditoria, a revogação de `tenant-two` falha e o grant permanece ativo; restaurando o privilégio, a revogação e auditoria são confirmadas juntas.
5. Fora de uma transação com contexto, nenhuma das quatro tabelas expõe registros ao papel restrito.

## Limitações operacionais

Este **não é um controller Laravel exposto**. O Portal ainda precisa autenticar sessão/MFA/RBAC, resolver membership tenant/ator sem strings vindas do navegador, disponibilizar uma conexão de runtime não privilegiada, verificar Google OAuth e propriedade em tempo real, configurar segredos e homologar staging. O `siteUrl` passado ao gateway deve ser uma propriedade exata previamente obtida de fonte Google confiável, não alegação de titularidade.

**DRAFT/NO-GO:** sem merge em dev/main, deploy, migração em bancos de clientes, HTTP Google real ou novos custos. Revisão independente AppSec/DBA/LGPD ainda obrigatória.
