# Landing Page — approved current briefing head boundary (draft)

Chain: Sites #541/#542 → Portal #1010 → Sites #555 → this isolated Portal branch.

This adds an **internal** immutable briefing-head value with original JSON source bytes,
SHA-256, exact tenant/order/site/contract/revision, source version, approval reference
and epoch. The new independent reader defaults to DENY and must ultimately obtain
approval, owner, version and revocation **atomically from official Portal data**.

`LandingPageCurrentApprovedBriefGate` revalidates service principal, independent
tenant/order/site custody and the approved current head twice. Wrong tenant/site,
contract/version churn, missing approval, revoked service, missing binding and
changed bytes are denied with one redacted error. Source bytes must never be
serialized as JSON, PHP object wire, debug text, CI log or GitHub fixture.

The explicit `LandingPagePrivateBriefEnvelopeCandidate` may encode those original
bytes only at a future authenticated private transport boundary. This **candidate**
wire shape is not authority; Sites must validate selectors, approval epoch and
exact source version against independently trusted state before use.

**Not operational:** the test source is synthetic; no Postgres approval ledger,
authenticated reviewer, mTLS configuration, route, payment settlement, renderer,
release, technical publication or customer acceptance exists here. Two reads are
not an atomic transaction; ABA can occur. This DTO is not an authorization token
and SHALL NOT be plugged into HTTP until official transaction, service identity,
privacy and audit evidence are homologated.

Draft only. No merges or changes to main/VPS/Cloudflare/DNS/production/Asaas,
credentials or suppliers. Ten operational gates stay BLOCKED.
