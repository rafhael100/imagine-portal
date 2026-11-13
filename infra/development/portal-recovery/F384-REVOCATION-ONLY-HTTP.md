# F384 — Permit revocation-only HTTP, never serving, when external witness is unavailable

F382 and F383 correctly prove login/MFA/session/resource responses cannot be authorized using a laboratory Ed25519 witness result, but **their intentionally deny-everything middleware also blocks logout**. This is not safe as an eventual operational policy: a user must remain able to *revoke* a session during an external witness outage, while the Portal must still deny login/serving.

F384 introduces the separate **unregistered** `PortalRevocationOnlyNoServingGuard` class as an experimental narrow exit. It will call the next Laravel handler **only** if all are true:
1. Request is HTTPS and has the exact configured origin, same-origin browser site signal, JSON POST, server-side Laravel session and valid CSRF token;
2. The **server-side route** is named `portal.f384.revocation-only`, exposes the single POST method and its action is **exactly** `PortalBrowserAuthController@logout`.
3. No user-supplied path, method override, cookie, role, header or body may select a different action.

For any other route, it returns private no-store **503 portal_not_ready**. On the allowed logout route, the existing controller calls the actual `PortalBrowserAuthentication::revoke` method and invalidates the browser envelope. A synthetic unit test asserts successful revoke leads to 204 without **ever calling the witness**. Failed durable revocation leads to **503 authentication_unavailable**, not false success. Cross-origin/CSRF spoofing is rejected by the existing `PortalBrowserAuthGuard` and by F384's own extra checks. An impostor route with the same name but a different controller, or the correct controller over GET, remains denied.

PHPUnit registers all routes only in memory; **nothing is added** to the real `routes/web.php`, bootstrap or provider. The default application still exposes just /up. CI runs the actual Laravel middleware, HTTP controller and CSRF tests on PHP 8.3 and 8.4.

## Important unresolved security issue

The controller currently invalidates the browser envelope on **failed** durable SQL revoke. This protects the immediate browser but leaves a possible active backend reference without a retry/outbox. Production would require durable revocation reconciliation and external witness-first publication under independent custody (and a reviewed CSRF/revocation rate-limit protocol) **before** allowing this exception. A success from a synthetic test double does not prove production SQL durability. The witness is not truly external or highly available yet.

No real route, public logout endpoint, auth credential, Cloudflare Worker, D1, production database, VPS, DNS, merge, deploy or new expense was touched. PR review-only; security issue #64 remains OPEN/NO-GO.
