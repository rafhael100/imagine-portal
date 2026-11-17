# Preserve Comercial context in the V5 private preview

The V5 composition at `26075ce0b409a32419bc2919270d4e25cb609772` preserved the
selected product but dropped its optional Google/Meta channel when registration
or login rotated the session. The Comercial CTAs already include that channel,
so the user lost the explanation of where they entered the purchase flow.

This ports only the contextual helper and its small controller/view integrations
from `3f7750daf4892050ae350b43d3f1e48f75023864`. The helper accepts only Onepage,
Landing Page and Performance; a channel is allowed only for Performance and
only for Google Ads or Meta Ads. New selections clear older context. Invalid
or tampered hints cannot become external redirects.

The controllers capture the validated product/channel before session rotation
and rebuild an internal shop URL afterwards. The purchase view displays one
literal, allowlisted notice. No media budget is filled automatically, and the
hint is not an order field, price, grant, payment or legal acceptance.

The V5 catalog and quote logic are unchanged. The port does not import the
historical prepaid Performance pricing or enable Landing/Performance Sandbox
checkout from the source branch. Existing authentication and customer ownership
checks are also unchanged.

## Evidence

`CustomerPreviewV5HandoffTest` exercises the actual controllers, Laravel
Request/session rotation and Blade rendering. Its in-memory login/registration
fixture proves context handling only; it does not stand in for authentication
or PostgreSQL evidence.

The dedicated CI copies the same focused test into an isolated archive of the
parent. It requires exactly four Google/Meta redirect assertion failures, with
four site controls passing, before running the complete test against the fixed
candidate. The normal Laravel matrix also runs the test.

Actual Comercial HTML → Chromium → Portal HTTP → PostgreSQL verification is
performed by the separate Comercial V5 integration candidate pinned to this
Portal change. That proof retains all earlier independent pins and verifies
the V5 offer snapshots, two distinct owners and zero provider payment intents.
Executed results and exact-SHA links belong in the PRs after CI completes.

This remains a private preview code change in a draft PR. It does not authorize
merge, publication, real registration, deployment or payment processing.
