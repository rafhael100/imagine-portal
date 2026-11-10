# PORTAL-F82 — prova ponta a ponta da senha até sessão com MFA e permissão

**Implementação de laboratório (NÃO operacional), para compatibilizar os componentes F69, F77, F81 e F76.**

O componente `App\\Labs\\IdentityF82\\PortalSyntheticPasswordSessionIssuer` só aceita execução PHP CLI com `APP_ENV=testing`, `PORTAL_F82_PG_LAB=1`, PostgreSQL descartável `portal_identity_lab` e roles conhecidas do CI. O emissor verifica senha Argon2id contra a identidade Portal-only, vínculo à organização, e-mail e TOTP cadastrados, estado ativo e revisões vigentes. Primeiro chama a função F77 de limitação de tentativas através de uma role com apenas EXECUTE. O hash de escopo é HMAC com pepper aleatório do teste e IP sintético de documentação. Somente depois consulta as credenciais.

Com credenciais válidas, cria **sessão `pending_mfa` por cinco minutos** com bearer aleatório de 256 bits, armazenando no PostgreSQL somente o SHA-256 do bearer e as revisões de identidade/membership/organização. A sessão não é autorizada para serviços. O verificador F81 usa o TOTP cifrado da identidade, o counter anti-replay e a versão da organização na mesma transação para completar o MFA. O predicado F76 em role restrita devolve `true` somente para permissão concedida e sessão vigente; retirar o grant ou alterar revisões remove acesso.

A suíte E2E F82 usa PostgreSQL 18 e PHP 8.3/8.4, testa credencial errada, tenant estranho, conta inexistente, bearer não persistido em claro, fase pendente recusada, TOTP incorreto, mudança de revisão, completion válido, grant explícito e retirada de grant, além de bloqueio na sexta tentativa e scope alternativo. Reaproveita em CI F66/F76/F77 e executa rollback completo.

## Limites claros

Este componente **não é login web**: usa superuser somente do PostgreSQL descartável para semear dados, emite bearer apenas para teste, aceita IP entregue pelo chamador CLI, não possui antifraude/recovery/cookie/CSRF, e não pode ser registrado em routes/providers, nem gerar sessões reais. Em operação devem existir roles PostgreSQL de privilégio mínimo revisadas, gestão segura de chaves, email verificado, custódia/rotação, proteção de sessão de ponta a ponta, rate-limit de IP no proxy e antirollback SEC-R03 fora dos backups. SQL SECURITY DEFINER e poderes de emissão de sessão exigem análise DBA/AppSec, inclusive ameaças de indisponibilidade, enumeração e concorrência.

**F82 em PR DRAFT; nenhuma mudança de produção, merge, publicação, VPS, dados de clientes ou nova despesa.**