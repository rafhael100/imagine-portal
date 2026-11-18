# Native Onepage entry payment and Sites command bridge

This composition uses Portal #940 (`1b394f9826f623dec02beffcd2528b6a17d0c51e`)
and selectively imports the binding, official-reader, settlement, contract,
revocation and CreateProject/Ed25519 interfaces from #901
(`5d88826d0f8a83455f11b44dbfd66d5b462ce267`). The imported verifier and issuer
are unchanged. No old Homologation class or catalog is imported.

## Commercial meaning

Onepage setup is R$990 in three separately scheduled R$330 obligations on
D0/D30/D60. The first confirmed entry enables the initial briefing/production
condition; it does not settle the other two obligations. The mandatory R$79.90
monthly subscription starts at D90 from acceptance, independently of publication.
Client acceptance of the exact site version remains a separate publication gate.

The accepted, server-published native terms must explicitly include
`onepage_payment_policy`, version `onepage-d0-d90-v1`. Its required shape is
`OnepageEntryPaymentTerms::requiredPolicy()`: the approved setup/monthly schedule,
`entry_installment_key=setup-1`, `briefing_after_entry_confirmation=true`, and
`publication_requires_separate_acceptance=true`. This helper does not publish or
accept terms. Missing terms, a different catalog version or an illustrative quote
alone fail closed. The SQL policy pins the current 99000/33000/7990 cent amounts;
future policy versions require a reviewed producer change and accepted terms.

The strict existing Asaas verifier still requires `RECEIVED`, the bound customer,
payment ID, reference, billing type and exact amount, without reversal/refund or
subscription. Synthetic `confirmed` and provider `CONFIRMED` are not silently
converted to that evidence. Financial arrears, service continuation, client
approval and publication are not decided by this binding adapter.

## Native sources and identity

`OnepageEntryPaymentBindingSource` consumes the actual `OnepageOrderReader` and
`OnepageAcceptedOrder` from the native order store. Each read revalidates the
native buyer, session/MFA, company and accepted publication. It cross-checks the
existing `PortalSiteContractSource` and expected-payment source; both remain
denying defaults when absent.

CreateProject v1 `tenantRef` is the Portal organization's `companyReference`
(`portal_org_ref` on the Sites side), not either product's internal company/tenant
UUID. The Sites receiver must independently resolve its internal tenant through
its native database login and compare the registered `portal_org_ref`.

`OnepageEntryPaymentTerms::reference()` binds the setup line to the accepted order,
quote, company UUID and external reference, buyer, publication, contract/revision,
catalog/offer/terms/contract digests, acceptance instant, account, customer,
due date and amount. `setup-2` therefore cannot substitute for `setup-1` even
though both cost R$330. Native JSONB digests are preserved; PHP does not claim
that `json_encode()` reproduces PostgreSQL `JSONB::text`.

## Expected-payment persistence

`database/security/portal_onepage_entry_payments.sql` is applied after the native
order schema only in an explicitly prepared environment. Nothing installs it
automatically. It creates empty ledgers and NOLOGIN roles:

- `portal_onepage_runtime` may execute an own-order read, with current native
  session and publication checks. It cannot write or inspect the tables.
- `portal_onepage_psp_registrar` may register a trusted creation receipt's customer
  and entry identifiers, or append a binding revocation. It cannot enroll accounts,
  modify orders, change records, assume the owner role or mark a payment received.
- `portal_onepage_payment_owner` is a non-login function owner with only the table
  and function privileges needed by these bounded operations.

An operator-enrolled, active Sandbox account is required. No account, customer,
payment, credential or user is seeded by the schema. Customer association derives
the company from the accepted order. Entry registration derives all order terms
from that same immutable native row and the still-current published contract.
The registrar supplies PSP identifiers/reference/value/date, which must match the
derived expectation. No company, tenant, contract or acceptance timestamp is taken
from a buyer request. Registration returns `recorded`, `replay` or `denied`; none
means paid. The runtime source and registrar require distinct restricted native
logins, the expected database, READ COMMITTED and no pre-existing transaction.

`PostgresOnepageEntryPayments(PDO, database, sandboxAccount, businessReference?)`
provides `registerCustomer`, `registerEntry`, `revokeEntry` and
`findInstallationPayment`. The reader can be injected into the entry adapter,
which then supplies `AsaasVerifiedSettlementSource`. That verifier re-reads the
entire adapter after the official GET, so native session/publication loss, a
revoked binding, an inactive account or a changed context invalidates the result.

`SitesCreateProjectEncoder` and `PortalEd25519EntitlementIssuer` keep their existing
wire contracts. A positive entry read cannot replace independent contract and
revocation authority, and no `paid` or `canPublish` flag is added to the command.
No service provider, public route, external integration or deployment is enabled.

## Verification

Run the actual PHP classes without any provider access:

```sh
php apps/portal/tests/Unit/OnepageEntryPaymentBindingStandalone.php
GITHUB_ACTIONS=true PORTAL_F390_ASAAS_CI=1 php tests/f390-asaas-official-settlement.php
GITHUB_ACTIONS=true PORTAL_P0_CHECKOUT_ASAAS_PARITY_CI=1 php tests/p0-checkout-asaas-proof-parity.php
```

The pre-existing parity test uses the current approved Performance floor of R$170
for Meta's R$700 minimum; importing the obsolete R$140 expectation would regress
the current catalog. Its Onepage assertions remain 99000 total/33000 entry/7990 D90.

`OnepageEntryPaymentsPgAssertions` extends `NativeOnepageOrdersPgTest` in the same
disposable PG18 database and with its one real native password/TOTP session and
accepted order. It tests empty defaults, permission boundaries, PHP/SQL reference
parity, setup-2 and changed-context denial, customer binding, atomic rollback,
durable replay, immutability, fresh-read isolation, and revocation during the
verifier's read. The PSP response in this integration test is explicitly synthetic.
Neither this test nor passing CI proves a live Sandbox payment or VPS deployment.
