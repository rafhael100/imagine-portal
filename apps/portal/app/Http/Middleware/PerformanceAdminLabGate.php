<?php
declare(strict_types=1);
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
final class PerformanceAdminLabGate
{
    public function handle(Request $request,Closure $next):Response
    {
        if(config('performance_admin.enabled')!==true||!app()->environment(['local','testing']))abort(404);
        $response=$next($request);
        $response->headers->set('Cache-Control','private, no-store');
        $response->headers->set('X-Frame-Options','DENY');
        $response->headers->set('X-Content-Type-Options','nosniff');
        $response->headers->set('Referrer-Policy','no-referrer');
        return $response;
    }
}
