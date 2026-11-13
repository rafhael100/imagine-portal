<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Infrastructure\Recovery\PortalWitnessOnlyIdentityRead;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * F382 -- review-only HTTP enforcement proof: NEVER call the next handler.
 *
 * A fresh Ed25519 witness observation, even if its SQL read is positive,
 * remains a laboratory observation. The authoritative off-host serving
 * fence, independent key custody and transactional revocation are NOT ready.
 *
 * This middleware is intentionally NOT registered as a route, alias, global
 * middleware, or container binding. All endpoints using it return 503.
 */
final readonly class PortalWitnessNoServingHttpGuard
{
    private const STATE = 'portal.browser_auth';

    public function __construct(private PortalWitnessOnlyIdentityRead $witness)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // No query-string, body, cookie value or header can supply a raw SQL
        // session token to this observer. Only the server-side encrypted
        // Laravel session is inspected, and no observation becomes authority.
        try {
            if ($request->hasSession()) {
                $state = $request->session()->get(self::STATE);
                if (is_array($state)
                    && ($state['phase'] ?? null) === 'business'
                    && is_string($state['reference'] ?? null)
                    && preg_match('/\A[a-f0-9]{64}\z/D', $state['reference']) === 1) {
                    // Deliberately discard even LAB_CHALLENGED_READ_STILL_NO_SERVING.
                    // The observer can fail/throw; never fall back or cache.
                    $this->witness->inspectBusinessSession($state['reference']);
                }
            }
        } catch (Throwable) {
            // Never propagate database, network, signature or tenant details.
        }

        // There is no 200/201/202/204, redirect, or $next() path.
        // Public routes are still absent. This is not a deploy-ready guard.
        $response = response()->json(['error' => 'portal_not_ready'], 503);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        return $response;
    }
}
