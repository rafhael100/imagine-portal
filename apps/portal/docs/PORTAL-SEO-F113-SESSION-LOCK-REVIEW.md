# Portal SEO F113 — contenção entre revogação de sessão e callback OAuth

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.**
Continuação da [F112 / PR #850](https://github.com/rafhael100/imagine-portal/pull/850).

## Problema solucionado no laboratório

A F112 revalidava os registros da sessão e consumia o state OAuth na
mesma transação PostgreSQL. Mas a role de leitura não pode executar
SELECT FOR SHARE sem privilégios extras. Ampliar acesso UPDATE à
role de callback violaria o princípio de privilégio mínimo.

A F113 introduz **bloqueio advisory transacional cooperativo** com a
mesma chave derivada do tenant e digest HMAC da sessão, aplicada
antes de revalidar o acesso:

- PortalSeoPgSessionAdvisoryLockLab: trava transacional PG de chave
  namespaceada, sem exigir UPDATE na role de callback
- PortalSeoPgOAuthSessionBoundLab: adquire lock antes de consultar
  sessão, MFA sintético, membership, recurso, grant e state OAuth
- PortalSeoPgSessionRevocationLab: role separada de escrita, com
  UPDATE apenas da coluna revoked_at e revogação de uso único
  sob o mesmo lock, sem token de cliente
- Teste com duas conexões PG18: enquanto a revogação mantém o lock,
  o callback encontra timeout e **não consome state OAuth**;
  depois que o revogador conclui, o callback é negado pela sessão
  revogada e o state continua sem consumo

Dados e credenciais são sintéticos e descartáveis. O F113 reexecuta
as suítes F108–F112 antes de testar essa contenção.

## Limitações críticas

**Advisory locks são cooperativos.** Código que escreva no mesmo
registro de sessão sem seguir este protocolo ainda pode criar
corridas. O serviço de revogação deste recorte não é uma API de
produção: não tem emissor de identidade, confirmação de MFA real,
autorização administrativa nem trilha completa de auditoria.

O papel SQL de callback continua somente leitura nas tabelas de
sessão, membership e recurso. A role de revogação do teste possui
permissão estrita UPDATE(revoked_at), mas exige uma revisão AppSec/
DBA antes de qualquer equivalente produtivo. Leitura RLS usando GUC
não substitui proteção contra SQL injection e autenticação real.

Este teste verifica contenção e timeout entre duas conexões,
mas não é teste de carga, não demonstra serializabilidade global
e não substitui revisão de deadlocks, lock starvation, pools e
cancelamento seguro de requisições.

Próximos gates: emissores Portal reais, MFA real, controle de acesso
por empresa, revogação confiável com auditoria, Google OAuth próprio
readonly e validação de propriedade efetiva, HTTPS/TLS verificado,
proteção de segredos, LGPD, staging com backup/rollback na VPS e
aceite de segurança. Nenhum dado de clientes ou API externa usados.

A metodologia Visitas → Leads → Clientes continua sem fabricar
conversões a partir de cliques ou impressões do Search Console.
