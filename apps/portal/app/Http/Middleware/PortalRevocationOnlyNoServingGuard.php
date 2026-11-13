<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\PortalBrowserAuthController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * F384 review-only: permit exactly one durable, CSRF-protected session
 * REVOCATION action during independent-witness outage.
 *
 * No public route/container alias/global binding. NEVER make a business,
 * auth, MFA, checkout, invitation or protected-resource action callable.
 * Browser envelope is invalidated by PortalBrowserAuthController::logout.
 */
final class PortalRevocationOnlyNoServingGuard
{
    private const ROUTE_NAME = 'portal.f384.revocation-only';
    private const ACTION = PortalBrowserAuthController::class.'@logout';

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $origin = config('app.url');
        $expected = is_string($origin) ? rtrim($origin,'/') : '';

        // Route name and controller method are controlled by the Laravel
        // server-side route table, NEVER a request-provided path/header.
        // Gate also independently confirms same-origin + CSRF because
        // accidentally mounting without PortalBrowserAuthGuard is unsafe.
        $allow = $route !== null
            && $route->getName() === self::ROUTE_NAME
            && $route->getActionName() === self::ACTION
            && $route->methods() === ['POST']
            && $request->method() === 'POST'
            && is_string($origin)
            && str_starts_with($origin,'https://')
            && $request->isSecure()
            && $request->getSchemeAndHttpHost() === $expected
            && $request->header('Origin') === $expected
            && in_array($request->header('Sec-Fetch-Site'),[null,'same-origin'],true)
            && $request->hasSession()
            && is_string($request->header('X-CSRF-TOKEN'))
            && is_string($request->session()->token())
            && strlen((string)$request->header('X-CSRF-TOKEN')) <= 128
            && hash_equals(
                (string)$request->session()->token(),
                (string)$request->header('X-CSRF-TOKEN'),
            )
            && $request->isJson()
            && strlen($request->getContent()) <= 4096;

        if ($allow) {
            // The ONLY exception to denial is the existing logout action,
            // whose sole security effect is durable revocation and session
            // clearing. Controller returns 204 only after SQL revoke success;
            // errors must remain unavailable, never a false success.
            return $next($request);
        }

        $response = response()->json(['error'=>'portal_not_ready'],503);
        $response->headers->set('Cache-Control','no-store, private');
        $response->headers->set('Pragma','no-cache');
        $response->headers->set('X-Content-Type-Options','nosniff');
        $response->headers->set('Referrer-Policy','no-referrer');
        $response->headers->set('X-Robots-Tag','noindex, nofollow, noarchive, nosnippet');
        $response->headers->set('X-Frame-Options','DENY');
        $response->headers->set('Cross-Origin-Resource-Policy','same-origin');
        return $response;
    }
}
