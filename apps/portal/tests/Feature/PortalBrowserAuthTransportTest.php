<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Identity\Browser\DenyingPortalBrowserAuthentication;
use App\Domain\Identity\Browser\PortalBrowserAuthentication;
use App\Http\Controllers\PortalBrowserAuthController;
use App\Http\Middleware\PortalBrowserAuthGuard;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/** Real HTTP middleware and encrypted file sessions; identity adapter is a test double. */
final class PortalBrowserAuthTransportTest extends TestCase
{
    private const ORIGIN = 'https://portal.example.test';
    private const ORG = '22222222-2222-4222-8222-222222222222';
    private string $directory;
    private ?string $browserCookie = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/portal-http-session-' . bin2hex(random_bytes(12));
        mkdir($this->directory, 0700);
        config([
            'app.url' => self::ORIGIN,
            'session.driver' => 'file',
            'session.files' => $this->directory,
            'session.encrypt' => true,
        ]);
        $this->app['session']->forgetDrivers();
        Route::middleware(['web', PortalBrowserAuthGuard::class])->group(function (): void {
            Route::get('/__auth-test/csrf', [PortalBrowserAuthController::class, 'csrf']);
            Route::post('/__auth-test/password', [PortalBrowserAuthController::class, 'password']);
            Route::post('/__auth-test/mfa', [PortalBrowserAuthController::class, 'mfa']);
            Route::get('/__auth-test/session', [PortalBrowserAuthController::class, 'current']);
            Route::post('/__auth-test/logout', [PortalBrowserAuthController::class, 'logout']);
        });
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    private function request(string $method, string $path, array $body = [], ?string $csrf = null, array $headers = [], ?string $origin = null): TestResponse
    {
        // Discard the in-memory driver between requests to prove file persistence.
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => self::ORIGIN,
            'REMOTE_ADDR' => '127.0.0.1',
        ];
        if ($csrf !== null) {
            $server['HTTP_X_CSRF_TOKEN'] = $csrf;
        }
        $cookies = $this->browserCookie === null ? [] : [config('session.cookie') => $this->browserCookie];
        $response = $this->call($method, ($origin ?? self::ORIGIN) . '/__auth-test/' . $path,
            [], $cookies, [], array_replace($server, $headers), json_encode($body, JSON_THROW_ON_ERROR));
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === config('session.cookie')) {
                $this->browserCookie = $cookie->getValue();
                self::assertTrue($cookie->isSecure());
                self::assertTrue($cookie->isHttpOnly());
                self::assertEmpty($cookie->getDomain());
                self::assertSame('/', $cookie->getPath());
                self::assertSame('lax', $cookie->getSameSite());
            }
        }
        return $response;
    }

    private function csrf(): string
    {
        return $this->request('GET', 'csrf')->assertOk()->json('csrf_token');
    }

    private function credentials(array $extra = []): array
    {
        return array_merge(['email' => 'synthetic@example.invalid', 'password' => 'synthetic-password-only', 'organization' => self::ORG], $extra);
    }

    private function adapter(): BrowserIdentityTransportDouble
    {
        $adapter = new BrowserIdentityTransportDouble();
        $this->app->instance(PortalBrowserAuthentication::class, $adapter);
        return $adapter;
    }

    private function loginWithMfa(): BrowserIdentityTransportDouble
    {
        $adapter = $this->adapter();
        $this->request('POST', 'password', $this->credentials(), $this->csrf())->assertStatus(202);
        $this->request('POST', 'mfa', ['code' => '123456'], $this->csrf())->assertOk();
        return $adapter;
    }

    public function test_default_adapter_never_authenticates_or_attests_revocation(): void
    {
        $adapter = $this->app->make(PortalBrowserAuthentication::class);
        self::assertInstanceOf(DenyingPortalBrowserAuthentication::class, $adapter);
        self::assertFalse($adapter->revoke(str_repeat('a', 64)));
        $this->request('POST', 'password', $this->credentials(), $this->csrf())
            ->assertUnauthorized()->assertExactJson(['error' => 'authentication_failed']);
        $this->request('GET', 'session')->assertUnauthorized();
    }

    public function test_csrf_is_enforced_even_under_phpunit_and_sibling_sites_are_rejected(): void
    {
        $adapter = $this->adapter();
        $token = $this->csrf();
        $this->request('POST', 'password', $this->credentials())->assertStatus(419);
        $this->request('POST', 'password', $this->credentials(), null, ['HTTP_SEC_FETCH_SITE' => 'same-origin'])->assertStatus(419);
        $this->request('POST', 'password', $this->credentials(), 'wrong-token')->assertStatus(419);
        $this->request('POST', 'password', $this->credentials(), $token, ['HTTP_ORIGIN' => 'https://crm.example.test'])->assertStatus(419);
        $this->request('POST', 'password', $this->credentials(), $token, ['HTTP_SEC_FETCH_SITE' => 'same-site'])->assertStatus(419);
        self::assertSame(0, $adapter->passwordCalls);
    }

    public function test_https_host_payload_and_caller_supplied_authority_are_rejected(): void
    {
        $adapter = $this->adapter();
        $this->request('GET', 'csrf', origin: 'http://portal.example.test')->assertForbidden();
        $this->request('GET', 'csrf', origin: 'https://crm.example.test')->assertForbidden();
        $token = $this->csrf();
        $this->request('POST', 'password', $this->credentials(['role' => 'owner']), $token)->assertUnauthorized();
        $this->request('POST', 'password', $this->credentials(['identity_id' => self::ORG]), $token)->assertUnauthorized();
        $this->request('POST', 'password', $this->credentials(), $token, ['CONTENT_TYPE' => 'text/plain'])->assertStatus(400);
        $this->request('POST', 'password', $this->credentials(['password' => str_repeat('x', 5000)]), $token)->assertStatus(400);
        self::assertSame(0, $adapter->passwordCalls);
    }

    public function test_password_and_mfa_rotate_ids_and_only_server_side_state_can_reach_current(): void
    {
        $adapter = $this->adapter();
        $csrf = $this->csrf();
        $anonymous = $this->app['session']->driver()->getId();
        $password = $this->request('POST', 'password', $this->credentials(), $csrf)
            ->assertStatus(202)->assertExactJson(['next' => 'mfa']);
        $pending = $this->app['session']->driver()->getId();
        $pendingCookie = $this->browserCookie;
        self::assertNotSame($anonymous, $pending);
        $this->request('POST', 'mfa', ['code' => '123456'], $csrf)->assertStatus(419);
        self::assertFileDoesNotExist($this->directory . '/' . $anonymous);
        $this->request('GET', 'session')->assertUnauthorized();
        $this->request('POST', 'mfa', ['code' => '000000'], $this->csrf())->assertUnauthorized();
        $mfa = $this->request('POST', 'mfa', ['code' => '123456'], $this->csrf())
            ->assertOk()->assertExactJson(['authenticated' => true]);
        self::assertNotSame($pending, $this->app['session']->driver()->getId());
        self::assertFileDoesNotExist($this->directory . '/' . $pending);
        self::assertContains(str_repeat('a', 64), $adapter->revoked);
        $activeCookie = $this->browserCookie;
        $this->browserCookie = $pendingCookie;
        $this->request('GET', 'session')->assertUnauthorized();
        $this->browserCookie = $activeCookie;
        $this->request('GET', 'session')->assertOk()->assertExactJson(['authenticated' => true]);
        self::assertSame('127.0.0.1', $adapter->sourceIp);
        foreach ([$password, $mfa] as $response) {
            self::assertStringNotContainsString(str_repeat('a', 64), $response->getContent());
            self::assertStringNotContainsString(str_repeat('b', 64), $response->getContent());
            self::assertStringNotContainsString('synthetic-password-only', $response->getContent());
            self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            self::assertStringNotContainsString(str_repeat('b', 64), file_get_contents($file));
            self::assertStringNotContainsString('synthetic-password-only', file_get_contents($file));
        }
        $this->request('POST', 'mfa', ['code' => '123456'], $this->csrf())->assertUnauthorized();
    }

    public function test_revoked_backend_state_is_rechecked_and_durably_revoked(): void
    {
        $adapter = $this->loginWithMfa();
        $this->request('GET', 'session')->assertOk();
        self::assertNotContains(str_repeat('b', 64), $adapter->revoked);
        $adapter->active = false;
        $this->request('GET', 'session')->assertUnauthorized();
        self::assertContains(str_repeat('b', 64), $adapter->revoked,
            'F294: a stale backend reference must be revoked before the cookie is discarded.');
        $adapter->active = true;
        $this->request('GET', 'session')->assertUnauthorized();
    }

    public function test_inactive_backend_revocation_failure_is_not_reported_as_normal_denial(): void
    {
        $adapter = $this->loginWithMfa();
        $adapter->active = false;
        $adapter->revocationSucceeds = false;
        $this->request('GET', 'session')
            ->assertStatus(503)->assertExactJson(['error' => 'authentication_unavailable']);
        $adapter->revocationSucceeds = true;
        $adapter->active = true;
        $this->request('GET', 'session')->assertUnauthorized();
        self::assertNotContains(str_repeat('b', 64), $adapter->revoked);
    }

    public function test_logout_destroys_local_state_and_waits_for_durable_revocation(): void
    {
        $adapter = $this->loginWithMfa();
        $oldId = $this->app['session']->driver()->getId();
        $oldCookie = $this->browserCookie;
        $this->request('POST', 'logout', [], $this->csrf())->assertNoContent();
        self::assertContains(str_repeat('b', 64), $adapter->revoked);
        self::assertFileDoesNotExist($this->directory . '/' . $oldId);
        $this->browserCookie = $oldCookie;
        $this->request('GET', 'session')->assertUnauthorized();
    }

    public function test_revocation_failure_clears_local_state_but_never_reports_success(): void
    {
        $adapter = $this->loginWithMfa();
        $adapter->revocationSucceeds = false;
        $this->request('POST', 'logout', [], $this->csrf())
            ->assertStatus(503)->assertExactJson(['error' => 'authentication_unavailable']);
        $this->request('GET', 'session')->assertUnauthorized();
    }

    public function test_relogin_requires_old_reference_revocation_before_another_password_attempt(): void
    {
        $adapter = $this->loginWithMfa();
        $adapter->revocationSucceeds = false;
        $calls = $adapter->passwordCalls;
        $this->request('POST', 'password', $this->credentials(), $this->csrf())->assertStatus(503);
        self::assertSame($calls, $adapter->passwordCalls);
        $this->request('GET', 'session')->assertUnauthorized();
    }

    public function test_mfa_requires_pending_state_and_rejects_unchanged_references(): void
    {
        $adapter = $this->adapter();
        $this->request('POST', 'mfa', ['code' => '123456'], $this->csrf())->assertUnauthorized();
        self::assertSame(0, $adapter->mfaCalls);
        $this->request('POST', 'password', $this->credentials(), $this->csrf())->assertStatus(202);
        $adapter->reusePending = true;
        $this->request('POST', 'mfa', ['code' => '123456'], $this->csrf())->assertStatus(503);
        $this->request('GET', 'session')->assertUnauthorized();
    }

    public function test_backend_exception_never_exposes_credentials_or_promotes_state(): void
    {
        $adapter = $this->adapter();
        $adapter->throwPassword = true;
        $this->request('POST', 'password', $this->credentials(), $this->csrf())
            ->assertStatus(503)->assertExactJson(['error' => 'authentication_unavailable']);
        $this->request('GET', 'session')->assertUnauthorized();
    }
}

/** Only bound inside this test class. Not an issuer or persistence implementation. */
final class BrowserIdentityTransportDouble implements PortalBrowserAuthentication
{
    public int $passwordCalls = 0;
    public int $mfaCalls = 0;
    public bool $active = false;
    public bool $revocationSucceeds = true;
    public bool $throwPassword = false;
    public bool $reusePending = false;
    public array $revoked = [];
    public ?string $sourceIp = null;

    public function beginPassword(string $email, string $password, string $organization, string $sourceIp): ?string
    {
        $this->passwordCalls++;
        $this->sourceIp = $sourceIp;
        if ($this->throwPassword) {
            throw new RuntimeException('Never expose this synthetic-password-only diagnostic.');
        }
        return str_repeat('a', 64);
    }

    public function completeMfa(string $pendingReference, string $code, string $sourceIp): ?string
    {
        $this->mfaCalls++;
        if ($code !== '123456' || in_array($pendingReference, $this->revoked, true)) {
            return null;
        }
        $this->active = true;
        $this->revoked[] = $pendingReference;
        return str_repeat($this->reusePending ? 'a' : 'b', 64);
    }

    public function isActiveBusinessSession(string $reference): bool
    {
        return $reference === str_repeat('b', 64) && $this->active;
    }

    public function revoke(string $reference): bool
    {
        if (!$this->revocationSucceeds) {
            return false;
        }
        $this->revoked[] = $reference;
        $this->active = false;
        return true;
    }
}
