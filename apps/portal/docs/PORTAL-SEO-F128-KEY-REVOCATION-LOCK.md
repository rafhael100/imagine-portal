# Portal SEO F128 — serializar revogação de chave e validação de relatório no PostgreSQL

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Continuação da [F127 #870](https://github.com/rafhael100/imagine-portal/pull/870).

## Lacuna fechada no código cooperativo

A F127 já exigia que a chave pública Ed25519 estivesse cadastrada para a empresa e o emissor e fosse consultada dentro da transação que consome o nonce. Porém, uma revogação administrativa **simultânea** poderia ocorrer entre a verificação da chave e a aceitação do relatório.

A F128 introduz `PortalSeoPgSigningKeyLock::acquire()`. O verificador F127 e o revogador `PortalSeoPgSigningKeyRevocationLab` adquirem o **mesmo advisory lock transacional** por par `tenant_ref + signer_ref`, derivado de chaves PostgreSQL inteiras com namespace próprio. A trava dura até commit/rollback. O verificador a mantém ao consultar status da chave, verificar assinatura e inserir o nonce. O revogador a mantém até registrar `key_status='revoked'` e `revoked_at`. Se a revogação confirmar primeiro, o verificador subsequente não consegue aceitar aquela chave.

Esse mecanismo não requer conceder ao verificador de relatórios permissão de `UPDATE` ou `DELETE` das chaves; cada papel retém apenas o acesso apropriado.

## Testes reais no runner GitHub

O workflow `.github/workflows/portal-seo-f128-key-revocation-pg18-ci.yml` usa PostgreSQL 18 real e PHP 8.3/8.4:
- O Engine F121 imutável audita HTML temporário e assina envelope Ed25519.
- Um processo de revogação abre transação RLS, adquire a trava, sinaliza ao processo principal, aguarda brevemente e grava a revogação.
- Enquanto a trava está ocupada, outro processo tenta aceitar o mesmo relatório. Ele deve **esperar** a confirmação da revogação e **recusar** o relatório; nenhum nonce será gravado.
- A classe própria de revogação é executada contra outra chave, com segunda tentativa idempotente recusada.
- Papel somente leitura não revoga. Outra empresa não revoga chave de tenant diferente. Uma chave que continua válida aceita o relatório, mas um build com prévia privada permanece `REVIEW_BLOCKED`.
- O contexto RLS não escapa da transação e o modo `APP_ENV=production` desativa esses laboratórios.

## Riscos ainda não eliminados

Este é um **protocolo cooperativo entre componentes do laboratório**. Uma conta administrativa com privilégios para executar UPDATE diretamente, sem adquirir a mesma trava, ainda pode causar corrida; portanto **não afirmar garantia global de serialização da revogação em produção**. O relatório mantém `keyRevocationRaceProven=false` e `outOfBandAdminRevocationExcluded=false`, embora `cooperativeSignerLockAppliedInLab=true`.

Também não há trilha de auditoria persistente de revogação, administrador com MFA, KMS/HSM, material de chaves produtivas, catalogação segura da autoridade emissora, serviço real de autenticação do Portal, job de manutenção, política LGPD aprovada ou staging VPS. O SQL e os testes são técnicos, com dados e chaves fictícios, sem publicação.
