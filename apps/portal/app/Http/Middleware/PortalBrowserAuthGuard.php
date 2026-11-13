<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Unwired transport guard. No test-mode or sibling-site CSRF bypass. */
final class PortalBrowserAuthGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = config('app.url');
        if (!is_string($origin) || !str_starts_with($origin, 'https://')
            || rtrim($origin, '/') !== $request->getSchemeAndHttpHost()
            || !$request->isSecure() || !$request->hasSession()) {
            return $this->refuse(403);
        }
        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            $token = $request->header('X-CSRF-TOKEN');
            $stored = $request->session()->token();
            $site = $request->header('Sec-Fetch-Site');
            if ($request->header('Origin') !== rtrim($origin, '/')
                || ($site !== null && $site !== 'same-origin')
                || !is_string($token) || !is_string($stored)
                || strlen($token) > 128 || !hash_equals($stored, $token)) {
                return $this->refuse(419);
            }
            if (!$request->isJson() || strlen($request->getContent()) > 4096) {
                return $this->refuse(400);
            }
        }

        return $this->protect($next($request));
    }

    private function refuse(int $status): Response
    {
        return $this->protect(response()->json(['error' => 'request_refused'], $status));
    }

    private function protect(Response $response): Response
    {
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
