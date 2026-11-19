# Onepage current head — private Portal response candidate (2026-11-19)

This draft is based exactly on [Portal #976](https://github.com/rafhael100/imagine-portal/pull/976) HEAD `89e309b497137cec4960b87f90a49d00bbf92843`; the cross-repo consumer is the immutable [Sites #551](https://github.com/rafhael100/imagine-sites/pull/551) candidate at `49e6f0a8da848ebf50b6f507d2d3cec6ae65dc97`. Branches, runtime providers, production, VPS and previous PRs are unchanged.

## Implemented contract

`OnepageCurrentHeadS2sResponseCandidate` is a side-effect-free composer for the *proposed* `POST /internal/sites/v1/brief-head/current` response. Separate interfaces require (1) S2S identity authorization for tenant/order/site, (2) current approved head (a latest/revocation-aware official read), (3) independently authorized current contract, (4) exact immutable source-version record and (5) independent live approval witness. The defaults for the new current-head source and service identity **always deny**.

The composer obtains the eleven-field response ONLY from those injected trusted sources, using the same original source bytes and canonical manifest encoder already in Portal #976. It double-reads identity authorization, contract, head, exact source and witness around export. A changed, revoked, foreign, superseded or inconsistent source is refused without disclosing customer content. The response contains no bytes, session, approval token, grants or publish flags.

The draft-only CI uses PHP 8.3/8.4 and the **actual parser from Sites #551** pinned to an immutable commit, along with previous Portal encoder/approval tests. All source implementations in tests are fake, with negative tests for tenant, service, product, approval revocation and read races. This is contract and interop validation, **not** proof of official Portal data or secure HTTP transport.

## Blocking dependencies

There is **no live authenticated route, latest approved briefing ledger, authorization middleware or S2S trusted principal provider**. A string passed as `authenticatedServiceRef` by itself is NOT identity. The future HTTP endpoint must derive it from verified mTLS, enforce scoped service ACL and replay constraints and run an authoritative transaction or CAS/epoch; repeated reads alone do not guarantee atomicity. Payment, reviewer approval, Landing Page, technical publish, acceptance, and all ten operational gates remain blocked. **DRAFT / NO-MERGE / NO-DEPLOY / NO-GO**, no Cloudflare, DNS, Asaas, creds, main or VPS modifications.
