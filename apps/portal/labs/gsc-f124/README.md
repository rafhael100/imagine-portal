# GSC-F124 — Proteção contra troca de empresa/propriedade e reativação após revogação

A F123 criou uma tabela de propriedades **candidatas** por empresa, com RLS fechado por padrão. A F124 adiciona uma segunda migration Laravel PostgreSQL com um trigger `BEFORE UPDATE` que reforça a integridade das linhas já cadastradas.

## Restrições implementadas

- `id`, empresa, membro fundador, `site_url`, SHA-256, tipo da propriedade e data de criação permanecem **imutáveis** após o primeiro cadastro. A propriedade não pode ser substituída por outra, nem movida silenciosamente para outra empresa.
- Estados seguem apenas `candidate → disabled → revoked` ou `candidate → revoked`; um registro desabilitado **não pode voltar** a `candidate`, e um registro revogado não pode mais ser atualizado.
- Qualquer atualização exige `security_revision` estritamente maior que a anterior. A mudança de estado `revoked` exige `revoked_at` conforme as constraints da F123.
- O trigger é função PostgreSQL `SECURITY INVOKER` padrão, com `search_path` fixo e EXECUTE revogado de PUBLIC. **Não habilita RLS, policies ou poderes de escrita** para a aplicação.
- Rollback da migration F124 é recusado enquanto a tabela possuir candidatos: sem essa regra, uma reversão poderia retirar a barreira de imutabilidade de dados existentes.

O CI aplica as migrations reais F66, F123 e F124 em PostgreSQL 18 descartável, testa também PHP 8.3/8.4 e simula troca de empresa, usuário e propriedade com digest consistente, redução da revisão, reativação de revogado e tentativas de contornar estados. Em seguida valida que o rollback é bloqueado enquanto existir dado e só reverte após limpeza explícita de **fixtures sintéticas**.

## Limites para produção

Um administrador PostgreSQL pode executar DELETE ou desligar triggers, e a F124 não cria uma trilha de auditoria externa resistente a rollback de backup. Sem políticas de RLS e IAM operacional, nenhum cliente pode cadastrar nem consultar propriedades. Registros são candidatos não comprovados: **não são conexões Google nem grants de token**. A persistência real requer revisão AppSec/DBA independente, políticas LGPD/retention, vault, sessão/MFA, propriedade confirmada pela API Google autenticada e homologação VPS.

As migrations estão somente em PRs draft. Nenhum merge `main`, deploy, DNS, tokens reais ou gasto foi realizado.
