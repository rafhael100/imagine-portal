# PORTAL-UNI V1 — impedir concessão sem serviço elegível verificado

**15/11/2026 · Revisão de segurança · NO-MERGE / NO-DEPLOY**

## Por que esta barreira é necessária

O Portal já reconhece `university:view` e `university:manage` na [PR #856](https://github.com/rafhael100/imagine-portal/pull/856), mas a fonte confiável de contratos ativos ainda não foi implementada. A tabela `portal_service_resource_claims` contém apenas **candidatos de propriedade de recurso** e proíbe estados verificados; **não** comprova serviço contratado, adimplência, continuidade ou elegibilidade.

Um grant isolado ou o papel `Owner` não pode abrir a Universidade. A Universidade não é produto vendido separadamente.

## Alteração em código

`OrganizationAuthorizationService` agora recusa **explicitamente** as duas permissões da Universidade, mesmo que um objeto de associação de empresa tenha esses grants sintéticos. As permissões existentes (`services.view` etc.) continuam sendo avaliadas normalmente.

`PortalUniversityVocabularyNoGrantTest` verifica: proprietário ativo + grants fictícios de view/manage não autorizam Universidade; permissão ordinária de serviço continua autorizável no domínio puro quando os demais requisitos estão presentes.

A política pura não é endpoint, emissor, SSO nem autorização efetiva de recurso. A decisão de Universidade só poderá ser reaberta após **um componente independente** comprovar na mesma fronteira de confiança: sessão business/MFA, organização e recurso, grant exato, serviço Imagine elegível **ativo e com fonte oficial**, revisão e revogação, inclusive no encerramento do último serviço. Não confiar em fixture, cookie enviado pelo browser ou `portal_service_resource_claims.state=candidate`.

## Próximas tarefas necessárias

1. Definir a fonte oficial de serviços ativos/planos elegíveis no Portal e adaptar os provedores por contrato; não usar o ledger candidato como prova.
2. Homologar emissão/verificação de sessões entre backend Portal e Universidade, assinatura/mTLS e expiração/revisão curtas.
3. Adicionar política transacional de elegibilidade, vinculação do recurso e revogação do último serviço.
4. Só então revisar CHECK do PostgreSQL e o emissor de grants, com testes negativos e rollback auditado.
5. Substituir o provider `UnavailablePortalAuthority` da Universidade **somente após** testes e autorização de homologação.

A [PR #25 da Universidade](https://github.com/rafhael100/imagine-universidade/pull/25) passou 54 testes Laravel e valida o comportamento sintético diante de cancelamento e falha do Portal; isso **não** substitui a fonte real de elegibilidade.

**Nenhuma migração, sessão, grant, rota, cobrança, merge ou deploy foi criado.**
