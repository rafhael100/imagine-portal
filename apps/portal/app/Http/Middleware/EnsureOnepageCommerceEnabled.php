<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Recheck private activation even when a long-lived worker has cached routes. */
final class EnsureOnepageCommerceEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('portal_onepage.enabled') !== true
            || config('portal_development_auth.enabled') !== true
            || config('app.env') !== 'staging' || config('app.debug') !== false
            || config('app.url') !== 'https://portal-dev.imaginecomunica.com.br'
            || config('session.driver') !== 'portal_private_pg'
            || config('session.cookie') !== '__Host-imagine-portal-session'
            || config('session.secure') !== true || config('session.http_only') !== true
            || config('session.domain') !== null || config('session.path') !== '/'
            || config('session.serialization') !== 'json'
            || !in_array(config('session.same_site'), ['lax', 'strict'], true)
            || app()->routesAreCached() || app()->configurationIsCached()) {
            return $this->refuse('not_found', 404);
        }
        try {
            return $next($request);
        } catch (TokenMismatchException) {
            return $this->refuse('request_refused', 419);
        } catch (Throwable) {
            // Store construction can fail before the controller catches an
            // operation error. Preserve the private, neutral boundary there too.
            return $this->refuse('order_unavailable', 503);
        }
    }

    private function refuse(string $error, int $status): Response
    {
        return response()->json(['error' => $error], $status)->withHeaders([
            'Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive, nosnippet',
            'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY', 'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }
}
