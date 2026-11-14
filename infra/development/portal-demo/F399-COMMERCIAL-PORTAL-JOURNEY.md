# F399 — Customer purchase-to-Portal experience, static/offline review only

The user needs to understand how an Imagine customer will move from the commercial website to seeing services and information in the Imagine Portal. Until security gate #64 passes, **real checkout, customer authentication, invoice/payment APIs and tenant-serving permissions cannot be enabled**.

F399 adds an explicit **four-stage, clickable HTML-only review** to the existing local-only 11-page Imagine Portal demo export, without changing the page count or enabling public routes:

1. **Conhecer os serviços no site Imagine** → existing `available.html` synthetic service catalog.
2. **Revisar o pedido ilustrativo** → existing `requests.html` synthetic requests.
3. **Entrar no espaço do cliente fictício** → existing `overview.html`, clearly marked as not a login or customer account.
4. **Acompanhar serviços e contratos de exemplo** → existing `services.html` visual preview, with a separate existing `contracts.html` reached through offline navigation.

`DemoCommercialPortalJourney` returns only hardcoded title/description/local-path tuples. Four separate privilege/side-effect indicators return false; it never accesses commerce, CRM, SQL, sessions, payments or customer data. `DemoPortalStaticExporter` verifies every link against the existing signed manifest's preapproved filenames and refuses repeated/unapproved paths before writing. `DemoPortalNavigationAudit` continues to verify every generated page's current-page indicator, noindex/CSP, image embedding, known local URLs, zero active forms/scripts and no external links.

In the exported `index.html` the commercial-to-Portal review appears before the existing detailed ten-screen walkthrough, so a reviewer can click through the four principal screens first and then examine contracts, notices, modules, service journeys and technical delivery reviews separately. F399 adds no new HTML page, no JavaScript application, no route, no customer entitlement and no checkout behavior. Existing original Imagine branding F398 remains a dependency.

CI `laravel-f1-01-a.yml` runs the full PHPUnit tests on PHP 8.3/8.4; new `DemoPortalStaticExporterTest` assertions inspect the exact static HTML DOM and confirm only four known local links, all target files exist, eleven generated pages, no active inputs/forms/scripts, and false authorizations.

**Outstanding operational integration**: real imagine-comercial checkout/payment + issuer webhook, idempotent customer/account provisioning, tenant binding, session MFA, signed order receipts, roles, reconciliation and revocation witness + live HTTP fences. None is implemented or enabled by this PR. GitHub branches remain review-only, issue #64 OPEN/NO-GO, no main merge, VPS/Cloudflare deployment, DNS, real users or new expense.
