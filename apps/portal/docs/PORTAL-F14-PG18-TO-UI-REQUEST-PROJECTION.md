# Imagine Portal F14 — primeira projeção PostgreSQL 18 → domínio → interface Blade

## Entrega executável na CI

A classe **`Tests\\Support\\DisposablePg18ProjectInboxReader`** lê a tabela candidata `portal_service_requests`, criada na PR #627, e transforma seus registros em objetos reais do domínio `ServiceRequestSnapshot`, consumidos por `RequestInboxPreview`. O teste de integração renderiza essas linhas com o componente existente `<x-portal.table />`, preservando rótulos, `caption`, navegação por teclado e escape Blade.

O leitor exige simultaneamente `APP_ENV=testing`, `PORTAL_BROWSER_PG_LAB=DISPOSABLE_PG18`, conexão local `127.0.0.1:5432`, banco `portal_browser_lab`, servidor PostgreSQL 18 e **não aceita uma transação já aberta**. A leitura ocorre sob `REPEATABLE READ, READ ONLY`, vinculando **explicitamente** `organization_id` e `project_id` nas duas consultas. O projeto precisa pertencer à empresa, estar em `draft/review` e a organização deve estar ativa; se houver claim de origem, ele precisa continuar candidato e corresponder à mesma empresa e produto.

A resposta é limitada a 50 solicitações de um projeto. A presença da 51ª solicitação rejeita toda a projeção, sem revelar um subconjunto arbitrário. IDs malformados, tenant diferente, projeto inexistente ou retirado, empresa suspensa, role runtime sem grants e erros da consulta retornam `RequestInboxPreviewResult::rejected('invalid_preview_input')`, **sem registros parciais** e com rollback da transação de leitura. A projeção inclui status reais do enum de domínio e revisões persistidas; não inventa datas de atendimento.

## Restrições intencionais

O leitor permanece **exclusivamente em `tests/Support`**. Usa conexão privilegiada apenas do banco descartável de testes para comprovar a compatibilidade da modelagem com a interface. Não é registrado no container Laravel de produção, não implementa autenticação, RLS permissiva, controller, rota ou autorização. A aplicação runtime continua sem SELECT nas tabelas candidatas; o próprio teste conecta esse role para comprovar a negação.

A CI da branch executa a nova suíte `PortalPg18ToUiRequestProjectionTest` com **PHP 8.3/8.4** sobre o mesmo PostgreSQL 18 efêmero. Confere isolamento entre duas empresas, estado de projeto, suspensão de organização, ausência de dados estrangeiros no Blade, teto de 50 registros, recusa a IDs malformados e impossibilidade de contornar grants com o leitor.

**Não há acesso de cliente, deploy, mudança em VPS/main/dev ou aprovação de homologação.** Para substituir o leitor sintético: identidade e MFA, escopo confiável, políticas RLS auditadas, grants mínimos, publicação de revogação verificável após restauração, revisão AppSec/DBA, e prova de contrato/entitlement. Issues #64 e #341 permanecem bloqueantes.
