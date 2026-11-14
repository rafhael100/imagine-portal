# Portal P0 — Customer-scoped database rehearsal (NO-GO)

**Scope:** independent, synthetic PostgreSQL 18 instance of the *private* customer checkout. Not an IAM implementation or a production migration.

## Data flow under test

1. Imagine Comercial Astro, pinned to a reviewed SHA, presents four service CTAs. In a disposable CI copy, the links go only to the local Laravel preview.
2. Laravel registers an individual **fictional** account, creates a fictional order, simulates payment, persists a sample briefing, and shows the record in `Meus serviços`.
3. `CustomerPreviewStore` uses the session-derived **customer ID** (never a customer_id from POST) to create an HMAC claim using a separate runtime secret; it sets both claim and MAC with `set_config(...,true)` **inside a PostgreSQL transaction**.
4. FORCE RLS on `customer_orders` calls a `SECURITY DEFINER` SQL verifier which has access to a DB-owner-only key. Raw SQL without the matching MAC, a caller-made claim, or a wrong customer fails closed.
5. Administrator-only controller routes use a distinct signed `admin` claim to view synthetic orders. Client endpoints use the specific signed customer ID.

The standalone company SQL login **cannot read the verifier key**, and the SQL test explicitly attempts to forge a principal or query the secret. The browser scenario proves each permitted customer can complete its own journey. These are technical security controls **only in the disposable fixture**.

## Required CI evidence before accepting this PR

- Start a new PG18 container, apply `customer-schema.sql` **before** starting the fail-closed application, and confirm RLS/verification grants at runtime.
- Execute the existing Portal customer/admin, CSRF, login, payment and persisted briefing tests, including restart.
- Compile the actual Imagine Comercial Astro and click the four CTAs using Chromium. No public DNS mutation.
- After several synthetic checkout orders exist, execute `test_customer_scope.sql` as the restricted session login. The test must return zero orders both without a claim and under a spoofed GUC; the verifier secret must remain inaccessible.
- Obtain completed `success` status for the exact GitHub PR head SHA; queued/cancelled runs do **not** count as green.

## Existing VPS caveat — DO NOT UPGRADE IN PLACE

The prior private checkout was reportedly installed at `/srv/imagine/purchase-preview` with an earlier runtime and database. **This branch intentionally changes the required runtime secret structure and schema**. Copying its PHP/router into that running installation without a coordinated migration would cause unavailability; re-running `prepare.py` on the old `.private` path is deliberately refused.

Do **not** overwrite the live private worktree or database, recreate volumes, run `docker compose down -v`, modify Nginx/DNS or switch traffic as a side effect of this PR. Stage a new isolated test instance with independent credentials and data, complete CI and operator smoke, then separately review an explicit migration/rollback plan. The earlier user-visible checkout must remain accessible and unchanged.

## Remaining release gates

- Raw `customers` table still grants SELECT to the synthetic tenant login, so this is **not complete individual data isolation**.
- No real IAM/MFA, human customer identity, signed legal contract, PSP payment validation, provider billing or invoicing.
- No independently operated S2S identity/contract/revocation source, approved Sites admission/CRM provision, external backups/restore, or approved production migrations.
- No independent AppSec/DBA/LGPD approval, monitored VPS handover, DNS or Cloudflare release.

**Release decision:** DRAFT / NO-GO. This lab can never charge a person, create an active service, or publish a site.
