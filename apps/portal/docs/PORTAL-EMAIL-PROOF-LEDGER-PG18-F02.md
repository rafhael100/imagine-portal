# Portal Imagine — ledger PostgreSQL de comprovantes de e-mail (F02)

**Status: revisão isolada e CI descartável; não é implantação, emissão de convite real nem autorização de usuários.**

A migração candidata `2026_11_13_000018_create_portal_email_proof_challenges.php` implementa o esquema de persistência necessário ao futuro primeiro operador e à verificação de e-mail, após a primitiva HMAC da PR #601.

## Contrato do ledger

- Identidade `identity_id` como chave primária: **um único desafio ativo/histórico por identidade**, sem substituição silenciosa da prova.
- Vínculo `identity_id + organization_id` por FK com membership Portal, endereço `email_normalized` e `identity_revision` positivos. Não usar dados, chaves ou identidade do CRM.
- Somente `proof_digest` HMAC de 64 caracteres hexadecimais é persistido, **nunca o token**, nem chave de HMAC.
- Expiração posterior à emissão e **não superior a dez minutos**; estados `pending`, `consumed` ou `blocked`. Contador entre zero e cinco.
- Trigger SECURITY INVOKER bloqueia troca de conta/empresa/e-mail/digest/revisão/prazos, retrocesso ou salto do contador, consumo após expiração, alteração após consumo e reabertura de prova bloqueada.
- O consumo define o instante por `clock_timestamp()` do PostgreSQL, não timestamp fornecido pelo solicitante. O quinto erro exige transição atômica a `blocked`.
- RLS `ENABLE` + `FORCE`, **nenhuma policy ALLOW**, nenhum GRANT de leitura/gravação à aplicação, nenhum papel de issuer, endpoint ou serviço registrado.
- A migração `down()` recusa remoção com qualquer evidência persistida. Limpeza de ledger precisa de revisão/autorização DBA e auditoria.

## Testes e condições de release

O workflow `portal-browser-identity-pg18.yml`, em PostgreSQL 18 descartável e PHP 8.3/8.4, executa a migração seguida de `PortalEmailProofLedgerPg18Test`, verificando: runtime sem `SELECT/INSERT/UPDATE`, RLS forçado, ausência de policies, consumo único, tentativas/lockout, prova expirada, invariância de escopo, FK e unicidade. Os testes anteriores de senha/MFA/identidade continuam no mesmo workflow.

**Não usar esta migration em VPS/staging/produção sem aprovação.** Sem políticas de issuer e de administração autenticada, **nem o Portal consegue criar ou consumir desafios**; isso é proposital. Pendências: prova efetiva de posse de caixa postal, entrega/reenvio controlado, injeção de chave independente do banco, garantias de recuperação/anti-rollback #64, privilégio mínimo auditado pela DBA/AppSec, integração #341 e autorização de deploy. Nenhuma evidência desta PR pode ativar acesso de cliente ou simular aprovação de produção.
