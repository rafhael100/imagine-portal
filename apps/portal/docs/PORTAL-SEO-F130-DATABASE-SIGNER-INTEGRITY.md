# Portal SEO F130 — integridade de chaves e auditoria reforçada no próprio PostgreSQL

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Continua a [RC47 #873](https://github.com/rafhael100/imagine-portal/pull/873) e F127–F129.

## Problema que passa a ser bloqueado

Até F129, o revogador PHP atualizava a chave e inseria a auditoria na mesma transação. Um papel com acesso SQL direto poderia, entretanto, fazer um UPDATE de revogação **sem usar a classe PHP** e sem criar o evento. Da mesma forma, um papel com permissão de UPDATE/DELETE poderia alterar o histórico de auditoria. A F130 acrescenta defesa no **próprio banco**, além do controle de aplicação.

## Mudanças na migration

`2026_11_15_000010_enforce_portal_seo_signer_audit_immutability.php`:

- `seo_f130_key_lifecycle_guard`: bloqueia DELETE e UPDATE de qualquer atributo de uma chave, exceto a transição **active → revoked**, preservando identidade, bytes da chave pública, fingerprint e prazos de validade. Reactivação é proibida.
- `seo_f130_key_must_be_audited`: **constraint trigger DEFERRABLE INITIALLY DEFERRED**. Um UPDATE de revogação só pode confirmar quando existir evento de auditoria do mesmo tenant, emissor, fingerprint e instante `revoked_at`. A checagem ocorre antes do commit: um UPDATE isolado sem evento é desfeito.
- `seo_f130_event_creation_guard`: impede criar evento quando não existe uma chave efetivamente revogada com a mesma identidade e horário no tenant.
- `seo_f130_event_immutable_guard`: rejeita UPDATE/DELETE em eventos já gravados, inclusive quando um papel recebe permissões de UPDATE/DELETE sobre a tabela.
- Índice único `seo_f130_one_revoke_per_key`: impede um segundo evento de revogação para a mesma chave, ainda que alguém forneça outro digest ou outro ator.

Todos os gatilhos são SECURITY INVOKER, com tabelas `ENABLE/FORCE ROW LEVEL SECURITY`, sem habilitar novos endpoints. O fluxo F129, que faz UPDATE da chave seguido de INSERT do evento **na mesma transação**, continua permitido. O worker de concorrência F128 recebe SELECT mínimo de eventos para a verificação diferida no commit.

## Prova real com PostgreSQL 18

O workflow F128/F129/F130 verifica em PHP 8.3 e 8.4:

1. Um papel sem bypass de RLS recebe deliberadamente UPDATE/DELETE de chaves e eventos. O banco **recusa UPDATE direto de revogação sem evento**, mesmo quando o SQL individual executou — a rejeição ocorre no COMMIT.
2. Tentativas de editar bytes da chave, excluir a chave, reativá-la, adulterar eventos ou apagá-los são recusadas.
3. INSERT de evento sem revogação correspondente é recusado; outro evento para chave já revogada é barrado pelo índice único.
4. O revogador F129 continua funcionando com chave e auditoria no mesmo commit; os testes existentes de revogação concorrente e rollback por privilégios incompletos continuam obrigatórios.
5. FORCE RLS oculta registros de outra empresa e impede UPDATE cruzado; a conexão não retém o contexto entre transações.
6. Nenhuma regra é aplicada à VPS ou a um banco produtivo nesta entrega.

## Limites importantes

É **defesa em profundidade, não autorização administrativa completa**. Um usuário malicioso com privilégios simultâneos de UPDATE na chave e INSERT na auditoria ainda pode criar uma alteração com evento correspondente; isso não prova a legitimidade do ator. O dono da tabela/superusuário pode desabilitar gatilhos, e SQL arbitrário com permissão para alterar `app.tenant_ref` pode comprometer isolamento se não houver restrição de execução. Para produção, bloquear DML direto a papéis de aplicação, expor operações somente por serviço/rotina estritamente autorizada, proteger o acesso privilegiado e manter trilha de auditoria independente.

Ainda faltam cadastro de administrador real com MFA e dupla aprovação, KMS, identidade tenant/serviço ativo, governança de chaves, revisão DBA/AppSec/LGPD, staging VPS, backup/restauração e aceite de deploy. Os testes de GitHub Actions utilizam dados e chaves **sintéticos**.

**ReleaseStatus: HOLD_ADMIN_IDENTITY_LEAST_PRIVILEGE_AND_STAGING.**
