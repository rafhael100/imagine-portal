<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Recheck activation on every request, including long-lived/cached routers. */
final class EnsurePortalDevelopmentAuthEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('portal_development_auth.enabled')!==true
            ||config('app.env')!=='staging'||config('app.debug')!==false
            ||config('app.url')!=='https://portal-dev.imaginecomunica.com.br') {
            return response()->json(['error'=>'not_found'],404)
                ->header('Cache-Control','no-store, private');
        }
        return $next($request);
    }
}
