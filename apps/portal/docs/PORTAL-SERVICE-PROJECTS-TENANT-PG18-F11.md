# Imagine Portal — projetos de serviços isolados por empresa (candidato PostgreSQL 18)

## Modelo entregue

A migration `2026_11_13_000019_create_portal_service_projects_candidate.php` acrescenta a tabela `portal_service_projects` **apenas na CI PostgreSQL 18 descartável**. A estrutura registra a preparação de Onepage, Landing Page ou Performance AI por organização, com referência única dentro da empresa e revisão monotônica. Não registra contas CRM, elegibilidade universitária, cobrança ou serviço ativo.

Um projeto é criado como **rascunho não vinculado**. Opcionalmente pode ser vinculado, uma só vez, a um registro previamente criado na tabela de *candidatos de origem* `portal_service_resource_claims` — já existente na migration #017. A associação é protegida por chave estrangeira composta `(source_claim_id, organization_id, product_code)`, impedindo associar recurso de outra empresa ou outro produto, e por unicidade do claim para evitar a utilização da mesma referência em vários projetos.

O fluxo permite apenas `draft → review → retired` ou `draft → retired`. Não existem estados `active`, `approved`, `published` ou `billable`. A etapa `review` exige o vínculo de origem; revisões e datas não podem ser definidas pelo cliente via SQL. Triggers protegem ID, produto, organização, referência, claim já vinculado, histórico retirado e exclusões. Organizações suspensas não podem originar novos projetos.

## Provas de segurança

Cada tabela candidata conserva `ENABLE/FORCE ROW LEVEL SECURITY` com **zero policies permissivas e zero grants a runtime**. O usuário da aplicação não pode selecionar, inserir, atualizar ou retirar um projeto, mesmo que saiba o UUID. A suíte `PortalServiceProjectsPg18Test` verifica por PostgreSQL real: isolamento entre empresas, referência duplicada, vínculo de claim alheio/retirado/de outro produto, transições proibidas, estado ativo inválido, projetos imutáveis, revisão automática e negação explícita de SELECT/INSERT/UPDATE ao papel runtime.

O workflow `portal-browser-identity-pg18.yml` executa a migration e os testes exclusivamente em contêiner Postgres 18 efêmero, com PHP 8.3 e 8.4 e credenciais sintéticas. Não há migração de VPS, fluxo de provisionamento, permissão de cliente, contrato assinado, acesso HTTP, API ou integração com contas externas.

## Limites para produção

A existência do registro de projeto ou do claim de origem **não comprova propriedade, pagamento, autorização de serviço ou vigência contratual**. Antes de qualquer uso real: identidade Portal/MFA, tenant RLS independente, comprovação de propriedade e assinatura, validação AppSec/DBA, serviço externo que ateste origem, tratamento de revogação/restore (#64), auditoria e homologação integrada (#341). A Universidade continua benefício condicional; CRM mantém conta/sessão próprias sem SSO.

**PR DRAFT / NO-GO**: sem merge em `main`/`dev`, deploy, cliente real ou despesa adicional.
