# GSC Slice AB — bloqueio explícito do vault de laboratório fora de testes

O vault do Lote S usa criptografia real, mas **a origem e as permissões de um token Google ainda não estão verificadas por um serviço autenticado**. Sem um bloqueio explícito, um integrador futuro poderia reutilizar inadvertidamente `storeSyntheticCandidate` em produção, gravando um token recebido de fonte não confiável.

## Garantia introduzida

Os métodos `storeSyntheticCandidate`, `inspectSyntheticCandidate`, `rotateSyntheticCandidate` e `revokeSyntheticCandidate` passaram a exigir **simultaneamente**:
- `APP_ENV=testing`;
- a habilitação de um laboratório sintético conhecido (`PORTAL_GSC_TOKEN_VAULT_LAB`, `PORTAL_GSC_TOKEN_RETENTION_LAB` ou `PORTAL_GSC_KEY_ROTATION_LAB` com valor `1`).

A escrita e a leitura exigem ainda que o access token, e qualquer refresh token existente, utilizem o prefixo **`synthetic-`**, reservado aos testes. Tokens de formato plausível, porém sem esse marcador, são rejeitados mesmo quando um laboratório está habilitado.

Essa restrição é uma defesa contra ativação acidental; **não é um controle anti-forgery de produção**: quem controla o ambiente PHP de testes pode criar tokens de laboratório. Um vault de credenciais reais deve possuir outro serviço, exclusivamente autenticado, com comprovação Google atual, grants e rotinas de auditoria/revogação aprovadas.

## Testes

A suíte S valida que dados sintéticos continuam cifrados e isolados, mas um token sem marcador não é gravado. Também altera temporariamente o ambiente do processo para `production` e remove a flag do laboratório, confirmando que escrita, leitura e revogação sintéticas são bloqueadas. Os laboratórios T/U mantêm suas próprias flags para limpeza e rotação de dados de teste.

## Gatilhos de liberação

Ainda faltam confirmação de sessão Portal/MFA/RBAC, prova Google de escopo e propriedade, transporte HTTPS, cofre de segredos operacional, papel PostgreSQL de runtime, políticas de retenção e revisão AppSec/DBA/LGPD. **Este vault não deve ser conectado a uma rota de cliente.**

**DRAFT/NO-GO:** nenhuma credencial real, deploy, aplicação de migração produtiva, merge em dev/main ou despesa adicional.
