# Portal GSC F108 — transação única e bloqueio de revogação (laboratório)

## Implementação

A F107 verificava sessão e GSC em **transações separadas**, deixando uma
janela entre as duas observações. Esta entrega acrescenta o candidato
`OfflinePortalGscAtomicFenceReview` e a função SQL restrita
`portal_gsc_f108_locked_observation` para fazer tudo numa **única
transação PostgreSQL**:

1. O serviço de teste valida formato de sessão opaca, propriedade
   selecionada pelo backend, tenant candidato de DTO F104 e ambiente.
2. `GoogleSearchConsoleTenantScopedTransaction` verifica que a conexão
   usa papel sem superuser, BYPASSRLS, CREATEROLE, INHERIT ou SET ROLE, e
   configura `SET LOCAL app.tenant_ref`.
3. Função `SECURITY DEFINER`, com papel `NOLOGIN` e permissões de
   coluna limitadas, verifica por hash SHA-256 a sessão Portal
   (`business`, MFA, expiração, revisão de identidade, associação e
   organização), a linha de vínculo UUID e o grant GSC readonly.
4. `FOR SHARE OF s,i,m,o,l,g` mantém bloqueadas **as seis linhas**
   (sessão, identidade, organização, membership, vínculo e grant) até
   o `COMMIT` da mesma transação. Retorna `issued_at` real da sessão,
   não o horário fabricado de observação usado na F107.
5. A saída continua uma **prévia interna não autorizada para clientes**.
   Nunca retorna bearer, UUID, nome de cliente, token ou URL da propriedade.

## Prova de concorrência

Workflow `portal-gsc-f108-atomic-ci.yml`: PostgreSQL 16 descartável,
PHP 8.4, Node 22, código SEO F104 na SHA
`ec1739621b20860f729c59d9863ba1b341da43e8`.
A fixture de identidade usa o mesmo esquema sintético da F107.

Para cada cenário F104 (completo, parcial e vazio), enquanto o leitor
mantém a transação aberta, uma segunda conexão administrativa tenta
modificar, com `lock_timeout` curto, cada uma das seis linhas. A prova
**exige SQLSTATE 55P03** para todos os seis conflitos. Depois de commit,
revogações e alterações de versão são realizadas e a próxima leitura
deve ser recusada. Também são exercitados outro tenant/propriedade,
sessão falsa, MFA ausente, finalidade da sessão inválida e gateway
fechado fora do ambiente de testes. Migrações GSC são revertidas.

Os bloqueios protegem apenas as linhas observadas durante a
transação; **não tornam o resultado reutilizável ou autorizado após
COMMIT**. Não provam que todas as possíveis rotinas operacionais de
revisão e revogação usam este protocolo. Custo de contenção, ordem
de locks e deadlocks precisam de revisão DBA independente.

## Restrições de segurança

O SQL é **somente uma fixture candidata de laboratório**, não uma
migration de aplicação. O proprietário `NOLOGIN` recebe
`UPDATE` em colunas-chave apenas para solicitar `FOR SHARE`; isso
ainda exige revisão de imutabilidade de identificadores, privilégios,
propriedade SQL, `SECURITY DEFINER`, search_path, policy RLS,
esquema de produção e segregação de funções. A conta `reader` não
recebe SELECT/UPDATE direto em nenhuma das seis tabelas.

Este módulo **não** ativa rota HTTP, login, Google Search Console,
permissão SEO explícita, OAuth/consentimento atual ou painel para clientes.
Um registro de grant não é uma prova de autorização Google, e não
substitui revisão de privacidade, AppSec, DBA, homologação, backups e
rollback. Mesmo quando há prova de bloqueio, as flags
`sessionAuthenticatedForProduction`, `permissionForSeoVerified`,
`customerDisplayAuthorized`, `productionReadAuthorized` e
`publicationAuthorized` são `false`.

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.**
