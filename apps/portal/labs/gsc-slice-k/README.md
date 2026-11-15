# GSC Slice K — retenção curta e proteção de chave em memória

O estado OAuth é temporário e contém metadados pseudônimos (empresa, operador, hash) e um verificador PKCE cifrado. A liberação do OAuth **não** pode criar um banco de estados abandonados sem limpeza.

## Alterações

- O Ledger existente agora encapsula a **chave de 32 bytes** em `SensitiveParameterValue`, não em propriedade de string simples. `print_r`, `var_export` e serialização não devem mostrar a chave. A origem operacional da chave deve ser cofre seguro, sem commit de secrets.
- `GoogleSearchConsoleOAuthStateRetention` remove no máximo **500 linhas** por chamada, usando CTE PostgreSQL com `FOR UPDATE SKIP LOCKED`, `LIMIT` e `DELETE` condicionado a `expires_at` ou `consumed_at` antigo.
- Estados expirados são elegíveis imediatamente depois de seu TTL de 600 segundos; estados consumidos são elegíveis após **60 segundos**. Um estado ainda pendente e válido não é excluído.
- Nunca retorna IDs de empresas, states, verifiers, ciphertext ou tokens; retorna somente quantidade removida ou `null` diante de entrada inválida/falha.
- Projetado para um futuro **job interno** com papel PostgreSQL de manutenção e horário confiável; não é uma rota HTTP nem executa agendamento por conta própria.

## Testes em PostgreSQL descartável

O teste `verify_postgres_retention.php` cria estados sintéticos vencidos, recentemente consumidos, antigos consumidos e pendentes. Verifica eliminação em lotes de 1, preservação dos pendentes, limite de 500, bloqueio de valores negativos/fracionários e remoção após TTL. Confere ainda que dumps do objeto não contêm chave de criptografia.

## Antes de integrar

Ainda são necessários job de manutenção autenticado, privilégios separados por função do PostgreSQL, métricas sem PII, política de backups/retention LGPD, revogação de tokens e testes de implantação real. Esta operação **não revoga** tokens do Google; apenas apaga estados de pré-autorização local.

**DRAFT/NO-GO:** sem migração em produção, merge em dev/main, OAuth real, dados de clientes, deploy ou custos; revisar AppSec/DBA/LGPD independentemente.
