# Landing Page — independent tenant/order/site binding (draft, 20/11/2026)

Chain: Sites #541/#542 → Portal #1008 → Sites #554 → this PR.

An authorized **order** is not proof of an authorized **site**. The prior Landing Page
admission consulted the contract only by orderRef; it could project a different
siteRef if a peer witness were broadly configured. The admission now denies unless
an independent Portal source returns a binding that matches all of
tenantRef, orderRef, siteRef, productCode=landing_page, contractRef and contractRevision.
It verifies the binding twice around another service-witness and contract read.

Missing/mutated/foreign bindings, site swaps, stale revisions, provider failures and
unauthorized peers return a single generic error without revealing private data.
The default binding provider denies. The output six-field contract is unchanged,
so Sites consumer still expects a separately established contract reference/revision.

**Limit:** Tests use synthetic witnesses only. No official transactional site-binding
provider, current approved/versioned brief, durable revocation/audit, real mTLS,
endpoint, consent, payment, renderer, publication or acceptance is supplied.
Two readbacks are not a transaction and do not prevent an ABA race. This change
cannot justify production GO. All ten operational gates remain blocked; no main,
VPS, DNS/Cloudflare, Asaas, production, merge, deploy, secrets or suppliers altered.
