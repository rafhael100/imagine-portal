<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\PortalAccess\Security\PortalAdminStagingSecurityPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin HTTP feature is dark by default. For any real staging/production
 * activation, a durable server-side session store and secure TLS cookie are
 * required. A feature toggle does not grant customer/tenant permissions.
 */
final class PortalAdminFeatureGate
{
    public function handle(Request $request,Closure $next):Response
    {
        if(config('portal_admin.enabled')!==true)abort(404);

        // CI alone bypasses this deployment policy for synthetic HTTP tests.
        // In all non-test contexts the only allowed target is restricted
        // staging, with an encrypted database session and TLS cookies.
        if(!app()->environment('testing') &&
           PortalAdminStagingSecurityPolicy::issues(
               app()->environment()
           )!==[])
            abort(503);

        $response=$next($request);
        $response->headers->set('Cache-Control','private, no-store');
        $response->headers->set('X-Robots-Tag','noindex, nofollow');
        $response->headers->set('X-Frame-Options','DENY');
        $response->headers->set('Referrer-Policy','no-referrer');
        return $response;
    }
}
