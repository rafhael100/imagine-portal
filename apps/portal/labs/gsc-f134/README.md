# GSC-F134 — Leitura de IAM com papel SQL restrito e RLS por empresa

## Por que esta etapa existe

A F133 demonstrou o predicado de sessão Portal/MFA, concessão explícita `seo.gsc.connect` e propriedade candidata com PostgreSQL real, além do bloqueio de revogações concorrentes. Entretanto o resultado positivo daquele teste era executado como **administrador do banco**, que normalmente contorna RLS. Isso não demonstra que o serviço poderá operar com um papel de privilégios mínimos.

A F134 acrescenta a segunda metade dessa prova **somente ao PostgreSQL 18 efêmero do CI**.

## Prova de privilégios mínimos

1. O testador cria um papel SQL `portal_gsc_f134_reader` com LOGIN sintético, **NOSUPERUSER, NOBYPASSRLS, NOINHERIT, NOCREATEDB e NOCREATEROLE**.
2. Concede apenas SELECT e UPDATE **da coluna `updated_at`** nas seis tabelas que a F133 lê. PostgreSQL 18 requer pelo menos um privilégio UPDATE para o bloqueio `SELECT ... FOR SHARE`, como já verificado no laboratório F112. O papel não recebe permissão para editar estado da sessão, identidade, grant, hash, empresa, propriedade nem revisões.
3. Adiciona RLS de SELECT/UPDATE **limitada a registros sintéticos de uma única empresa**. Essas policies são criadas no próprio teste, **não são migrations** nem funcionalidades do Portal. Um segundo tenant com propriedade semelhante não fica visível.
4. Prova que o leitor F133 consegue obter um diagnóstico positivo **sem BYPASSRLS**, mas continua sem autorizar OAuth. Confirma que o papel não consegue alterar status de sessão nem código de permissão.
5. Enquanto o papel mantém os seis locks por transação, um segundo cliente tenta revogar o grant ou a propriedade e recebe timeout de lock. Após commit, o teste remove o grant explicitamente e o leitor seguinte passa a negar a operação.
6. Restaura apenas a concessão sintética esperada pela F130, remove todas as RLS temporárias, revoga o papel, destrói a sessão criada, confere ausência de vestígios e prossegue com os rollbacks da cadeia de migrations.

O CI usa PHP 8.3 e 8.4. As políticas de laboratório ficam **apenas em `labs/gsc-f134`**, sob guarda de ambiente `PORTAL_F134_PG_LAB=1`, conexão local `portal_identity_lab` e nenhuma fonte de dados real.

## O que permanece NO-GO

Essa prova **não** aprova as RLS para produção. São policies de teste deliberadamente restritas a IDs fictícios; a futura política operacional precisa modelar sessões autenticadas, isolamento por empresa, acesso granular, MFA, revogação, concorrência, consentimento, auditoria, incidentes e LGPD sob revisão independente AppSec e DBA.

O leitor F133 ainda **não é registrado** como autoridade operacional no container Laravel, nenhum endpoint OAuth é aberto, não existe cliente OAuth Google no Portal, cofre KMS, tokens ou propriedade Google autenticada. A interface existente permanece vinculada ao `DenyingPortalAuthorities`.

PR draft sem merge na `main`, migrações na VPS, custos externos ou deploy.
