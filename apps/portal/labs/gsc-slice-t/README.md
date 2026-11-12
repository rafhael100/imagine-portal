# GSC Slice T — eliminação limitada de candidatos OAuth cifrados

Este lote complementa o vault cifrado do Lote S com uma rotina **de backend** para remover candidatos a token expirados ou revogados, sem emitir tokens e sem chamar Google.

A classe `GoogleSearchConsoleTokenCandidateRetention` executa uma consulta PostgreSQL parametrizada com `FOR UPDATE SKIP LOCKED`, bloqueando e removendo **1 a 500 registros** por operação. Um registro é elegível quando:
- Sua expiração já passou (`expires_at < now`); ou
- Foi revogado e o evento de revogação ocorreu há mais de 60 segundos.

Os registros não vencidos e ainda não revogados permanecem. A operação retorna apenas contagem; não devolve empresa, ator, propriedade, tokens ou corpo criptografado. Entradas de tempo/limite inválidas retornam `null` e não executam SQL.

O laboratório T exercita PostgreSQL descartável, gerando candidatos fictícios vencidos, revogados há mais de 60 segundos, revogados recentemente e ainda ativos. Exige limpeza em lotes, não exclusão de registros válidos, expiração posterior dos remanescentes, limites estritos e nenhuma autorização de uso ou revogação Google.

**Ainda é necessário integrar a rotina a um job interno autenticado e agendado** com privilégios mínimos de banco, métricas sem segredos e política de retenção revisada por LGPD. Eliminar um registro local **não revoga** eventual refresh token no Google.

**DRAFT/NO-GO**: sem merge, deploy, credenciais reais, chamadas externas ou custos. Requer revisão AppSec/DBA/LGPD e homologação em staging.
