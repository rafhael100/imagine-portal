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

## Integridade SQL das referências e revisões (candidato)

A migração incremental `2026_11_19_000023` restringe `consent_reference` e
`credential_ref` a ASCII, primeiro caractere alfanumérico, demais caracteres
`[A-Za-z0-9_.:-]`, comprimento de 8 a 128 bytes. O banco e o gate PHP
passam a aceitar os mesmos formatos; não basta confiar no validador de request.
A constraint UNIQUE `(mandate_id, revision)` impede duplicação do evento
gerado para a mesma revisão.

Os testes executam INSERTs inválidos com FK, status, escopo e revisão válidos,
exigindo SQLSTATE `23514` em vez de conflito de chave ou erro de trigger.
Verificam que não foi criado evento; INSERTs válidos em ambos os limites
passam; evento de revisão duplicada falha com `23505`. O restore sintético
verifica que as três constraints sobrevivem à recuperação em outro banco.

Isso não prova consentimento humano nem habilita S2S real. Permanecem
`source_kind=synthetic_candidate`, `operational_enabled=false`, nenhum
GRANT ao runtime e nenhuma credencial operacional.

## Bloqueio de pré-inserção de evento de revisão futura (candidato)

A migração incremental `2026_11_19_000024` rejeita via trigger SQLSTATE
`23514` eventos cuja `revision` não corresponda **à revisão atual do mandato**
com a mesma empresa e principal. Sem ela, um escritor com INSERT na tabela
de auditoria poderia pré-registrar uma revisão futura; o evento automático
colidiria mais tarde com a UNIQUE `(mandate_id, revision)` e impediria uma
rotação/revogação legítima. O teste prova recusa de pré-inserção **antes** de
uma alteração válida, e a geração normal do evento na revisão seguinte.
O restore PostgreSQL descartável verifica a existência do trigger e o mesmo
bloqueio de evento futuro após recuperação. Nenhum GRANT é concedido.

**Limites:** o esquema ainda não identifica a autoria humana da alteração.
SQL proprietário/superusuário, GUCs de escopo, ausência de cofre IAM, consentimento
real e vínculo SQL001→S2S continuam sem homologação e não devem ser usados para
emitir mandatos operacionais. A prova é de um banco sintético descartável, não
de um backup externo, de Ads ou da VPS.


## Prova multiempresa sobre mesma conta publicitária (PostgreSQL descartável)

O teste `test_same_provider_account_has_independent_tenants_and_no_cross_tenant_audit`
constrói duas empresas sintéticas com o **mesmo** provider, account_id e
principal técnico. A unicidade do mandato é por empresa; a criação registra
um evento independente para cada empresa. Uma tentativa direta de registrar
evento de B apontando ao mandato de A deve retornar SQLSTATE `23514`;
um segundo mandato com a mesma chave lógica em A retorna `23505`.
Revogar A deve deixar B com status candidato e sem novos eventos, e nenhum
registro pode estar `operational_enabled=true`.

A prova observa os invariantes em banco de CI descartável, **não** demonstra
a emissão da delegação autenticada ou `SQL001→S2S`, acesso de runtime a
mandatos, contas Google/Meta autorizadas, consentimento humano, integração de
cofre ou restore de VPS. Os testes continuam dentro da role de migração
privilegiada controlada, sem conceder SELECT ao runtime, sem nova API e
sem mudança na classificação `synthetic_candidate`.


## Imutabilidade pós-revogação (candidato sintético, 19/11/2026)

A migração `000025` protege o estado terminal: após `status=revoked`, nem uma alteração mantendo esse status pode atualizar `credential_ref`, `consent_reference`, prazo ou revisão. Antes, uma UPDATE com revisão nova poderia reescrever referências de um mandato revogado e gerar outro evento `revoked`, reduzindo a utilidade forense da auditoria. Uma transição inicial `candidate→revoked` continua possível com evento automático transacional; alterações posteriores falham com SQLSTATE `23514`.

Os testes PostgreSQL18 descartáveis cobrem a transição permitida, quatro mutações proibidas e contagem de eventos inalterada; a restauração sintética repete uma mutação pós-revogação adversarial no banco restaurado. São fixtures, não prova de backups R2/Leaseweb, autenticidade de consentimento, IAM/cofre ou permissão Google/Meta. Nenhum GRANT à role runtime e nenhum caminho operacional foi criado. Revisão AppSec/DBA/LGPD e gate Leaseweb continuam obrigatórios.
