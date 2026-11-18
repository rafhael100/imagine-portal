<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\Response;

/** Presentation only: never asserts a settlement, an intake or publication. */
final class OnepageClientPageController
{
    private const POLICY = "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'";

    public function index(): Response
    {
        return response()->view('portal.commerce.onepage')->withHeaders([
            'Content-Security-Policy' => self::POLICY,
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        ]);
    }

    public function stylesheet(): Response
    {
        return response()->file(resource_path('onepage-client/app.css'), [
            'Content-Type' => 'text/css; charset=utf-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function script(): Response
    {
        return response()->file(resource_path('onepage-client/app.js'), [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
