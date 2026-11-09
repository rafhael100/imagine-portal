<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Labs\ClientAuth\LabMembershipFence;
use App\Labs\ClientAuth\LabPortalGateway;
use App\Labs\ClientAuth\LabOutboxPublisher;
use App\Labs\ClientAuth\LabOutboxWorker;
use App\Labs\ClientAuth\LabTotp;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Tests real SQL and HTTP requests in an ephemeral PostgreSQL service.
 * No real credentials, accounts, identities, data, production routes or VPS.
 */
final class LabPortalPgFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('PORTAL_CLIENT_LAB_ENABLED') !== '1'
            || ! app()->environment('testing')
            || PHP_SAPI !== 'cli') {
            $this->markTestSkipped('Requires explicit disposable PostgreSQL runner.');
        }

        config(['database.connections.portal_lab_runtime' => array_merge(
            config('database.connections.pgsql'),
            [
                'username' => 'portal_lab_runtime',
                'password' => 'portal-lab-runtime-only',
                'database' => 'portal_lab',
            ],
        )]);
        DB::purge('portal_lab_runtime');

        config(['database.connections.portal_lab_worker' => array_merge(
            config('database.connections.pgsql'),
            [
                'username' => 'portal_lab_worker',
                'password' => 'portal-lab-worker-only',
                'database' => 'portal_lab',
            ],
        )]);
        DB::purge('portal_lab_worker');

        require_once base_path('labs/client-auth/LabOutboxPublisher.php');
        require_once base_path('labs/client-auth/LabOutboxWorker.php');

        $migration = require base_path('labs/client-auth/create_lab_schema.php');
        $migration->down();
        $migration->up();

        DB::connection('pgsql')->table('lab_portal_users')->insert([
            [
                'id' => 'portal-user-a',
                'email' => 'a@example.invalid',
                'password_hash' => Hash::make('synthetic-pass-A-2026'),
                'status' => 'active',
                'session_revision' => 1,
            ],
            [
                'id' => 'portal-user-b',
                'email' => 'b@example.invalid',
                'password_hash' => Hash::make('synthetic-pass-B-2026'),
                'status' => 'active',
                'session_revision' => 1,
            ],
        ]);
        // Fixed, published artificial MFA fixtures; never use in production.
        DB::connection('pgsql')->table('lab_portal_auth_factors')->insert([
            [
                'user_id' => 'portal-user-a',
                'secret_hex' => '3132333435363738393031323334353637383930',
                'last_step' => -1,
            ],
            [
                'user_id' => 'portal-user-b',
                'secret_hex' => '4142434445464748494a4b4c4d4e4f5051525354',
                'last_step' => -1,
            ],
        ]);

        DB::connection('pgsql')->table('lab_portal_organizations')->insert([
            ['id' => 'org-a', 'name' => 'Aurora Synthetic', 'status' => 'active'],
            ['id' => 'org-b', 'name' => 'Boreal Synthetic', 'status' => 'active'],
        ]);
        DB::connection('pgsql')->table('lab_portal_memberships')->insert([
            ['org_id' => 'org-a', 'user_id' => 'portal-user-a', 'status' => 'active'],
            ['org_id' => 'org-b', 'user_id' => 'portal-user-b', 'status' => 'active'],
        ]);
        DB::connection('pgsql')->table('lab_portal_services')->insert([
            ['org_id' => 'org-a', 'id' => 'service-a', 'label' => 'Onepage synthetic A'],
            ['org_id' => 'org-b', 'id' => 'service-b', 'label' => 'Performance synthetic B'],
        ]);
    }

    private function loginA(): void
    {
        $this->postJson('/_lab/portal/login', [
            'email' => 'a@example.invalid',
            'password' => 'synthetic-pass-A-2026',
        ])->assertOk()->assertJsonPath('message', 'lab_session_created');
    }

    public function test_login_and_list_are_scoped_to_active_portal_membership(): void
    {
        $this->getJson('/_lab/portal/organizations')->assertUnauthorized();

        $this->postJson('/_lab/portal/login', [
            'email' => 'a@example.invalid',
            'password' => 'wrong-password',
        ])->assertUnauthorized()->assertJsonPath('error', 'invalid_credentials');

        $this->loginA();

        $this->getJson('/_lab/portal/organizations')
            ->assertOk()
            ->assertJsonCount(1, 'organizations')
            ->assertJsonPath('organizations.0.id', 'org-a');

        $this->getJson('/_lab/portal/organizations/org-a/services')
            ->assertOk()
            ->assertJsonCount(1, 'services')
            ->assertJsonPath('services.0.id', 'service-a')
            ->assertDontSee('Performance synthetic B');

        $this->getJson('/_lab/portal/organizations/org-b/services')
            ->assertNotFound();
    }

    public function test_membership_revocation_and_suspended_organization_fail_closed(): void
    {
        $this->loginA();
        $this->getJson('/_lab/portal/organizations/org-a/services')->assertOk();

        DB::connection('pgsql')->table('lab_portal_memberships')
            ->where('user_id', 'portal-user-a')->update(['status' => 'revoked']);

        $this->getJson('/_lab/portal/organizations/org-a/services')->assertNotFound();

        DB::connection('pgsql')->table('lab_portal_memberships')
            ->where('user_id', 'portal-user-a')->update(['status' => 'active']);
        DB::connection('pgsql')->table('lab_portal_organizations')
            ->where('id', 'org-a')->update(['status' => 'suspended']);

        $this->getJson('/_lab/portal/organizations/org-a/services')->assertNotFound();
        $this->getJson('/_lab/portal/organizations')->assertJsonCount(0, 'organizations');
    }

    public function test_account_revision_change_and_logout_invalidate_session(): void
    {
        $this->loginA();
        DB::connection('pgsql')->table('lab_portal_users')
            ->where('id', 'portal-user-a')->update(['session_revision' => 2]);

        $this->getJson('/_lab/portal/organizations')->assertUnauthorized();
        $this->getJson('/_lab/portal/organizations/org-a/services')->assertUnauthorized();

        $this->loginA();
        $this->getJson('/_lab/portal/organizations')->assertOk();

        $this->postJson('/_lab/portal/logout')->assertOk();
        $this->getJson('/_lab/portal/organizations')->assertUnauthorized();
    }

    public function test_runtime_role_cannot_read_foreign_services_or_write_to_tenants(): void
    {
        $db = DB::connection('portal_lab_runtime');
        $current = $db->selectOne('select current_user as name')->name;
        $this->assertSame('portal_lab_runtime', $current, 'Runtime queries must never use the schema owner.');

        // With no tenant/actor context, PostgreSQL RLS fails closed.
        $this->assertSame(0, $db->table('lab_portal_services')->count());

        $rowsA = $db->transaction(static function () use ($db) {
            $db->select("select set_config('portal.lab_tenant', ?, true)", ['org-a']);
            $db->select("select set_config('portal.lab_actor', ?, true)", ['portal-user-a']);

            return $db->table('lab_portal_services')->pluck('id')->all();
        });
        $this->assertSame(['service-a'], $rowsA);

        // Attacker may select B in a request; without B membership, RLS denies.
        $rowsB = $db->transaction(static function () use ($db) {
            $db->select("select set_config('portal.lab_tenant', ?, true)", ['org-b']);
            $db->select("select set_config('portal.lab_actor', ?, true)", ['portal-user-a']);

            return $db->table('lab_portal_services')->pluck('id')->all();
        });
        $this->assertSame([], $rowsB);
        $this->assertSame(0, $db->table('lab_portal_services')->count(), 'SET LOCAL must not leak through pooling.');

        try {
            $db->table('lab_portal_memberships')->where('org_id', 'org-a')->update(['status' => 'active']);
            $this->fail('Read-only runtime unexpectedly wrote a membership.');
        } catch (QueryException $e) {
            $this->assertTrue(true);
        }
    }

    public function test_portal_session_does_not_accept_crm_cookie_or_unknown_organization(): void
    {
        $this->withSession([
            'crm_user_id' => 'portal-user-a',
            'crm_session' => 'fixture-remote-session',
        ]);
        $this->getJson('/_lab/portal/organizations')->assertUnauthorized();
        $this->loginA();
        $this->getJson('/_lab/portal/organizations/%2e%2e/services')->assertNotFound();
        $this->getJson('/_lab/portal/organizations/org-b/services')->assertNotFound();
    }

    public function test_tenant_dashboard_renders_authorized_blade_and_escapes_html(): void
    {
        $this->get('/_lab/portal/organizations/org-a/dashboard')->assertUnauthorized();
        $this->loginA();

        $this->get('/_lab/portal/organizations/org-a/dashboard')
            ->assertOk()
            ->assertSeeText('Aurora Synthetic')
            ->assertSeeText('Onepage synthetic A')
            ->assertDontSee('Performance synthetic B')
            ->assertSee('LABORATÓRIO ISOLADO')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->get('/_lab/portal/organizations/org-b/dashboard')->assertNotFound();

        DB::connection('pgsql')->table('lab_portal_services')
            ->where('org_id', 'org-a')
            ->update(['label' => '<script>alert("untrusted")</script>']);

        $response = $this->get('/_lab/portal/organizations/org-a/dashboard')->assertOk();
        $response->assertSee('&lt;script&gt;', false);
        $response->assertDontSee('<script>alert("untrusted")</script>', false);

        DB::connection('pgsql')->table('lab_portal_memberships')
            ->where('org_id', 'org-a')
            ->where('user_id', 'portal-user-a')
            ->update(['status' => 'revoked']);

        $this->get('/_lab/portal/organizations/org-a/dashboard')->assertNotFound();
    }


    public function test_requests_write_stays_within_member_tenant_and_fk_composite(): void
    {
        $this->postJson('/_lab/portal/organizations/org-a/requests', [
            'service_id' => 'service-a',
            'title' => 'Synthetic request',
        ])->assertUnauthorized();

        $this->loginA();

        $this->postJson('/_lab/portal/organizations/org-b/requests', [
            'service_id' => 'service-b',
            'title' => 'Cross tenant write attempt',
        ])->assertNotFound();

        $this->postJson('/_lab/portal/organizations/org-a/requests', [
            'service_id' => 'service-b',
            'title' => 'Forged foreign service',
        ])->assertNotFound();

        $create = $this->postJson('/_lab/portal/organizations/org-a/requests', [
            'service_id' => 'service-a',
            'title' => 'Synthetic request for A',
        ])->assertStatus(201)->assertJsonPath('status', 'received');

        $requestId = $create->json('request_id');
        $this->assertIsString($requestId);
        $this->assertSame(1, DB::connection('pgsql')
            ->table('lab_portal_requests')
            ->where('org_id', 'org-a')
            ->where('id', $requestId)
            ->count());

        $this->getJson('/_lab/portal/organizations/org-a/requests')
            ->assertOk()
            ->assertJsonCount(1, 'requests')
            ->assertJsonPath('requests.0.title', 'Synthetic request for A');
        $this->getJson('/_lab/portal/organizations/org-b/requests')->assertNotFound();

        // Even an admin-generated cross-tenant FK cannot be inserted.
        try {
            DB::connection('pgsql')->table('lab_portal_requests')->insert([
                'org_id' => 'org-a',
                'id' => 'bad-fk',
                'service_id' => 'service-b',
                'title' => 'Cross tenant reference',
                'status' => 'received',
            ]);
            $this->fail('PostgreSQL accepted cross-tenant composite FK.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $this->assertSame(1, DB::connection('pgsql')->table('lab_portal_requests')->count());
    }

    public function test_requests_fail_closed_when_runtime_context_missing_or_member_revoked(): void
    {
        $runtime = DB::connection('portal_lab_runtime');
        try {
            $runtime->table('lab_portal_requests')->insert([
                'org_id' => 'org-a',
                'id' => 'no-actor-context',
                'service_id' => 'service-a',
                'title' => 'Missing RLS context',
                'status' => 'received',
            ]);
            $this->fail('Runtime wrote without tenant/actor GUCs.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $this->loginA();
        DB::connection('pgsql')->table('lab_portal_memberships')
            ->where('org_id', 'org-a')
            ->where('user_id', 'portal-user-a')
            ->update(['status' => 'revoked']);

        $this->postJson('/_lab/portal/organizations/org-a/requests', [
            'service_id' => 'service-a',
            'title' => 'After revocation',
        ])->assertNotFound();

        $this->assertSame(0, DB::connection('pgsql')->table('lab_portal_requests')->count());
    }


    private function secureOtpA(): string
    {
        return LabTotp::codeAt('3132333435363738393031323334353637383930', time());
    }

    private function secureSignInA(): void
    {
        $this->postJson('/_lab/portal/secure/login', [
            'email' => 'a@example.invalid',
            'password' => 'synthetic-pass-A-2026',
            'totp' => $this->secureOtpA(),
        ])->assertOk()->assertJsonPath('message', 'synthetic_mfa_session_created');
    }

    public function test_secure_lab_totp_matches_rfc_test_vector_truncated_to_six_digits(): void
    {
        // RFC 6238 SHA1 test vector at 59s => 94287082 (8 digits),
        // so the equivalent 6-digit code is 287082.
        $this->assertSame('287082', LabTotp::codeAt(
            '3132333435363738393031323334353637383930',
            59,
        ));

        $this->assertNull(LabTotp::verifiedNewStep(
            '3132333435363738393031323334353637383930',
            '287082',
            59,
            1,
        ), 'Already-used counter may never be used again.');
    }

    public function test_secure_login_requires_totp_and_creates_server_side_hashed_session(): void
    {
        $this->getJson('/_lab/portal/secure/organizations')->assertUnauthorized();

        $this->postJson('/_lab/portal/secure/login', [
            'email' => 'a@example.invalid',
            'password' => 'synthetic-pass-A-2026',
            'totp' => '000000',
        ])->assertUnauthorized();

        $this->secureSignInA();

        $this->assertSame(1, DB::connection('pgsql')->table('lab_portal_auth_sessions')->count());
        $stored = DB::connection('pgsql')->table('lab_portal_auth_sessions')->first();
        $this->assertSame(64, strlen($stored->token_digest));
        $this->assertTrue((bool) $stored->mfa_verified);
        $this->assertFalse((bool) $stored->revoked);
        $this->assertSame('portal-user-a', $stored->user_id);
        $this->assertGreaterThan(time(), (int) $stored->expires_at);

        $this->getJson('/_lab/portal/secure/organizations')
            ->assertOk()->assertJsonCount(1, 'organizations')
            ->assertJsonPath('organizations.0.id', 'org-a');

        $this->get('/_lab/portal/secure/organizations/org-a/dashboard')
            ->assertOk()->assertSee('Onepage synthetic A')->assertDontSee('Performance synthetic B');
        $this->get('/_lab/portal/secure/organizations/org-b/dashboard')->assertNotFound();

        // A secure bearer never implicitly activates the original legacy
        // synthetic session route, and a CRM cookie never signs into secure.
        $this->getJson('/_lab/portal/organizations')->assertUnauthorized();
    }

    public function test_secure_logout_replay_and_password_revision_invalidate_access(): void
    {
        $this->secureSignInA();
        $this->getJson('/_lab/portal/secure/organizations')->assertOk();

        DB::connection('pgsql')->table('lab_portal_users')
            ->where('id', 'portal-user-a')->update(['session_revision' => 2]);
        $this->getJson('/_lab/portal/secure/organizations')->assertUnauthorized();

        DB::connection('pgsql')->table('lab_portal_users')
            ->where('id', 'portal-user-a')->update(['session_revision' => 1]);

        DB::connection('pgsql')->table('lab_portal_auth_sessions')
            ->update(['expires_at' => time() - 1]);
        $this->getJson('/_lab/portal/secure/organizations')->assertUnauthorized();

        $this->postJson('/_lab/portal/secure/logout')->assertOk();
        $this->assertSame(1, DB::connection('pgsql')
            ->table('lab_portal_auth_sessions')->where('revoked', true)->count());
        $this->getJson('/_lab/portal/secure/organizations')->assertUnauthorized();

        // The same OTP counter was consumed in the first successful login.
        $this->postJson('/_lab/portal/secure/login', [
            'email' => 'a@example.invalid',
            'password' => 'synthetic-pass-A-2026',
            'totp' => $this->secureOtpA(),
        ])->assertUnauthorized();
    }

    public function test_secure_rate_limit_blocks_three_invalid_attempts_and_even_good_password(): void
    {
        for ($n = 1; $n <= 3; $n++) {
            $response = $this->postJson('/_lab/portal/secure/login', [
                'email' => 'a@example.invalid',
                'password' => 'incorrect-password',
                'totp' => $this->secureOtpA(),
            ]);
            $response->assertStatus($n === 3 ? 429 : 401);
        }

        $this->postJson('/_lab/portal/secure/login', [
            'email' => 'a@example.invalid',
            'password' => 'synthetic-pass-A-2026',
            'totp' => $this->secureOtpA(),
        ])->assertStatus(429);

        $this->assertSame(0, DB::connection('pgsql')->table('lab_portal_auth_sessions')->count());
        $limit = DB::connection('pgsql')->table('lab_portal_login_limits')->first();
        $this->assertSame(3, (int) $limit->failures);
        $this->assertGreaterThan(time(), (int) $limit->locked_until);
    }

    public function test_secure_tenant_membership_revocation_denies_dashboard_after_valid_mfa(): void
    {
        $this->secureSignInA();
        $this->get('/_lab/portal/secure/organizations/org-a/dashboard')->assertOk();

        DB::connection('pgsql')->table('lab_portal_memberships')
            ->where('org_id', 'org-a')->where('user_id', 'portal-user-a')
            ->update(['status' => 'revoked']);

        $this->get('/_lab/portal/secure/organizations/org-a/dashboard')->assertNotFound();
        $this->getJson('/_lab/portal/secure/organizations')->assertJsonCount(0, 'organizations');
    }


    public function test_secure_idempotent_request_returns_same_id_and_rejects_payload_conflict(): void
    {
        $payload = [
            'service_id' => 'service-a',
            'title' => 'Authorized request one',
            'idempotency_key' => 'REQ-SYNTHETIC-2026-A',
        ];

        $this->postJson('/_lab/portal/secure/organizations/org-a/requests', $payload)
            ->assertUnauthorized();

        $this->secureSignInA();

        $this->postJson('/_lab/portal/secure/organizations/org-a/requests', [
            'service_id' => 'service-a',
            'title' => 'Authorized request one',
        ])->assertUnprocessable();

        $first = $this->postJson('/_lab/portal/secure/organizations/org-a/requests', $payload)
            ->assertCreated()->assertJsonPath('replayed', false);
        $requestId = $first->json('request_id');
        $this->assertIsString($requestId);

        $again = $this->postJson('/_lab/portal/secure/organizations/org-a/requests', $payload)
            ->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame($requestId, $again->json('request_id'));

        $this->postJson('/_lab/portal/secure/organizations/org-a/requests', array_merge($payload, [
            'title' => 'Same key with changed payload',
        ]))->assertStatus(409)->assertJsonPath('error', 'idempotency_conflict');

        $this->postJson('/_lab/portal/secure/organizations/org-b/requests', [
            'service_id' => 'service-b',
            'title' => 'Attempt foreign tenant',
            'idempotency_key' => $payload['idempotency_key'],
        ])->assertNotFound();

        $this->assertSame(1, DB::connection('pgsql')->table('lab_portal_requests')->count());
        $record = DB::connection('pgsql')->table('lab_portal_requests')->first();
        $this->assertSame(hash('sha256', $payload['idempotency_key']), $record->idempotency_digest);
        $this->assertSame('org-a', $record->org_id);
    }

    public function test_secure_idempotency_is_scoped_per_organization_and_revoked_member_cannot_replay(): void
    {
        $key = 'SAME-KEY-DIFFERENT-ORGS-2026';
        $this->secureSignInA();

        $a = $this->postJson('/_lab/portal/secure/organizations/org-a/requests', [
            'service_id' => 'service-a',
            'title' => 'Tenant A task',
            'idempotency_key' => $key,
        ])->assertCreated();

        DB::connection('pgsql')->table('lab_portal_memberships')
            ->where('org_id', 'org-a')->where('user_id', 'portal-user-a')
            ->update(['status' => 'revoked']);

        $this->postJson('/_lab/portal/secure/organizations/org-a/requests', [
            'service_id' => 'service-a',
            'title' => 'Tenant A task',
            'idempotency_key' => $key,
        ])->assertNotFound();

        $this->postJson('/_lab/portal/secure/logout')->assertOk();

        $this->postJson('/_lab/portal/secure/login', [
            'email' => 'b@example.invalid',
            'password' => 'synthetic-pass-B-2026',
            'totp' => LabTotp::codeAt('4142434445464748494a4b4c4d4e4f5051525354', time()),
        ])->assertOk();

        $b = $this->postJson('/_lab/portal/secure/organizations/org-b/requests', [
            'service_id' => 'service-b',
            'title' => 'Tenant B independent task',
            'idempotency_key' => $key,
        ])->assertCreated();

        $this->assertNotSame($a->json('request_id'), $b->json('request_id'));
        $this->assertSame(2, DB::connection('pgsql')->table('lab_portal_requests')->count());
        $this->assertSame(1, DB::connection('pgsql')->table('lab_portal_requests')
            ->where('org_id', 'org-a')->count());
        $this->assertSame(1, DB::connection('pgsql')->table('lab_portal_requests')
            ->where('org_id', 'org-b')->count());
    }


    public function test_f4_writer_first_holds_grant_lock_until_commit_then_revoker_succeeds(): void
    {
        $writer = DB::connection('portal_lab_runtime');
        $revoker = DB::connection('pgsql');
        $this->assertNotSame(
            $writer->selectOne('SELECT pg_backend_pid() AS pid')->pid,
            $revoker->selectOne('SELECT pg_backend_pid() AS pid')->pid,
            'F4 needs genuinely independent PostgreSQL backend connections.',
        );

        $writer->beginTransaction();

        try {
            // Real synthetic write runs under nested transaction/savepoint.
            // Shared lock must survive its inner COMMIT until outer COMMIT.
            $result = app(LabPortalGateway::class)->submitIdempotentRequest(
                'portal-user-a',
                'org-a',
                'service-a',
                'F4 synthetic writer before revoke',
                'F4-WRITER-FIRST-2026',
            );
            $this->assertSame('created', $result['outcome']);

            $revoker->beginTransaction();
            try {
                $revoker->statement("SET LOCAL lock_timeout = '180ms'");

                try {
                    LabMembershipFence::lockRevoker($revoker, 'portal-user-a', 'org-a');
                    $this->fail('Exclusive revocation acquired a lock while writer had shared lock.');
                } catch (QueryException $exception) {
                    $this->assertStringContainsString('lock timeout', strtolower($exception->getMessage()));
                }
            } finally {
                if ($revoker->transactionLevel() > 0) {
                    $revoker->rollBack();
                }
            }

            // Writer may finish before revocation commits, not afterwards.
            $writer->commit();
            $this->assertSame(1, LabMembershipFence::revoke('portal-user-a', 'org-a'));
            $this->assertNull(app(LabPortalGateway::class)->submitIdempotentRequest(
                'portal-user-a', 'org-a', 'service-a',
                'F4 rejected after revocation', 'F4-AFTER-REVOCATION',
            ));
            $this->assertSame(1, DB::connection('pgsql')->table('lab_portal_requests')->count());
        } finally {
            if ($writer->transactionLevel() > 0) {
                $writer->rollBack();
            }
            if ($revoker->transactionLevel() > 0) {
                $revoker->rollBack();
            }
        }
    }

    public function test_f4_revoker_first_blocks_writer_then_rechecks_revoked_membership(): void
    {
        $writer = DB::connection('portal_lab_runtime');
        $revoker = DB::connection('pgsql');
        $this->assertNotSame(
            $writer->selectOne('SELECT pg_backend_pid() AS pid')->pid,
            $revoker->selectOne('SELECT pg_backend_pid() AS pid')->pid,
        );

        $revoker->beginTransaction();

        try {
            LabMembershipFence::lockRevoker($revoker, 'portal-user-a', 'org-a');
            $revoker->table('lab_portal_memberships')
                ->where('org_id', 'org-a')->where('user_id', 'portal-user-a')
                ->update(['status' => 'revoked']);

            // On independent DB connection, the write must wait for the
            // revoker's exclusive lock. lock_timeout bounds this lab test.
            $writer->statement("SET lock_timeout = '180ms'");
            try {
                try {
                    app(LabPortalGateway::class)->submitIdempotentRequest(
                        'portal-user-a', 'org-a', 'service-a',
                        'F4 must not bypass pending revocation', 'F4-REVOKER-FIRST',
                    );
                    $this->fail('Writer bypassed the open revocation transaction.');
                } catch (QueryException $exception) {
                    $this->assertStringContainsString('lock timeout', strtolower($exception->getMessage()));
                }
            } finally {
                $writer->statement('RESET lock_timeout');
            }

            $revoker->commit();

            // After revocation commits, the lock is available, but current
            // membership is revoked. Rechecking MUST reject the write.
            $this->assertNull(app(LabPortalGateway::class)->submitIdempotentRequest(
                'portal-user-a', 'org-a', 'service-a',
                'F4 rejected after revocation', 'F4-POST-COMMIT',
            ));
            $this->assertSame(0, $revoker->table('lab_portal_requests')->count());
        } finally {
            if ($revoker->transactionLevel() > 0) {
                $revoker->rollBack();
            }
            if ($writer->transactionLevel() > 0) {
                $writer->rollBack();
            }
            $writer->statement('RESET lock_timeout');
        }
    }


    private function syntheticF7Request(string $key = 'F7-REQUEST-UNIQUE-2026'): string
    {
        $result = app(LabPortalGateway::class)->submitIdempotentRequest(
            'portal-user-a', 'org-a', 'service-a', 'Lab F7 outbox request', $key,
        );
        $this->assertSame('created', $result['outcome']);
        $this->assertIsString($result['id']);

        return $result['id'];
    }

    public function test_f7_outbox_delivers_one_local_receipt_and_replay_cannot_duplicate_effect(): void
    {
        $requestId = $this->syntheticF7Request();
        $publisher = app(LabOutboxPublisher::class);
        $worker = app(LabOutboxWorker::class);

        $id = $publisher->enqueue('portal-user-a', 'org-a', $requestId);
        $this->assertIsString($id);
        $this->assertSame($id, $publisher->enqueue('portal-user-a', 'org-a', $requestId));
        $this->assertSame(1, DB::connection('pgsql')->table('lab_portal_outbox')->count());

        $this->assertSame('portal_lab_worker',
            DB::connection('portal_lab_worker')->selectOne('select current_user as name')->name);
        $this->assertSame('delivered', $worker->consumeOne());
        $this->assertSame('empty', $worker->consumeOne());
        $this->assertSame(1, DB::connection('pgsql')->table('lab_portal_delivery_receipts')->count());
        $this->assertSame('synthetic.local_receipt_only',
            DB::connection('pgsql')->table('lab_portal_delivery_receipts')->first()->effect_type);
        $this->assertSame('delivered',
            DB::connection('pgsql')->table('lab_portal_outbox')->first()->status);

        // INSERT conflict cannot manufacture another outbox event after
        // success; a delivered event is not visible to runtime RLS SELECT.
        $this->assertNull($publisher->enqueue('portal-user-a', 'org-a', $requestId));
        $this->assertSame(1, DB::connection('pgsql')->table('lab_portal_outbox')->count());
        $this->assertSame(1, DB::connection('pgsql')->table('lab_portal_delivery_receipts')->count());
    }

    public function test_f7_revoked_membership_blocks_queued_work_and_reactivation_cannot_replay_old_generation(): void
    {
        $requestId = $this->syntheticF7Request();
        $id = app(LabOutboxPublisher::class)->enqueue('portal-user-a', 'org-a', $requestId);
        $this->assertIsString($id);
        $this->assertSame(1, LabMembershipFence::revoke('portal-user-a', 'org-a'));

        $this->assertSame('blocked', app(LabOutboxWorker::class)->consumeOne());
        $this->assertSame('empty', app(LabOutboxWorker::class)->consumeOne());
        $this->assertSame(0, DB::connection('pgsql')->table('lab_portal_delivery_receipts')->count());
        $record = DB::connection('pgsql')->table('lab_portal_outbox')->first();
        $this->assertSame('blocked', $record->status);
        $this->assertSame(1, (int) $record->attempts);

        DB::connection('pgsql')->table('lab_portal_memberships')
            ->where('org_id', 'org-a')->where('user_id', 'portal-user-a')
            ->update(['status' => 'active']);

        // Re-activation does not reset revision; the previously blocked
        // event never becomes deliverable without an explicit new operation.
        $this->assertSame(2, (int) DB::connection('pgsql')
            ->table('lab_portal_memberships')->where('org_id', 'org-a')
            ->where('user_id', 'portal-user-a')->value('revision'));
        $this->assertSame('empty', app(LabOutboxWorker::class)->consumeOne());
        $this->assertSame(0, DB::connection('pgsql')->table('lab_portal_delivery_receipts')->count());
    }

    public function test_f7_account_revision_and_suspended_organization_block_queued_side_effects(): void
    {
        $requestId = $this->syntheticF7Request();
        $this->assertIsString(app(LabOutboxPublisher::class)->enqueue('portal-user-a', 'org-a', $requestId));

        DB::connection('pgsql')->table('lab_portal_users')
            ->where('id', 'portal-user-a')
            ->update(['session_revision' => 2]);

        $this->assertSame('blocked', app(LabOutboxWorker::class)->consumeOne());
        $this->assertSame(0, DB::connection('pgsql')->table('lab_portal_delivery_receipts')->count());

        // Separate case: fresh event with active account but suspended org.
        DB::connection('pgsql')->table('lab_portal_users')
            ->where('id', 'portal-user-a')
            ->update(['session_revision' => 1]);
        $second = $this->syntheticF7Request('F7-ORG-SUSPEND-2026');
        $this->assertIsString(app(LabOutboxPublisher::class)->enqueue('portal-user-a', 'org-a', $second));
        DB::connection('pgsql')->table('lab_portal_organizations')
            ->where('id', 'org-a')
            ->update(['status' => 'suspended']);

        $this->assertSame('blocked', app(LabOutboxWorker::class)->consumeOne());
        $this->assertSame('empty', app(LabOutboxWorker::class)->consumeOne());
        $this->assertSame(0, DB::connection('pgsql')->table('lab_portal_delivery_receipts')->count());
    }

    public function test_f7_worker_role_cannot_revoke_membership_and_publisher_cannot_forge_tenant(): void
    {
        $requestId = $this->syntheticF7Request();
        $this->assertNull(app(LabOutboxPublisher::class)->enqueue('portal-user-a', 'org-b', $requestId));

        $publisher = DB::connection('portal_lab_runtime');
        $this->assertSame(0, $publisher->table('lab_portal_outbox')->count(), 'No GUC must not reveal outbox.');

        // Runtime cannot insert an event with organization B and actor A.
        try {
            $publisher->table('lab_portal_outbox')->insert([
                'org_id' => 'org-b',
                'id' => 'forged-outbox',
                'actor_id' => 'portal-user-a',
                'request_id' => $requestId,
                'event_type' => 'request.received.synthetic',
                'membership_revision' => 1,
                'account_revision' => 1,
                'status' => 'pending',
                'attempts' => 0,
            ]);
            $this->fail('Runtime created a foreign event without tenant authorization.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $worker = DB::connection('portal_lab_worker');
        try {
            $worker->table('lab_portal_memberships')
                ->where('org_id', 'org-a')->update(['status' => 'revoked']);
            $this->fail('Worker gained permission to revoke a membership.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $this->assertSame(0, DB::connection('pgsql')->table('lab_portal_outbox')->count());
    }

    public function test_f7_two_independent_worker_connections_skip_locked_event(): void
    {
        $requestId = $this->syntheticF7Request();
        $this->assertIsString(app(LabOutboxPublisher::class)->enqueue('portal-user-a', 'org-a', $requestId));

        config(['database.connections.portal_lab_worker_b' => array_merge(
            config('database.connections.portal_lab_worker'),
            ['application_name' => 'portal_lab_worker_b'],
        )]);
        DB::purge('portal_lab_worker_b');

        $first = DB::connection('portal_lab_worker');
        $second = DB::connection('portal_lab_worker_b');
        $this->assertNotEquals(
            $first->selectOne('select pg_backend_pid() as pid')->pid,
            $second->selectOne('select pg_backend_pid() as pid')->pid,
            'Two different real PostgreSQL backend processes are required.',
        );

        $first->beginTransaction();
        try {
            $locked = $first->table('lab_portal_outbox')
                ->where('status', 'pending')->lock('FOR UPDATE SKIP LOCKED')->first();
            $this->assertNotNull($locked);
            $skipped = $second->transaction(
                static fn () => $second->table('lab_portal_outbox')
                    ->where('status', 'pending')->lock('FOR UPDATE SKIP LOCKED')->first(),
            );
            $this->assertNull($skipped, 'Concurrent worker must skip a locked event.');
        } finally {
            $first->rollBack();
            DB::disconnect('portal_lab_worker_b');
        }

        $this->assertSame('delivered', app(LabOutboxWorker::class)->consumeOne());
        $this->assertSame(1, DB::connection('pgsql')->table('lab_portal_delivery_receipts')->count());
    }

    public function test_f10_performance_issuer_rechecks_mfa_membership_rls_and_revocation(): void
    {
        // Reuse the actual F6 synthetic MFA/session verifier and F4 lock
        // rather than trusting an actor supplied to a separate mock issuer.
        require_once base_path('labs/client-auth/LabSecureLogin.php');
        require_once base_path('labs/client-auth/LabPerformanceMfaIssuer.php');

        $login = app(\App\Labs\ClientAuth\LabSecureLogin::class)->signIn(
            'b@example.invalid',
            'synthetic-pass-B-2026',
            LabTotp::codeAt('4142434445464748494a4b4c4d4e4f5051525354', time()),
        );
        $this->assertIsString($login['token'], 'B must pass the synthetic MFA lab.');

        $request = \Illuminate\Http\Request::create('/synthetic-performance-lab', 'POST');
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put('portal_lab_v2_token', $login['token']);

        $issuer = new \App\Labs\ClientAuth\LabPerformanceMfaIssuer();
        $secret = random_bytes(32);
        $issuedAt = 1799999940;
        $expiresAt = 1800000060;
        $emit = static fn (string $org, string $service, string $id, string $nonce) =>
            $issuer->issue($request, $org, $service, $id, $nonce, $issuedAt, $expiresAt, $secret);

        $envelope = $emit('org-b', 'service-b', 'req-demo-b1', 'nonce-demo-b1');
        $this->assertIsArray($envelope, 'Active synthetic MFA B can emit only the allowlisted lab service.');
        $this->assertSame('org-demo-b', $envelope['organization_ref']);
        $this->assertSame('perf-demo-b', $envelope['tenant_ref']);
        $this->assertSame('svc-demo-b', $envelope['service_ref']);
        $this->assertSame(1, $envelope['grant_revision']);
        $this->assertSame('portal-imagine-mock', $envelope['producer']);
        $this->assertArrayNotHasKey('actor_id', $envelope);
        $this->assertArrayNotHasKey('session_token', $envelope);
        $this->assertArrayNotHasKey('password', $envelope);

        $fixture = (string) file_get_contents(base_path(
            'labs/performance-contract/performance-dashboard-v1.json'
        ));
        $digest = hash('sha256', $fixture);
        $this->assertSame($digest, $envelope['payload_sha256']);
        $verifier = new \Imagine\Protocol\Lab\SyntheticS2SProtocol();
        $this->assertSame('accepted_synthetic_only', $verifier->verifyForLab(
            $envelope, $secret, 'org-demo-b', 'perf-demo-b', 'svc-demo-b',
            $digest, 1, 1800000000, true,
        ));
        $this->assertSame('mock_replay_denied', $verifier->verifyForLab(
            $envelope, $secret, 'org-demo-b', 'perf-demo-b', 'svc-demo-b',
            $digest, 1, 1800000000, true,
        ));

        $tampered = $envelope;
        $tampered['tenant_ref'] = 'perf-demo-a';
        $this->assertSame('invalid_lab_signature',
            (new \Imagine\Protocol\Lab\SyntheticS2SProtocol())->verifyForLab(
                $tampered, $secret, 'org-demo-b', 'perf-demo-b', 'svc-demo-b',
                $digest, 1, 1800000000, true,
            ));

        $this->assertNull($emit('org-a', 'service-a', 'req-demo-foreign', 'nonce-demo-foreign'));
        $this->assertNull($emit('org-b', 'service-a', 'req-demo-other', 'nonce-demo-other'));
        $this->assertNull($emit('org-b', 'service-absent', 'req-demo-missing', 'nonce-demo-missing'));
        $this->assertNull($issuer->issue(
            $request, 'org-b', 'service-b', 'req-demo-weak', 'nonce-demo-weak',
            $issuedAt, $expiresAt, 'weak',
        ));

        // No session is a hard denial even if the requester names a valid B service.
        $request->session()->forget('portal_lab_v2_token');
        $this->assertNull($emit('org-b', 'service-b', 'req-demo-blank', 'nonce-demo-blank'));
        $request->session()->put('portal_lab_v2_token', $login['token']);

        // Server-side session invalidation invalidates the projected grant.
        DB::connection('pgsql')->table('lab_portal_auth_sessions')
            ->where('user_id', 'portal-user-b')->update(['revoked' => true]);
        $this->assertNull($emit('org-b', 'service-b', 'req-demo-session', 'nonce-demo-session'));
        DB::connection('pgsql')->table('lab_portal_auth_sessions')
            ->where('user_id', 'portal-user-b')->update(['revoked' => false, 'expires_at' => time() - 1]);
        $this->assertNull($emit('org-b', 'service-b', 'req-demo-exp', 'nonce-demo-exp'));
        DB::connection('pgsql')->table('lab_portal_auth_sessions')
            ->where('user_id', 'portal-user-b')->update(['expires_at' => time() + 600]);

        // Password/account revision invalidates the previously verified session.
        DB::connection('pgsql')->table('lab_portal_users')
            ->where('id', 'portal-user-b')->update(['session_revision' => 2]);
        $this->assertNull($emit('org-b', 'service-b', 'req-demo-revision', 'nonce-demo-revision'));
        DB::connection('pgsql')->table('lab_portal_users')
            ->where('id', 'portal-user-b')->update(['session_revision' => 1]);

        DB::connection('pgsql')->table('lab_portal_organizations')
            ->where('id', 'org-b')->update(['status' => 'suspended']);
        $this->assertNull($emit('org-b', 'service-b', 'req-demo-suspended', 'nonce-demo-suspended'));
        DB::connection('pgsql')->table('lab_portal_organizations')
            ->where('id', 'org-b')->update(['status' => 'active']);

        $this->assertSame(1, LabMembershipFence::revoke('portal-user-b', 'org-b'));
        $this->assertNull($emit('org-b', 'service-b', 'req-demo-revoked', 'nonce-demo-revoked'));
        DB::connection('pgsql')->table('lab_portal_memberships')
            ->where('org_id', 'org-b')->where('user_id', 'portal-user-b')
            ->update(['status' => 'active']);
        $this->assertNull($emit('org-b', 'service-b', 'req-demo-reopened', 'nonce-demo-reopened'),
            'Reactivating membership at a newer revision does not silently restore the old grant.');

        $this->assertSame(2, (int) DB::connection('pgsql')->table('lab_portal_memberships')
            ->where('org_id', 'org-b')->where('user_id', 'portal-user-b')->value('revision'));
        $this->get('/_lab/portal/performance/emit')->assertNotFound();
    }

}
