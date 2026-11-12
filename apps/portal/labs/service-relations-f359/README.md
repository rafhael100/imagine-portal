# F359 — quarentena total pós-restauração (laboratório sintético)

A [issue de segurança SEC-R03 #64](https://github.com/rafhael100/imagine-portal/issues/64) descreve a alternativa *fail-safe* de descartar sessões/autorizações de um backup suspeito e exigir nova autenticação. Este recorte prova **somente** a ideia contra a fixture F24 de duas empresas inventadas, depois do `pg_dump/pg_restore` real da F32. **Não é um procedimento para a VPS ou para dados de clientes.**

O workflow `portal-f22-laravel-pg-readonly.yml`, em PostgreSQL 18 descartável, primeiro restaura a sessão fictícia antiga e demonstra que a prova assinada antiga pode acessar *somente uma prévia sintética* se a testemunha não for consultada. Depois executa `quarantine-restored-fixture.sql` com uma transação que bloqueia as duas tabelas, confere nomes e identidades exatos e revoga todas as sessões e vínculos do domínio fictício. Cada revisão só avança na primeira execução; a segunda deve ser idempotente.

O laboratório verifica as duas empresas A/B em SQL, a recusa do token antigo **e até de um token artificial com revisões atualizadas** por `F24SyntheticAuthorizedReader`, ausência de linhas expostas e de autorização de serviço, e preservação da testemunha independente após a operação. O script SQL não é instalado como migration, não entra em `app/Providers`, não registra rotas nem conecta nenhum CRM/Sites ou banco operacional.

**Limite inegociável:** não foram invalidados tokens reais, sessões de PHP/Workers, cookies, senhas, fatores MFA, recovery codes, caches, chaves HMAC, filas ou provedor externo; não existe barreira de serving em um ambiente de produção nem coordenação contra concorrência real. A prova não substitui anti-rollback de testemunha externa e mantém as issues #64/#122 e a candidata #578 **NO-GO** para merge/deploy/serving.

## F360 — atomicidade e recusa de sessões inesperadas

Antes do teste positivo F359, a CI insere temporariamente uma **terceira sessão fictícia** na organização A. O mesmo SQL deve interromper a transação com erro sem revogar a sessão A, a sessão B ou seus memberships; a CI confere que as **três sessões continuam ativas em revisão 1** e que os dois vínculos continuam ativos antes de remover a sessão adicional. Só então executa a quarentena completa, verifica A/B bloqueadas e exige idempotência na segunda execução. Essa prova é deliberadamente destrutiva **apenas sobre fixtures descartáveis** e não deve ser usada como script de reparo em bancos operacionais.
