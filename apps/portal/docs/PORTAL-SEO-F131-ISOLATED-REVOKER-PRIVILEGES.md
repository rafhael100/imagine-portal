# Portal SEO F131 — revogador SQL com privilégios mínimos, sem DML direto do operador

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Continuação de [F130 #875](https://github.com/rafhael100/imagine-portal/pull/875).

## Por que importa

A F130 implantou gatilhos no banco para bloquear alterações de chaves sem evento de auditoria e impedir adulteração do histórico. Ainda assim, um papel que recebesse simultaneamente UPDATE de chaves e INSERT da auditoria poderia executar os dois comandos sem passar pela rotina autorizada da aplicação.

A F131 reduz os privilégios necessários ao solicitante da revogação: ele pode **executar uma função SQL restrita**, mas **não possui UPDATE nas chaves nem INSERT na tabela de eventos**.

## Implementação isolada em CI

O SQL `apps/portal/labs/seo-f131-pg18/install-isolated-revoker.sql`:

- **Recusa qualquer banco diferente de `portal_gsc_f108_ci`**. Não é migration de produção.
- Cria `portal_seo_f131_function_owner`, papel **NOLOGIN, NOSUPERUSER, NOBYPASSRLS e NOINHERIT**. Somente esse papel recebe `SELECT,UPDATE` de chaves e `SELECT,INSERT` de eventos.
- Cria `portal_seo_f131_operator` sem login, destinado exclusivamente à concessão de `EXECUTE` da função. Não recebe UPDATE/INSERT nas tabelas.
- Implementa `public.portal_seo_f131_revoke_signer` como `SECURITY DEFINER`, com `search_path` fixo e owner limitado, que exige correspondência entre o tenant fornecido e `app.tenant_ref` local da transação.
- Reutiliza a trava cooperativa de tenant+emissor da F128, altera a chave para revogada e grava o evento na mesma transação. Os gatilhos F130 garantem que o evento corresponda à alteração e que o histórico seja somente de acréscimo.
- **Revoga EXECUTE de PUBLIC** e permite execução apenas à role operadora cadastrada. Não aceita SQL dinâmico, caminho arbitrário, URL de cliente ou token.

O adaptador PHP `PortalSeoPgIsolatedRevokerLab` só invoca essa função via parâmetros SQL e contexto sintético CI restrito. Não usa UPDATE nem INSERT diretos.

## Testes em PostgreSQL 18 real

O workflow F128–F131 (PHP 8.3/8.4) testa, em banco descartável:

1. O proprietário da função não tem LOGIN, SUPERUSER ou BYPASSRLS.
2. A função é `SECURITY DEFINER`, não é executável publicamente e o operador não possui UPDATE ou INSERT nas tabelas.
3. Um papel sem permissão de EXECUTE não consegue revogar; SQL direto do operador não consegue atualizar a chave nem inserir evento.
4. A chamada válida confirma uma revogação e exatamente um evento; uma repetição não gera novo evento.
5. Divergência entre tenant explícito e `app.tenant_ref` é recusada.
6. Retirada da permissão INSERT da role proprietária faz a função falhar, e o UPDATE da chave sofre rollback. A restauração da permissão restabelece a operação.
7. A conexão não mantém contexto RLS entre transações; outra empresa não vê eventos daquele tenant; o modo produção recusa o laboratório.

## Limites que impedem produção

**Esta é uma prova de arquitetura em CI, não uma rotina administrativa pronta para uso real.** O `app.tenant_ref` é um GUC PostgreSQL que pode ser falsificado por alguém com capacidade de executar SQL arbitrário; não autentica o cliente sozinho. O parâmetro `actor_ref` também não comprova a identidade do operador. A função utiliza um owner limitado, mas uma conta superusuária ainda pode desabilitar gatilhos ou alterar concessões.

A versão operacional precisará de identidade Portal/MFA real, autorização por empresa e função de negócio, canal administrativo autenticado, bloqueio de SQL arbitrário, credenciais próprias e gestão de concessões, KMS, auditoria independente, backup e rollback na VPS, DBA/AppSec/LGPD e aceite formal. Por segurança, o instalador SQL da F131 **não participa de migrations regulares de produção**.

**ReleaseStatus: HOLD_ADMIN_AUTH_IDENTITY_AND_SECURE_DEPLOYMENT.**
