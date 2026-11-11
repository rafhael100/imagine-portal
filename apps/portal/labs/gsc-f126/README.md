# GSC-F126 — Vínculo do cofre imutável e revogação definitiva

A F125 criou o registro de **metadados de referência** do futuro cofre de tokens, com escopo somente leitura, FK composta de empresa/propriedade, RLS fechado e sem tokens. A F126 fecha uma lacuna adicional de integridade no próprio PostgreSQL: alguém que consiga gravar futuramente um registro não poderá reutilizá-lo para outra empresa, propriedade, digest de referência, versão de chave ou escopo.

## Restrições

A migration Laravel F126 instala uma função e um trigger `BEFORE INSERT OR UPDATE` em `portal_gsc_vault_reference_candidates`.

- INSERT somente com `pending_review`, sem `revoked_at`, com `binding_revision=1`.
- `id`, `organization_id`, `candidate_id`, `vault_reference_sha256`, `key_version`, `scope` e `created_at` ficam imutáveis.
- A **única atualização** admitida é `pending_review → revoked`, com timestamp compatível e incremento de revisão exatamente +1. Um registro revogado não pode ser reativado, editado ou reutilizado.
- O rollback da migration F126 recusa remover a proteção enquanto restar qualquer registro, sem apagar dados implicitamente.
- O teste integrado aplica F66 → F123 → F124 → F125 → F126 em PostgreSQL 18 descartável e PHP 8.3/8.4, executa tentativas adversariais de troca de empresa, propriedade, digest, versão de chave, ampliação do escopo, salto/repetição de revisão e reativação, e desfaz cada migration em ordem inversa após limpeza de **fixtures artificiais**.

## Limitações e requisitos de operação

O novo trigger continua submetido à autoridade do DBA: administradores de banco podem remover triggers ou apagar linhas. **Não fornece resistência criptográfica**, provedor KMS, auditoria fora do banco ou revogação no Google. Como a F125 não tem estado `active`, essa tabela **não representa uma conexão existente** e não permite armazenar nem recuperar tokens. Rotacionar uma referência exige um projeto separado, auditado, com migração segura; não reutilizar registros anteriores ou contornar a unicidade.

**Acesso continua fechado:** RLS FORCE sem policies, nenhuma rota HTTP, autenticação operacional, OAuth client Google, secret vault real, requisição Google ou produção. As migrations são propostas de PRs draft; a avaliação independente AppSec/DBA e homologação da VPS continuam obrigatórias antes de qualquer aplicação.
