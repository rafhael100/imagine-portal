# Account mandates — persistência candidata no Portal (19/11/2026)

Esta migração complementa a [issue PERF #425](https://github.com/rafhael100/imagine-performance-ai/issues/425) e o validador de laboratório [PR #426](https://github.com/rafhael100/imagine-performance-ai/pull/426). **Não autoriza consulta real a contas Ads.**

## Recorte

`portal_performance_account_mandates` recebe **somente** referências opacas: empresa, provider, account_id, principal S2S, operação `reports.read`, finalidade `ads_reporting_read`, `consent_reference`, `credential_ref`, revisão, prazo, status e revogação. Não guarda bearer, refresh token, credencial OAuth, escopo de acesso humano ou conteúdo do relatório. Campo `source_kind=synthetic_candidate` e `operational_enabled=false` são protegidos por CHECK: não há promoção silenciosa.

`portal_performance_account_mandate_events` contém registros correlacionáveis de mudança, com FK composta para garantir o mesmo mandato/empresa/principal e trigger append-only. Ambos os objetos têm ENABLE + FORCE RLS; o predicado é empresa **e** principal. **O papel `portal_performance_runtime` não recebe nenhum privilégio** de leitura, escrita ou administração nessas novas tabelas. GUC de escopo isoladamente não autentica um principal; a futura leitura operacional deve usar função SQL com identidade confiável e revisão AppSec/DBA.

## Etapa seguinte

Implementar uma fonte persistente com transação própria, identidade nominal MFA do Portal, delegação S2S específica e token/operações em cofre. Validar revogação/rotatividade em dois serviços, sem transformar registros `candidate` em mandato `active` do engine. A ativação de quaisquer claims exige revisão LGPD/AppSec, consentimento real e GO de staging.

## Testes

`NativePerformanceAccountMandateStoreTest` executa em PostgreSQL descartável (`CI=true`, `ADMIN_PHASE4_PG_CI=1`, `performance_phase4_ci`), comprova schema FORCE RLS, ausência de grants do runtime, restrições dos campos, proibição de promoção, FK/auditoria append-only e falha de leitura pela role runtime. A base de CI é descartada ao fim da execução. CI verde não é homologação da VPS.


## Eventos automáticos no mesmo commit de banco

A migração `2026_11_19_000022` adiciona trigger `AFTER INSERT OR UPDATE` que grava `created`, `revised`, `suspended` ou `revoked` junto à alteração correspondente, dentro da mesma transação PostgreSQL. Uma falha ao registrar o evento impede confirmar a alteração. DELETE de mandato e TRUNCATE de qualquer uma das duas tabelas são recusados por trigger, além das restrições e do histórico já existente. O trigger é `SECURITY INVOKER`: **não concede privilégios**, e o runtime existente segue sem SELECT/INSERT/UPDATE. Para futuras credenciais restritas, a capacidade de inserir eventos terá de ser explicitamente revisada, sem elevar o runtime do receiver.

Os registros gerados incluem identificadores de correlação sintéticos; não representam aprovação humana, consentimento externo ou identidade certificada. Testes em PostgreSQL descartável confirmam um evento por alteração aceita, nenhum evento extra por alteração recusada, bloqueio de DELETE/TRUNCATE, revisionamento e irreversibilidade da revogação. Não habilitar essa estrutura em VPS sem gate operacional.


## Recuperação com conteúdo sintético preenchido

O workflow de autoridade também executa `tests/Fixtures/phase15-mandate-restore/run.sh` em PostgreSQL 18 descartável. Ele exige mandato revogado e eventos reais de laboratório, captura `pg_dump -Fc` local, restaura em segundo banco novo no mesmo contêiner e compara SHA256 do arquivo, conteúdo das tabelas, RLS, triggers e privilégios da identidade runtime. Prova ainda que um novo INSERT no banco restaurado produz seu evento automaticamente, sem alterar a origem. O arquivo e o banco auxiliar são removidos no encerramento. Isto **não é** backup externo, R2, restauração entre servidores, criptografia do dump nem homologação da VPS.
