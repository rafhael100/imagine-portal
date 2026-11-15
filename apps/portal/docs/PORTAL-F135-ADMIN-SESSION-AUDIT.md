# Portal F135 — auditoria obrigatória de revogação administrativa

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Continua [F134 #881](https://github.com/rafhael100/imagine-portal/pull/881), que revoga todas as sessões e desafios TOTP antigos pelo contador `session_version`.

## Implementação

A F135 acrescenta o registro persistente obrigatório para rastrear cada execução de `portal-admin:invalidate-sessions`:

- Migration PostgreSQL `portal_admin_security_events`, com ID de evento aleatório, referência interna da conta, motivo `sessions_revoked` ou `admin_deactivated`, versões antes/depois, instante e origem do operador declarada como **não autenticada** (`ci_synthetic_operator` ou `staging_tty_unverified`).
- O evento **não guarda senha, segredo TOTP, e-mail, IP, token ou cookie de sessão**; esses dados sensíveis não são necessários para explicar a rotação.
- Banco impõe `version_after=version_before+1`, categorias fechadas e `BEFORE UPDATE OR DELETE` que rejeita adulteração e exclusão do evento, mesmo que um papel receba permissões de escrita.
- O comando atualiza `session_version`, opcionalmente desativa a conta e **insere o evento na mesma transação**. Se a gravação da auditoria falhar, toda a revogação é revertida — nenhuma mudança silenciosa e sem registro será concluída.
- Contas inexistentes, entrada inválida e ambiente sem flag de autorização continuam recusados. Não existe endpoint HTTP ou alteração de acesso a clientes.

## Testes reais

No PostgreSQL18 descartável em PHP 8.3/8.4:

1. Revogar sessões grava um evento e incrementa a versão apenas uma vez.
2. Revogar novamente com desativação grava um segundo evento distinto e desativa a conta, dentro de uma única transação.
3. Um trigger sintético provoca falha deliberada de INSERT do evento; o comando falha e **nenhuma versão, conta ou status é modificado**. Após remover o bloqueio temporário, a operação volta a funcionar.
4. UPDATE/DELETE direto do evento é rejeitado pelo gatilho PostgreSQL.
5. Eventos não contêm e-mail, senha ou campos TOTP; sem autorização de CI/staging a operação é recusada.
6. Testes anteriores F132–F134 seguem obrigatórios na mesma matriz.

## Limitações de produção

`actor_context=staging_tty_unverified` não representa identidade administrativa verificada. Um superusuário PostgreSQL ainda pode desabilitar triggers; proteção de backup, retenção LGPD, trilha externa, MFA de operadores, papéis de banco dedicados e autorização contextual são necessários antes da VPS. A migração permanece apenas em branch de revisão; nenhuma chave, usuário, banco ou cliente real foi alterado.

**ReleaseStatus: HOLD_ADMIN_IDENTITY_EXTERNAL_AUDIT_RETENTION_AND_STAGING.**
