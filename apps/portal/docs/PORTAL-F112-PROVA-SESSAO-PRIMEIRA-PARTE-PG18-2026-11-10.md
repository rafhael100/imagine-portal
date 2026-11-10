# PORTAL-F112 — Prova de origem de identidade a partir de sessão Portal no PostgreSQL 18

**Data:** 10/11/2026. **Status:** integração PHP↔PG18 executável em runner descartável; **não** é login ou identidade operacional.

## Avanço concreto

A F110 criou o contrato `PortalInvitationIdentitySource` e as regras de pré-verificação de um convite. A F111 adicionou uma trava de release, impedindo que o Laravel troque acidentalmente a autoridade negadora por qualquer fonte não revisada.

A F112 implementa **um adaptador funcional exclusivamente em `apps/portal/labs/identity-f112/`** que preenche o contrato F110 a partir de registros PostgreSQL de sessão, identidade, empresa e vínculo empresarial. O ator, empresa, MFA, datas e revisões vêm de linhas do banco, **não de um DTO de identidade enviado pelo chamador**.

`PortalF112OfflinePgSessionIdentitySource.php` valida a referência opaca da sessão como Base64URL canônico de 256 bits e seleciona a empresa como UUID canônico; calcula SHA-256 internamente, realiza a leitura através de PDO prepared statement e exige que a conexão SQL corresponda **somente** à conta de laboratório `portal_f112_reader` no banco `portal_identity_lab`, em loopback, com `APP_ENV=testing`, `PORTAL_F112_PG_LAB=1` e execução CLI. Em qualquer erro retorna `null`, sem comunicar existência de identidade/empresa ou dados SQL.

`verified_portal_session_evidence.sql` instala, somente no runner descartável:

- papel proprietário `portal_f112_owner` com `NOLOGIN/NOSUPERUSER/NOBYPASSRLS`, permissões **SELECT somente nas colunas essenciais** de quatro tabelas; não lê hash de senha, segredo de MFA nem email;
- quatro policies `FOR SELECT` destinadas **exclusivamente a esse proprietário NOLOGIN**, sob `ENABLE+FORCE RLS`, sem acesso dos leitores aos dados;
- função `SECURITY DEFINER` com `search_path` controlado, `row_security=on`, execução concedida somente ao leitor `portal_f112_reader`, e consulta com digest de sessão/empresa, `JOIN` de vínculo e comparações de revisões;
- verificação de sessão `active`, não revogada, com expiração máxima de 24h, identidade e organização ativas, membership convidada, e-mail e MFA declarados como confirmados, MFA da sessão com idade **até 5 minutos** e revisões de segurança iguais às do banco;
- saída composta só por UUIDs, tempos em epoch e revisões, sem token, digest, endereço de e-mail, segredo MFA ou permissões de negócio.

A função prova **proveniência de dados do banco durante o ensaio**, não comprova que quem gravou o registro era de fato um emissor autorizado nem que a sessão da pessoa foi obtida por login+MFA legítimos. Os dados são gerados por fixture e não devem ser confundidos com identidade real.

## Testes de segurança

O teste `test_pg_verified_session_evidence.php`, executado em PHP 8.3/8.4 + PostgreSQL 18 descartável, confirma:

- conexão com papel `EXECUTE-only` e ausência de SELECT direto; `FORCE RLS` com zero linhas mesmo quando se acrescenta temporariamente SELECT de uma coluna;
- token sintético de sessão com digest armazenado, evidência derivada do banco e pré-verificação F110 **apenas sinteticamente positiva**, mas sem autorizar HTTP, criar sessão ou consumir convite;
- negação de token desconhecido, token CRM, token malformado, empresa estrangeira e conexão superuser indevida;
- negação de MFA com mais de 300 segundos, MFA futuro, e-mail ou MFA não confirmados, sessão expirada/revogada, empresa ou vínculo alterados, e revisões de identidade, membership e organização divergentes;
- preservação de ausência de permissões de negócio e nenhuma mutação de sessão; em `APP_ENV=production` ou sem flag de laboratório a fonte nega tudo;
- rollback das migrations da fundação F66 e revisão organizacional F76 e destruição de roles/functions/policies.

O workflow F112 usa banco temporário e credenciais somente do runner. O PR também executa testes Laravel, smoke Docker F64 e gate F111 de não liberação.

## Decisões e bloqueios de produção

**Não** vincular a fonte F112 no `AppServiceProvider`; ela deve ficar exclusivamente em `labs/`, fora da imagem Docker de homologação. O Laravel continua resolvendo `DenyingPortalInvitationIdentitySource` (F110) e `DenyingPortalAuthorities` (F98). A única rota é `/up`.

Uma liberação real ainda exige emissor de sessão seguro, algoritmo de login/Argon2id e MFA testado de ponta a ponta, armazenamento/renovação de cookies, medidas CSRF, rate-limit, prova de titularidade do canal de convite, permissão e RLS reais por empresa, revogação transacional sem janela TOCTOU, revisão independente DBA/AppSec, LGPD, auditoria antirollback externa SEC-R03, segredos e homologação na VPS. As policies `USING(true)` deste laboratório **não são regras operacionais multiempresa**.

Nenhum merge em `main`, novo endpoint HTTP, deploy, conexão CRM/Ads, banco de clientes ou custos de fornecedores. **PR draft / NO-GO.**
