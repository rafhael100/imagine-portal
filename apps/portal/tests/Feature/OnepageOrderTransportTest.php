<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Commerce\OnepageAcceptedOrder;
use App\Domain\Commerce\OnepageOrderStore;
use App\Http\Controllers\OnepageOrderController;
use App\Http\Middleware\EnsureOnepageCommerceEnabled;
use App\Http\Middleware\PortalBrowserAuthGuard;
use App\Providers\OnepageCommerceServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/** HTTP/CSRF behavior with a store double; native identity/SQL have separate PG tests. */
final class OnepageOrderTransportTest extends TestCase
{
    private const ORIGIN = 'https://portal.example.test';
    private const QUOTE = '11111111-1111-4111-8111-111111111111';
    private const ORDER = '22222222-2222-4222-8222-222222222222';
    private OnepageTransportStore $store;
    private string $phase = 'business';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => self::ORIGIN, 'session.driver' => 'array',
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        // Resolve the response factory while this transport-only test uses its
        // array session driver; the activation test does not install PG storage.
        response();
        $this->store = new OnepageTransportStore();
        $this->app->instance(OnepageOrderStore::class, $this->store);
        Route::prefix('__onepage')->middleware(['web', PortalBrowserAuthGuard::class])->group(static function (): void {
            Route::post('quotes', [OnepageOrderController::class, 'create']);
            Route::get('quotes/{quoteId}', [OnepageOrderController::class, 'resume']);
            Route::get('quotes/{quoteId}/review', [OnepageOrderController::class, 'review']);
            Route::post('quotes/{quoteId}/accept', [OnepageOrderController::class, 'accept']);
            Route::get('orders/{orderId}', [OnepageOrderController::class, 'show']);
        });
    }

    private function send(string $method, string $path, array $body = [], array $server = []): TestResponse
    {
        $this->withSession(['_token' => str_repeat('t', 40),
            'portal.browser_auth' => ['phase' => $this->phase, 'reference' => str_repeat('b', 64)]]);
        $response = $this->call($method, self::ORIGIN.'/__onepage/'.$path, [], [], [], array_replace([
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => self::ORIGIN, 'HTTP_SEC_FETCH_SITE' => 'same-origin',
            'HTTP_X_CSRF_TOKEN' => str_repeat('t', 40),
        ], $server), json_encode($body, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow, noarchive, nosnippet', $response->headers->get('X-Robots-Tag'));
        self::assertStringNotContainsString(str_repeat('b', 64), $response->getContent());
        return $response;
    }

    private function selection(): array
    {
        return ['request_key' => 'request-one', 'selection' => ['site' => 'onepage', 'channels' => [], 'tier' => 'recommended']];
    }

    public function test_current_server_session_is_used_for_create_review_accept_and_read(): void
    {
        $this->send('POST', 'quotes', $this->selection())->assertOk()->assertJsonPath('status', 'draft')
            ->assertJsonMissingPath('company')->assertJsonMissingPath('buyer');
        self::assertSame(str_repeat('b', 64), $this->store->calls[0][1]);
        self::assertSame($this->selection()['selection'], $this->store->calls[0][3]);
        $this->send('GET', 'quotes/'.self::QUOTE)->assertOk();
        $review = $this->send('GET', 'quotes/'.self::QUOTE.'/review')->assertOk()->json('review');
        self::assertArrayNotHasKey('company', $review);
        self::assertArrayNotHasKey('buyer', $review);
        self::assertArrayNotHasKey('company_reference', $review);
        $acceptance = array_intersect_key($review, array_flip(['quote_id', 'offer_digest', 'terms_digest',
            'contract_ref', 'contract_revision', 'contract_digest']));
        $acceptance['accepted'] = true;
        $this->send('POST', 'quotes/'.self::QUOTE.'/accept', ['request_key' => 'accept-one', 'acceptance' => $acceptance])
            ->assertOk()->assertJsonPath('status', 'pending_payment');
        $this->send('GET', 'orders/'.self::ORDER)->assertOk()->assertJsonPath('order.id', self::ORDER)
            ->assertJsonPath('order.contract_ref', 'contract-onepage-2026-v1')
            ->assertJsonPath('order.product', 'onepage');
    }

    public function test_pending_mfa_and_missing_business_phase_never_reach_store(): void
    {
        foreach (['pending_mfa', '', 'revoked'] as $phase) {
            $this->phase = $phase;
            $this->send('POST', 'quotes', $this->selection())->assertUnauthorized();
            $this->send('GET', 'orders/'.self::ORDER)->assertUnauthorized();
        }
        self::assertSame([], $this->store->calls);
    }

    public function test_browser_cannot_supply_identity_price_or_arbitrary_top_level_fields(): void
    {
        foreach (['company', 'buyer', 'business_reference', 'paid', 'amount_cents'] as $key) {
            $this->send('POST', 'quotes', $this->selection() + [$key => 'untrusted'])->assertStatus(422);
        }
        $this->send('POST', 'quotes', ['request_key' => [], 'selection' => []])->assertStatus(422);
        $this->send('GET', 'quotes/not-a-uuid')->assertNotFound();
        $this->send('GET', 'orders/'.self::ORDER.'?company=other')->assertNotFound();
        self::assertSame([], $this->store->calls);
    }

    public function test_origin_csrf_and_payload_guards_remain_active_in_tests(): void
    {
        foreach ([['HTTP_ORIGIN' => 'https://sibling.example.test'], ['HTTP_X_CSRF_TOKEN' => 'wrong'],
            ['HTTP_SEC_FETCH_SITE' => 'cross-site']] as $server) {
            $this->send('POST', 'quotes', $this->selection(), $server)->assertStatus(419);
        }
        $this->send('POST', 'quotes', ['request_key' => str_repeat('x', 5000)], [])->assertStatus(400);
        self::assertSame([], $this->store->calls);
    }

    public function test_store_denials_are_neutral_and_errors_do_not_expose_database_diagnostics(): void
    {
        $this->store->mode = 'denied';
        $this->send('POST', 'quotes', $this->selection())->assertNotFound()->assertExactJson(['error' => 'not_found']);
        $this->send('GET', 'orders/'.self::ORDER)->assertNotFound()->assertExactJson(['error' => 'not_found']);
        $this->store->mode = 'throw';
        $this->send('POST', 'quotes', $this->selection())->assertStatus(503)->assertExactJson(['error' => 'order_unavailable']);
        $this->store->mode = 'conflict';
        $this->send('POST', 'quotes', $this->selection())->assertStatus(409)->assertExactJson(['error' => 'order_conflict']);
        $this->store->mode = 'terms_unavailable';
        $this->send('POST', 'quotes', $this->selection())->assertStatus(503)->assertExactJson(['error' => 'terms_unavailable']);
    }

    public function test_private_product_routes_are_absent_by_default_and_incomplete_activation_is_refused(): void
    {
        foreach (Route::getRoutes() as $route) {
            self::assertFalse(str_starts_with($route->uri(), '_development/commerce/onepage'));
        }
        config(['portal_onepage.enabled' => true]);
        $this->expectException(RuntimeException::class);
        (new OnepageCommerceServiceProvider($this->app))->register();
    }

    public function test_live_activation_is_rechecked_before_controller(): void
    {
        $valid = ['portal_onepage.enabled' => true, 'portal_development_auth.enabled' => true,
            'app.env' => 'staging', 'app.debug' => false,
            'app.url' => 'https://portal-dev.imaginecomunica.com.br', 'session.driver' => 'portal_private_pg',
            'session.cookie' => '__Host-imagine-portal-session', 'session.secure' => true,
            'session.http_only' => true, 'session.domain' => null, 'session.path' => '/',
            'session.serialization' => 'json', 'session.same_site' => 'lax'];
        config($valid);
        $calls = 0;
        $next = static function () use (&$calls) { ++$calls; return response()->json(['ok' => true]); };
        $guard = new EnsureOnepageCommerceEnabled();
        self::assertSame(200, $guard->handle(Request::create('/'), $next)->getStatusCode());
        foreach (['portal_onepage.enabled' => false, 'session.secure' => false,
            'session.http_only' => false, 'session.domain' => '.imaginecomunica.com.br',
            'session.serialization' => 'php', 'session.cookie' => 'unscoped-session'] as $key => $value) {
            config(array_replace($valid, [$key => $value]));
            self::assertSame(404, $guard->handle(Request::create('/'), $next)->getStatusCode());
        }
        self::assertSame(1, $calls);
        config($valid);
        $unavailable = $guard->handle(Request::create('/'), static function () {
            throw new RuntimeException('Private constructor connection diagnostics');
        });
        self::assertSame(503, $unavailable->getStatusCode());
        self::assertSame(['error' => 'order_unavailable'], json_decode($unavailable->getContent(), true));
        self::assertStringContainsString('no-store', $unavailable->headers->get('Cache-Control'));
        $csrf = $guard->handle(Request::create('/'), static function () { throw new TokenMismatchException(); });
        self::assertSame(419, $csrf->getStatusCode());
    }
}

final class OnepageTransportStore implements OnepageOrderStore
{
    public array $calls = [];
    public string $mode = 'ok';

    public function createDraft(string $businessReference, string $requestKey, array $selection): array
    {
        $this->calls[] = ['create', $businessReference, $requestKey, $selection];
        if ($this->mode === 'throw') throw new RuntimeException('private SQL password and endpoint must not escape');
        return $this->mode === 'ok' ? ['status' => 'draft', 'id' => '11111111-1111-4111-8111-111111111111',
            'quote' => ['site' => 'onepage'], 'order_created' => false, 'payment_requested' => false,
            'site_provisioned' => false, 'company' => 'internal-company', 'buyer' => 'internal-buyer'] : ['status' => $this->mode];
    }
    public function resumeDraft(string $businessReference, string $draftId): array
    {
        $this->calls[] = ['resume', $businessReference, $draftId];
        return ['status' => 'draft', 'id' => $draftId, 'quote' => ['site' => 'onepage'],
            'order_created' => false, 'payment_requested' => false, 'site_provisioned' => false];
    }
    public function review(string $businessReference, string $quoteId): ?array
    {
        $this->calls[] = ['review', $businessReference, $quoteId];
        return ['quote_id' => $quoteId, 'quote' => ['site' => 'onepage'], 'offer_digest' => str_repeat('a', 64),
            'terms' => ['scope' => 'onepage'], 'terms_digest' => str_repeat('c', 64),
            'contract_ref' => 'contract-onepage-2026-v1', 'contract_revision' => 1,
            'contract_text' => 'Terms', 'contract_digest' => hash('sha256', 'Terms'),
            'company' => 'internal-company', 'buyer' => 'internal-buyer', 'company_reference' => 'portal-company'];
    }
    public function accept(string $businessReference, string $requestKey, string $quoteId, array $acceptance): array
    {
        $this->calls[] = ['accept', $businessReference, $requestKey, $quoteId, $acceptance];
        return ['status' => 'pending_payment', 'id' => '22222222-2222-4222-8222-222222222222',
            'quote_id' => $quoteId, 'accepted_at' => '2026-11-18T12:00:00.000000Z',
            'payment_requested' => false, 'site_provisioned' => false];
    }
    public function findOwnedAcceptedOrder(string $businessReference, string $orderId): ?OnepageAcceptedOrder
    {
        $this->calls[] = ['read', $businessReference, $orderId];
        if ($this->mode !== 'ok' || $orderId !== '22222222-2222-4222-8222-222222222222') return null;
        return new OnepageAcceptedOrder($orderId, '11111111-1111-4111-8111-111111111111',
            '33333333-3333-4333-8333-333333333333', '44444444-4444-4444-8444-444444444444',
            'portal-company', 'published-onepage-2026-v1',
            ['catalog_version' => str_repeat('a', 64), 'site' => 'onepage', 'channels' => []],
            str_repeat('a', 64), str_repeat('d', 64), ['scope' => 'onepage', 'payment_policy' => 'onepage-entry-v1'],
            str_repeat('c', 64), 'contract-onepage-2026-v1', 1, 'Terms', hash('sha256', 'Terms'),
            '2026-11-18T12:00:00.000000Z');
    }
}
