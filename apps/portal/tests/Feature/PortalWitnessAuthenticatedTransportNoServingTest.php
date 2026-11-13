<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Identity\Browser\PortalBrowserAuthentication;
use App\Http\Controllers\PortalBrowserAuthController;
use App\Http\Middleware\PortalBrowserAuthGuard;
use App\Http\Middleware\PortalWitnessNoServingHttpGuard;
use App\Infrastructure\Recovery\PortalWitnessOnlyIdentityRead;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * F383: the real Laravel transport controllers are used only on temporary
 * PHPUnit routes, never on production routes.
 *
 * A seemingly healthy signed witness and optimistic SQL identity double
 * MUST NEVER allow issuing a pending session, completing MFA, checking a
 * business session or touching logout while the HTTP fence is NO-GO.
 */
final class PortalWitnessAuthenticatedTransportNoServingTest extends TestCase
{
    private const ORIGIN = 'https://portal.example.test';
    private const ORG = '22222222-2222-4222-8222-222222222222';
    private const SESSION_REFERENCE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const CSRF = 'trusted-only-in-phpunit-internal-f383-token';

    private function registerFixture(): array
    {
        config([
            'app.url' => self::ORIGIN,
            'session.driver' => 'array',
            'portal_development_auth.organization' => self::ORG,
        ]);
        $this->app['session']->forgetDrivers();

        $identity = new class implements PortalBrowserAuthentication {
            public int $password = 0;
            public int $mfa = 0;
            public int $current = 0;
            public int $revoke = 0;

            public function beginPassword(
                string $email, string $password, string $organization, string $sourceIp,
            ): ?string {
                $this->password++;
                return str_repeat('b', 64);
            }

            public function completeMfa(string $pendingReference, string $code, string $sourceIp): ?string
            {
                $this->mfa++;
                return str_repeat('c', 64);
            }

            public function isActiveBusinessSession(string $reference): bool
            {
                $this->current++;
                return true;
            }

            public function revoke(string $reference): bool
            {
                $this->revoke++;
                return true;
            }
        };

        $witness = new class implements PortalWitnessOnlyIdentityRead {
            /** @var list<string> */
            public array $observed = [];
            public bool $throw = false;

            public function inspectBusinessSession(string $reference): array
            {
                $this->observed[] = $reference;
                if ($this->throw) {
                    throw new RuntimeException('SECRET external witness private key');
                }
                return [
                    'status' => 'LAB_CHALLENGED_READ_STILL_NO_SERVING',
                    // Intentionally impossible/untrusted positive fake flag:
                    'canAuthorizeHttp' => true,
                    'canAuthorizeBusiness' => true,
                ];
            }
        };

        $this->app->instance(PortalBrowserAuthentication::class, $identity);
        $this->app->instance(PortalWitnessOnlyIdentityRead::class, $witness);

        Route::middleware(['web', PortalBrowserAuthGuard::class])->post(
            '/__f383/guard-baseline',
            static fn () => response()->json(['csrf_passed' => true]),
        );
        Route::middleware([
            'web',
            PortalBrowserAuthGuard::class,
            PortalWitnessNoServingHttpGuard::class,
        ])->group(function (): void {
            Route::get('/__f383/csrf', [PortalBrowserAuthController::class, 'csrf']);
            Route::post('/__f383/password', [PortalBrowserAuthController::class, 'password']);
            Route::post('/__f383/mfa', [PortalBrowserAuthController::class, 'mfa']);
            Route::get('/__f383/session', [PortalBrowserAuthController::class, 'current']);
            Route::post('/__f383/logout', [PortalBrowserAuthController::class, 'logout']);
        });

        return [$identity, $witness];
    }

    private function request(string $method, string $path, array $payload = [], array $state = []): \Illuminate\Testing\TestResponse
    {
        $this->withSession(array_replace(['_token' => self::CSRF], $state));
        $server = [
            'HTTP_ORIGIN' => self::ORIGIN,
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
            'HTTP_X_CSRF_TOKEN' => self::CSRF,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
        ];
        return $this->call(
            $method,
            self::ORIGIN . $path,
            [],
            [],
            [],
            $server,
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    private function assertDenied(\Illuminate\Testing\TestResponse $r): void
    {
        $r->assertStatus(503)->assertExactJson(['error' => 'portal_not_ready']);
        self::assertSame('no-store, private', $r->headers->get('Cache-Control'));
        self::assertSame('DENY', $r->headers->get('X-Frame-Options'));
        self::assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        self::assertSame('noindex, nofollow, noarchive, nosnippet', $r->headers->get('X-Robots-Tag'));
        self::assertStringNotContainsString('SECRET', $r->getContent());
        self::assertStringNotContainsString(self::SESSION_REFERENCE, $r->getContent());
    }

    private function assertNoIdentityActions(object $identity): void
    {
        self::assertSame(0, $identity->password);
        self::assertSame(0, $identity->mfa);
        self::assertSame(0, $identity->current);
        self::assertSame(0, $identity->revoke);
    }

    public function test_existing_https_csrf_guard_accepts_real_same_origin_test_request(): void
    {
        [$identity, $witness] = $this->registerFixture();
        $this->request('POST', '/__f383/guard-baseline', ['synthetic' => true])
            ->assertOk()->assertExactJson(['csrf_passed' => true]);
        $this->assertNoIdentityActions($identity);
        self::assertSame([], $witness->observed);
    }

    public function test_all_five_actual_controller_entrypoints_are_fenced_before_identity_actions(): void
    {
        [$identity, $witness] = $this->registerFixture();
        $business = [
            'portal.browser_auth' => [
                'phase' => 'business',
                'reference' => self::SESSION_REFERENCE,
            ],
        ];
        $pending = [
            'portal.browser_auth' => [
                'phase' => 'pending_mfa',
                'reference' => self::SESSION_REFERENCE,
            ],
        ];

        $this->assertDenied($this->request('GET', '/__f383/csrf', [], $business));
        $this->assertDenied($this->request('POST', '/__f383/password', [
            'email' => 'synthetic@example.invalid',
            'password' => 'synthetic-password-only',
            'organization' => self::ORG,
        ], $business));
        $this->assertDenied($this->request('POST', '/__f383/mfa', [
            'code' => '123456',
        ], $pending));
        $this->assertDenied($this->request('GET', '/__f383/session', [], $business));
        $this->assertDenied($this->request('POST', '/__f383/logout', [], $business));

        $this->assertNoIdentityActions($identity);
        self::assertNotEmpty($witness->observed);
        foreach ($witness->observed as $ref) {
            self::assertSame(self::SESSION_REFERENCE, $ref);
        }
    }

    public function test_offline_witness_never_leaks_or_executes_actual_controller(): void
    {
        [$identity, $witness] = $this->registerFixture();
        $witness->throw = true;
        $this->assertDenied($this->request('GET', '/__f383/session', [], [
            'portal.browser_auth' => [
                'phase' => 'business',
                'reference' => self::SESSION_REFERENCE,
            ],
        ]));
        self::assertSame([self::SESSION_REFERENCE], $witness->observed);
        $this->assertNoIdentityActions($identity);
    }

    public function test_remote_bearer_headers_do_not_become_browser_identity(): void
    {
        [$identity, $witness] = $this->registerFixture();
        $response = $this->request('GET', '/__f383/session?role=owner&token='.self::SESSION_REFERENCE);
        $this->assertDenied($response);
        self::assertSame([], $witness->observed);
        $this->assertNoIdentityActions($identity);
    }
}
