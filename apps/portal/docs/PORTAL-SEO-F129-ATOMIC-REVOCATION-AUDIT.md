# Portal SEO F129 — revogação de chave somente com auditoria atômica

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Continuação de [F128 #871](https://github.com/rafhael100/imagine-portal/pull/871).

## Por que existe
A F128 serializa, de forma cooperativa, a validação e a revogação da chave Ed25519 por tenant e emissor. O status podia ser alterado sem registro durável obrigatório do evento. A F129 torna a gravação de auditoria condição indispensável da revogação.

## Implementação
- A migration `2026_11_15_000009_create_portal_seo_build_signer_events.php` cria `portal_seo_build_signer_events` com `tenant_ref`, digest único do evento, `signer_ref`, fingerprint SHA-256 da chave pública, `actor_ref`, código `signer_key_revoked` e horário. **Nenhuma chave privada, token, conteúdo de cliente ou relatório HTML é persistido.**
- A tabela tem `ENABLE/FORCE ROW LEVEL SECURITY`, validação de campos e chave primária tenant+digest. Sem permissões concedidas por padrão aos papéis de aplicação.
- `PortalSeoPgSigningKeyRevocationLab::revoke` segura o mesmo advisory lock F128, modifica `key_status` e obrigatoriamente insere o evento **antes do commit**. Se o INSERT falhar por privilégio, RLS ou unicidade, a transação inteira é revertida pelo helper de transações PG.
- Segunda tentativa de revogação da mesma chave não altera o status e **não cria outro evento**.
- `revocationAuditPersistedInPgLab=true` é emitido somente após sucesso do INSERT na transação; `realAuditRetentionApproved=false`, `realAdministratorVerified=false` e `publicationAuthorized=false` permanecem explícitos.

## Provas em PostgreSQL 18 real
O workflow F128/F129 executa a corrida entre revogação e validação, agora com o worker gravando também auditoria na mesma transação. O teste F129 utiliza outros papéis e tenant isolado:
- Role com `SELECT,UPDATE` nas chaves **mas sem INSERT no evento**: a tentativa falha e a chave continua ativa; a tabela de auditoria continua intacta.
- Role com `INSERT` na auditoria **mas sem UPDATE da chave**: não consegue concluir nem deixar evento parcial.
- Role com permissões mínimas de ambas as operações: revoga a chave, persiste exatamente um evento, recusa repetição e mantém isolamento RLS por empresa.
- Duas chaves diferentes produzem dois eventos distintos; outra empresa não consegue revogar nenhuma delas nem consultar seus eventos.
- O modo `APP_ENV=production` continua impedindo todo o laboratório.

## Limites para produção
O registro é uma trilha **de laboratório**, não um sistema definitivo de auditoria administrativa. Um administrador de banco com privilégios elevados ainda poderia executar alterações diretas, ignorando o protocolo de advisory lock. A versão final precisa de controle de acesso privilegiado, auditoria separada e imutabilidade/retention policy, autenticação MFA dos administradores, KMS/HSM, revisão LGPD, observabilidade, homologação e rollback na VPS.

A migration existe no repositório, **não foi aplicada ao banco de produção**. Sem merge, deploy, endpoints HTTP, Google OAuth ou exposição de clientes.
