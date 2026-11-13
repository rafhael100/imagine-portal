# Imagine Portal — etapas de preparação por produto em PostgreSQL 18

## Escopo implementado

A migration `2026_11_13_000020_create_portal_service_project_checkpoints.php` adiciona um ledger de itens de preparação, **por projeto e por organização**, à base candidata da PR #625. Os itens representam **revisões internas sintéticas** e não aprovações de publicação, contrato, cobrança ou execução.

| Produto | Itens obrigatórios para passar a revisão interna |
| --- | --- |
| Onepage | briefing, content, domain |
| Landing Page | briefing, content, consent |
| Performance AI | goals, ad_accounts, creatives, budget |

Um projeto inicia em `draft` e cada item deve nascer como `pending`, com revisão de estado incrementada apenas pelo banco. Cada par projeto/código aparece no máximo uma vez. Um item `reviewed` não pode voltar a `pending`, ser alterado ou apagado. Um item não pode ser criado para uma empresa diferente daquela do projeto, nem para um produto ao qual não pertence.

Uma segunda trigger impede `draft → review` até que todos os códigos exigidos para o produto estejam presentes **e revisados**, e o projeto esteja ligado a um claim de origem ainda de caráter candidato. Não há ação para `active`, `approved`, `published` ou `paid`.

**Garantias de isolamento:** FK composta de projeto e empresa, `ENABLE/FORCE ROW LEVEL SECURITY`, nenhuma policy que permita leitura, nenhuma concessão de privilégios SQL ao role do navegador. Os testes `PortalServiceProjectCheckpointsPg18Test` rodam em PostgreSQL 18 descartável com PHP 8.3/8.4: isolação entre empresas e produtos, itens faltantes, repetição, atualização indevida, estados finais, role sem grants, locks concorrentes e impossibilidade de marcar como concluído sem passar pelas etapas.

## Fora de escopo

A demonstração visual da PR #623 não executa essas migrations, não acessa contas e não grava arquivos reais. Nenhum endpoint HTTP, API de editor, envio de documentos, confirmação de aprovação, cobrança ou anúncio é ativado por essa estrutura. A prova de origem do claim, as assinaturas reais e o serviço externo ainda necessitam homologação, autenticação Portal/MFA, anti-rollback independente (issue #64) e autorização AppSec/DBA e de integração (issue #341). O código permanece em PR DRAFT e não é aplicado a VPS, `dev` nem `main`.
