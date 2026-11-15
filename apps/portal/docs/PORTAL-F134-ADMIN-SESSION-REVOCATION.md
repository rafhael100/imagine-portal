# Portal F134 — revogação global de sessões administrativas por versão persistida

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Continuação de [F133 #878](https://github.com/rafhael100/imagine-portal/pull/878) e [F132 #877](https://github.com/rafhael100/imagine-portal/pull/877).

## Problema resolvido

A F132 já verificava usuário ativo e segundo fator a cada acesso administrativo. Contudo, após troca de senha, perda de dispositivo de MFA ou suspeita de comprometimento, **não existia uma forma de invalidar de uma vez todos os cookies administrativos ativos e todos os desafios de senha ainda pendentes**.

A F134 cria uma versão de sessão por conta (`portal_admin_users.session_version`), que inicia em 1 e nunca pode voltar a zero. Não depende do ID do cookie, do cache de rate limit ou da lista de dispositivos.

- **Senha inicial:** ao validar senha, o Portal grava no servidor a versão vigente junto ao desafio pendente de TOTP. Nenhuma sessão de guard é criada ainda.
- **Segundo fator:** o desafio só existe se a conta continuar ativa e a versão no banco ainda corresponder à capturada após a senha. O UPDATE de contador TOTP exige, no mesmo CAS, a versão da conta, impedindo o aceite caso uma rotação ocorra durante a validação.
- **Sessão emitida:** após senha e TOTP, a versão vigente é gravada na sessão Laravel, e a proteção de `/portal-admin` exige correspondência exata com a versão atual lida novamente no PostgreSQL.
- **Revogação:** o comando `php artisan portal-admin:invalidate-sessions --email=...` incrementa a versão sob `lockForUpdate`, invalidando os acessos anteriores na próxima requisição. Com `--deactivate`, também desativa a conta na mesma transação.
- **Permissão para executar:** o comando só funciona em CI PG18 com flag de teste ou em staging autorizado com `PORTAL_ADMIN_PROVISION_ENABLED=true` e terminal interativo com confirmação; **não opera em produção** e não expõe API HTTP.
- A versão é um número de comparação, não substitui autenticação MFA, CSRF, sessão segura nem autorização por tenant; continua a existir somente o painel vazio sem dados de clientes.

## Validação com PostgreSQL 18
CI PHP 8.3/8.4 com migrations reais:
- Login senha + TOTP continua permitido com sessão vigente.
- Após incrementar a versão no banco, uma sessão anteriormente autenticada deixa de acessar o painel.
- Após senha, mas antes do TOTP, a alteração da versão cancela o desafio pendente.
- O comando CLI revoga sessões e pode opcionalmente desativar a conta; não altera contas inexistentes e não divulga existência de conta.
- Rate limits, código TOTP de uso único, logout e bloqueios F132/F133 continuam validados por regressão.

## Limites operacionais

Sem identidade administrativa validada para produção, gerenciamento de credenciais e recuperação MFA, armazenamento persistente de sessões na VPS, rate limit Redis distribuído, trilha de auditoria da revogação, autorização por organização, KMS/AppSec/DBA/LGPD, TLS homologado e restauração/rollback, a funcionalidade permanece **desligada por padrão**. Não cria conta real, não altera `main` nem conecta recursos SEO/CRM/GSC.

**ReleaseStatus: HOLD_ADMIN_SESSION_STAGING_OPERATIONAL_SECURITY.**
