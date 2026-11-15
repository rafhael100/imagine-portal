# F401 — Emissor original de comando Sites→CRM no Portal (somente CI)

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY / NO-PII / NO-CREDENCIAL REAL.**

Recorte isolado em apps/portal. Não altera Node legado, HTTP, rotas Laravel, pagamentos, base real, usuários, contratos ou servidores. A intenção é comprovar uma fonte independente de serialização do comando F400 em CI, sem permitir a aplicação operacional de nenhum evento.

- `app/Labs/SitesF401/PortalSitesCrmSignedBindingCommandLab.php`: emissor PHP puro do wire canonical `IMAGINE-F400-PORTAL-CRM-SITES-BINDING-CI-v1` com HMAC SHA256 de **chaves falsas públicas**. Vincula source/audience, ação provision/revoke, organização, pedido, binding, versão, nonce e validade.
- `tests/Contracts/f401-crm-binding-emitter-ci.php`: ensaio standalone com asserts negativos de mudança de destino, emissor, chave, ação, versão, tempo, IDs e campos excedentes.
- Teste CRM cruzado deve consultar uma **SHA fixa** desta branch para produzir o wire original Portal F401 e passar pelo validador CRM F400, estado owner-owned do CRM F399, captura original Sites F398 e revogação; sem serviços vivos.

**Limitação crítica:** assinatura com chave falsa em GitHub NÃO é IAM nem autenticação de serviços. A aprovação e o consentimento são fixtures sem valor jurídico; nenhum caminho para operação real é habilitado. A ativação exige mTLS, segredos em vault, autorização e auditoria de serviços, fonte real de pedido/PSP/LGPD, fila durável e revisão SEG/DBA.

Somente `apps/portal/` foi alterado, preservando os limites do AGENTS.md. A CI original do Portal não foi modificada; paridade cross-repo é responsabilidade de um workflow descartável separado no CRM.