# Portal GSC F109 — grant explícito de leitura de métricas, separado de conexão

**Candidata de laboratório / NÃO é autorização de produção.**

A F108 protege a observação da sessão e registros GSC por bloqueios de
linha na mesma transação. Ainda assim, um usuário com sessão válida e
propriedade conectada não tem necessariamente direito a ler métricas.

A F109 diferencia **`seo.gsc.connect`** (configuração de propriedade,
vocabulário já previsto na identidade do Portal) de
**`seo.gsc.view`** (proposta explícita para leitura de indicadores).
O novo código não presume que um grant de conexão, papel `owner` ou
status ativo equivalha a permissão de leitura.

## Entrega

- `locked-view-grant.sql`: tabela `portal_membership_grants` de
  **laboratório**, apenas com as duas permissões de teste, e papel
  `NOLOGIN` restrito. A função
  `portal_gsc_f109_metric_read_candidate` chama a observação
  F108 sob o mesmo `SET LOCAL app.tenant_ref` e consulta o grant
  `seo.gsc.view` associado ao membership, com
  `FOR SHARE OF m,mg`. O grant continua bloqueado até a transação
  externa terminar.
- `OfflinePortalGscMetricsGrantReview` retorna somente um relatório
  interno, nunca um token, endpoint, URL de propriedade ou autorização
  de visualização a clientes.
- `test-postgres.php` usa duas conexões PostgreSQL reais e demonstra
  que `seo.gsc.connect` sozinho é recusado; o grant `seo.gsc.view`
  permite **apenas candidato sintético**. Enquanto a transação de
  observação está aberta, uma segunda conexão não consegue excluir
  esse grant (`lock_timeout` → SQLSTATE `55P03`). Após commit,
  uma exclusão efetivada é observada e a próxima leitura falha.
- CI: três cenários F104 (completo/parcial/vazio), PHP 8.4,
  PostgreSQL 16 descartável, migrações GSC revertidas, source SHA pinada.

## O que permanece necessário

A migration real do vocabulário `seo.gsc.view` no domínio de identidade
do Portal, o aval do esquema de grants e RBAC, e a autorização de
exibição são **dependências não resolvidas**. A tabela aqui é somente
fixture: não se publica uma política `FOR ALL` e concessão UPDATE
de chaves no produto sem revisão DBA/AppSec de imutabilidade, segurança
de `SECURITY DEFINER`, ownership, tenant e contenção.

A checagem ainda não é autenticação HTTP de clientes; faltam
OAuth Google próprio, consentimento, property binding verificável,
cofre de tokens, revogação operacional, LGPD, staging, backup e
rollback. A conexão OpenSEO não comprova autorização SaaS.

Todas as flags de autenticação operacional, leitura Google, exibição,
publicação e autorização permanecem **falsas**.

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY / NO-CUSTOMER-DATA.**
