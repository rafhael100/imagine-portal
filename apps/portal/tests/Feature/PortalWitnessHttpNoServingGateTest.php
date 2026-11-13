<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\PortalWitnessNoServingHttpGuard;
use App\Infrastructure\Recovery\PortalWitnessOnlyIdentityRead;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * F382: HTTP requests in actual Laravel router with a maliciously optimistic
 * witness diagnostic must NEVER reach controller or return a success status.
 *
 * Temporary PHPUnit-only routes are never written to routes/web.php.
 */
final class PortalWitnessHttpNoServingGateTest extends TestCase
{
    private const REF = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private function fake(): PortalWitnessOnlyIdentityRead
    {
        $witness = new class implements PortalWitnessOnlyIdentityRead {
            /** @var list<string> */
            public array $observed = [];
            public bool $fail = false;
            public array $report = [
                'status' => 'LAB_CHALLENGED_READ_STILL_NO_SERVING',
                // Explicitly simulate a malicious/buggy diagnostic reporting
                // authority, which the HTTP guard MUST NOT accept.
                'canAuthorizeHttp' => true,
                'canAuthorizeBusiness' => true,
            ];

            public function inspectBusinessSession(string $reference): array
            {
                $this->observed[] = $reference;
                if ($this->fail) {
                    throw new RuntimeException('SECRET SQL provider tenant data must not leak');
                }
                return $this->report;
            }
        };
        $this->app->instance(PortalWitnessOnlyIdentityRead::class, $witness);
        return $witness;
    }

    private function route(int &$actions): void
    {
        Route::middleware(['web', PortalWitnessNoServingHttpGuard::class])
            ->match(['GET', 'POST', 'HEAD'], '/__f382/internal-diagnostic', static function () use (&$actions): Response {
                $actions++;
                return response()->json(['should_never_be_returned' => true]);
            });
    }

    private function assertProtected(Response $response): void
    {
        self::assertSame(503, $response->getStatusCode());
        self::assertSame(
            ['error' => 'portal_not_ready'],
            json_decode((string) $response->getContent(), true, 8, JSON_THROW_ON_ERROR)
        );
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('same-origin', $response->headers->get('Cross-Origin-Resource-Policy'));
        self::assertSame('noindex, nofollow, noarchive, nosnippet', $response->headers->get('X-Robots-Tag'));
    }

    public function test_real_application_has_no_public_routes_or_live_witness_bindings(): void
    {
        self::assertFalse($this->app->bound(PortalWitnessOnlyIdentityRead::class));
        self::assertFalse($this->app->bound(PortalWitnessNoServingHttpGuard::class));
        $uris = [];
        foreach ($this->app['router']->getRoutes() as $route) {
            $uris[] = $route->uri();
        }
        self::assertSame(['up'], $uris);
        foreach (['/login', '/mfa', '/portal', '/api/portal/services', '/checkout'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }

    public function test_even_a_malicious_success_observation_cannot_continue_http(): void
    {
        $fake = $this->fake();
        $actions = 0;
        $this->route($actions);
        $response = $this->withSession([
            'portal.browser_auth' => ['phase' => 'business', 'reference' => self::REF],
        ])->get('/__f382/internal-diagnostic?role=owner&is_admin=true');

        $this->assertProtected($response->baseResponse);
        self::assertSame([self::REF], $fake->observed);
        self::assertSame(0, $actions);
    }

    public function test_no_session_or_client_supplied_tokens_can_trigger_observer(): void
    {
        $fake = $this->fake();
        $actions = 0;
        $this->route($actions);
        $this->assertProtected($this->get('/__f382/internal-diagnostic?token='.self::REF)->baseResponse);
        $this->assertProtected($this->withHeaders([
            'Authorization' => 'Bearer '.self::REF,
            'X-Portal-Session' => self::REF,
        ])->get('/__f382/internal-diagnostic')->baseResponse);
        self::assertSame([], $fake->observed);
        self::assertSame(0, $actions);
    }

    public function test_invalid_server_session_fails_closed_without_backend_probe(): void
    {
        $fake = $this->fake();
        $actions = 0;
        $this->route($actions);
        foreach ([
            ['phase' => 'pending_mfa', 'reference' => self::REF],
            ['phase' => 'business', 'reference' => 'not-a-session'],
            ['phase' => 'business', 'reference' => str_repeat('F', 64)],
            ['phase' => 'admin', 'reference' => self::REF],
            ['phase' => 'business'],
        ] as $state) {
            $this->assertProtected($this->withSession([
                'portal.browser_auth' => $state,
            ])->get('/__f382/internal-diagnostic')->baseResponse);
        }
        self::assertSame([], $fake->observed);
        self::assertSame(0, $actions);
    }

    public function test_unavailable_witness_redacts_diagnostics_and_stays_503(): void
    {
        $fake = $this->fake();
        $fake->fail = true;
        $actions = 0;
        $this->route($actions);
        $response = $this->withSession([
            'portal.browser_auth' => ['phase' => 'business', 'reference' => self::REF],
        ])->get('/__f382/internal-diagnostic');
        $this->assertProtected($response->baseResponse);
        self::assertStringNotContainsString('SECRET', $response->getContent());
        self::assertSame([self::REF], $fake->observed);
        self::assertSame(0, $actions);
    }

    public function test_all_fake_negative_and_positive_verdicts_are_http_denied(): void
    {
        $fake = $this->fake();
        $actions = 0;
        $this->route($actions);
        foreach ([
            'LAB_CHALLENGED_READ_STILL_NO_SERVING',
            'LAB_CHALLENGED_DENIAL_STILL_NO_SERVING',
            'WITNESS_PRECHECK_DENIED',
            'WITNESS_POSTCHECK_DENIED',
            'WITNESS_EPOCH_CONFLICT',
            'WITNESS_SIGNATURE_DENIED',
            'WITNESS_NONCE_DENIED',
            'WITNESS_UNAVAILABLE',
            'UNEXPECTED_SUCCESS',
        ] as $status) {
            $fake->report = ['status' => $status, 'canAuthorizeHttp' => true];
            $response = $this->withSession([
                'portal.browser_auth' => ['phase' => 'business', 'reference' => self::REF],
            ])->get('/__f382/internal-diagnostic');
            $this->assertProtected($response->baseResponse);
        }
        self::assertCount(9, $fake->observed);
        self::assertSame(0, $actions);
    }
}
