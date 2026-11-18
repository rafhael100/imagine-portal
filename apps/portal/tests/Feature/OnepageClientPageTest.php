<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\OnepageClientPageController;
use App\Http\Middleware\EnsureOnepageCommerceEnabled;
use App\Http\Middleware\PortalBrowserAuthGuard;
use App\Providers\OnepageCommerceServiceProvider;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Exercises Blade/assets via HTTP; external payment/Sites capabilities remain disabled. */
final class OnepageClientPageTest extends TestCase
{
    private const ORIGIN = 'https://portal.example.test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => self::ORIGIN, 'session.driver' => 'array',
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        Route::prefix('__onepage-client-test')
            ->middleware(['web', PortalBrowserAuthGuard::class])
            ->group(static function (): void {
                Route::get('/', [OnepageClientPageController::class, 'index']);
                Route::get('client.css', [OnepageClientPageController::class, 'stylesheet']);
                Route::get('client.js', [OnepageClientPageController::class, 'script']);
            });
    }

    public function test_private_page_renders_without_embedded_authority_or_inline_scripts(): void
    {
        $this->withSession(['_token' => str_repeat('t', 40),
            'portal.browser_auth' => ['phase' => 'business', 'reference' => str_repeat('b', 64)]]);
        $response = $this->get(self::ORIGIN.'/__onepage-client-test');
        $response->assertOk()->assertSee('Minha Onepage')->assertSee('Aguardando confirmação oficial');
        $html = $response->getContent();
        self::assertStringContainsString('id="loading"', $html);
        self::assertStringContainsString('id="order"', $html);
        self::assertStringContainsString('client.js" defer', $html);
        self::assertStringNotContainsString(str_repeat('b', 64), $html);
        self::assertStringNotContainsString('name="company"', $html);
        self::assertStringNotContainsString('name="payment"', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertSame("default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'",
            $response->headers->get('Content-Security-Policy'));
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow, noarchive, nosnippet', $response->headers->get('X-Robots-Tag'));
    }

    public function test_assets_are_private_same_origin_and_never_share_payment_claims(): void
    {
        $this->withSession(['_token' => str_repeat('t', 40)]);
        foreach (['client.css' => 'text/css; charset=utf-8',
            'client.js' => 'text/javascript; charset=utf-8'] as $path => $type) {
            $response = $this->get(self::ORIGIN.'/__onepage-client-test/'.$path);
            $response->assertOk();
            self::assertSame($type, $response->headers->get('Content-Type'));
            self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
            self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        }
        $script = file_get_contents(resource_path('onepage-client/app.js'));
        self::assertIsString($script);
        self::assertStringContainsString("auth + 'session'", $script);
        self::assertStringContainsString("auth + 'csrf'", $script);
        self::assertStringContainsString("credentials:'same-origin'", $script);
        self::assertStringContainsString("redirect:'error'", $script);
        self::assertStringContainsString("textContent", $script);
        self::assertStringNotContainsString('innerHTML', $script);
        self::assertStringNotContainsString('localStorage', $script);
        self::assertStringNotContainsString('payment_confirmed', $script);
        self::assertStringNotContainsString('site_publish', $script);
    }

    public function test_mismatched_origin_is_refused_before_assets_or_page(): void
    {
        $this->withSession(['_token' => str_repeat('t', 40)]);
        $response = $this->get('https://sibling.example.test/__onepage-client-test');
        $response->assertForbidden()->assertExactJson(['error' => 'request_refused']);
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    public function test_product_routes_and_assets_use_the_same_private_activation_gates(): void
    {
        $target = '_development/commerce/onepage';
        foreach (Route::getRoutes() as $route) {
            self::assertFalse(str_starts_with($route->uri(), $target));
        }
        config([
            'portal_onepage.enabled' => true,
            'portal_development_auth.enabled' => true,
            'app.env' => 'staging', 'app.debug' => false,
            'app.url' => 'https://portal-dev.imaginecomunica.com.br',
            'session.driver' => 'portal_private_pg',
            'session.cookie' => '__Host-imagine-portal-session',
            'session.secure' => true, 'session.http_only' => true,
            'session.domain' => null, 'session.path' => '/',
            'session.serialization' => 'json', 'session.same_site' => 'lax',
        ]);
        (new OnepageCommerceServiceProvider($this->app))->boot();
        $found = [];
        foreach (Route::getRoutes() as $route) {
            if (!str_starts_with($route->uri(), $target)) continue;
            $found[$route->uri()] = $route->gatherMiddleware();
        }
        foreach ([$target, $target.'/client.js', $target.'/client.css',
            $target.'/quotes', $target.'/orders/{orderId}'] as $uri) {
            self::assertArrayHasKey($uri, $found);
            self::assertContains(EnsureOnepageCommerceEnabled::class, $found[$uri]);
            self::assertContains(PortalBrowserAuthGuard::class, $found[$uri]);
            self::assertContains('web', $found[$uri]);
        }
    }
}
