<?php

declare(strict_types=1);

namespace Tests\Feature;

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

}
