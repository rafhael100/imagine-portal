# Sites P0 — expected Sandbox payment binding candidate

DRAFT / NO-MERGE / NO-CHARGE / NO-PUBLISH. This code does not complete operational reconciliation.

Base Portal759/975bb83. Existing official Asaas GET reader and strict settlement verifier are reused; no new customer/payment creation, POST, financial bypass or public route. Production API access is not enabled.

`PgSandboxInstallationBindingSource` reads an immutable expected-payment mapping in a new candidate namespace, scoped by native PostgreSQL session_user, expected Sandbox account, active/unexpired grant and append-only order revocation. Owner/superuser/BYPASSRLS/role-switch and existing/repeatable transactions deny. Runtime receives SELECT on bindings, not writes to bindings/authority/audit. PSP payment IDs are unique; mappings, revocations and audit cannot be updated/deleted by normal SQL operations. Do not migrate or reuse existing laboratory/CRM tables, databases, credentials or volumes.

The schema is NOT installed on the VPS and is not a Laravel auto-migration. A homologated native Portal order/offer/contract producer must create the expected mapping from an authenticated company/order. Browser data, webhook content and the PSP response cannot author the expected amount, tenant or product. An operator merely inserting a fixture is not proof of that producer's authority. No `paid` flag exists in this ledger. Current denying service bindings remain unchanged.

`SandboxSettlementFactory` is an explicit server bootstrap candidate, not a feature toggle. It requires the fixed mounted file `/run/secrets/asaas_sandbox_api_token`, no symlink, no group/world write/execute, no world read, bounded size. Owner-only or controlled group-read secret mount is required; never put token values in environment dumps, GitHub, command arguments or chat. The factory always selects the official Sandbox reader; there is no production parameter. The account reference and PDO/native company login must be resolved by a trusted server authority, never the browser. The factory is not registered in HTTP/application providers.

The settlement verifier now re-reads and strictly compares the expected binding AFTER the official payment read. Revoked/expired/changed mappings during the network request produce null evidence; no cached success fallback. Subsequent Sites admission still needs its own independently current authorization/revocation checks.

New CI uses PostgreSQL18 and two separate native logins for scope/immutability/revocation, plus a deliberately synthetic PSP callback to revoke during a read. This is a new persistence/security test, NOT an operational Asaas Sandbox payment. The existing settlement/parity regressions are run because that verifier changed. No provider request, credential or transaction is used in those tests.

Operational gates: approved native order/contract producer and customer identity; actual Sandbox account/token/customer/payment and independent GET reconciliation; mTLS principals/tenant grants and key revocation; integration with Sites admission/project/preview; authenticated human acceptance tied to exact release; independent revocation witness and approved encrypted offsite backup/restore/rollback. Keep default-deny until each is evidenced. No URL, payment reconciliation, funded project or client preview is claimed by this PR.

## Evidência executada — 16/11/2026

HEAD funcional **50f550f154420b875eb22b2e06c99e0871c7ba3a**. [CI43865341382](https://github.com/rafhael100/imagine-portal/actions/runs/43865341382), PHP8.3/8.4:19 asserções PostgreSQL18 sobre dois logins nativos, isolamento, owner/account negados, GUC sem autoridade, escrita runtime negada, mapeamento/pagamento imutável, vínculo expirado/revogado, revogação durante callback PSP e auditoria append-only; segredo montado ausente também nega factory. A resposta PSP desse cenário é sintética, nenhuma chamada de rede.

Regressões do verificador modificado:43 asserções F1-390/Ed25519 e15 de paridade por versão PHP, aprovadas. [CI43865341299](https://github.com/rafhael100/imagine-portal/actions/runs/43865341299) confirmou também o leitor GET-only/default-deny. Laravel8.3/8.4, Node22/24, OAuth offline, private-compose e isolated-preview CI aprovados na mesma HEAD. Houve12 check runs success e2 Node push duplicados cancelados, substituídos pelos Node22/24 aprovados; não declarar14 suítes exclusivas.

Falha inicial do serviço CI: aspas simples no argumento --health-cmd foram interpretadas incorretamente pelo runner Docker. Corrigido para aspas duplas; os testes executaram e passaram na HEAD indicada. O commit posterior desta evidência altera somente documentação.

A instalação e conciliação na VPS não foram executadas: nenhum token Asaas Sandbox foi disponibilizado por mecanismo aprovado, não existe neste candidato produtor nativo homologado de pedidos/contratos nem identidade/customer-authority operacional. Não ligar este ledger a fixtures de checkout para declarar compra verdadeira. A prova de primeira parcela D0 não implica política aprovada de liberação integral da implantação/parcelamento; esse vínculo também precisa ser validado antes de habilitar admissão operacional.
