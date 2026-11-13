<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\PortalBrowserAuthGuard;
use Illuminate\Http\Request;
use Tests\TestCase;

final class PortalPrivateGatewayHeadersTest extends TestCase
{
    public function test_refused_requests_cannot_be_cached_embedded_or_indexed(): void
    {
        config(['app.url' => 'https://portal.example.test']);
        $request = Request::create('http://portal.example.test/_development/auth/session', 'GET');
        $forwarded = false;

        $response = (new PortalBrowserAuthGuard())->handle($request, function () use (&$forwarded) {
            $forwarded = true;
            return response('should-not-run');
        });

        self::assertFalse($forwarded);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertSame('noindex, nofollow, noarchive, nosnippet', $response->headers->get('X-Robots-Tag'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertSame('same-origin', $response->headers->get('Cross-Origin-Resource-Policy'));
    }
}
