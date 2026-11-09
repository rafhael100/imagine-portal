<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Labs\ClientAuth\LabMembershipFence;
use App\Labs\ClientAuth\LabPortalGateway;
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

}
