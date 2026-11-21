<?php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CI-only collision and selective-revocation proof for *synthetic* mandates.
 *
 * This is deliberately not a test of a consent authority or an operational
 * S2S reader. In particular, administrator SQL queries bypass RLS and MUST NOT
 * be interpreted as effective restricted-role tenant isolation.
 */
final class NativePerformanceAccountMandateTenantCollisionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('ADMIN_PHASE4_PG_CI') !== '1' || getenv('CI') !== 'true') {
            $this->markTestSkipped('Explicit disposable PostgreSQL CI required');
        }
        if (DB::getDriverName() !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'performance_phase4_ci') {
            $this->fail('Refuse anything except performance_phase4_ci PostgreSQL');
        }
    }

    public function test_same_provider_account_in_two_companies_keeps_independent_audit_and_revocation(): void
    {
        $companyA = (string) Str::uuid();
        $companyB = (string) Str::uuid();
        $mandateA = (string) Str::uuid();
        $mandateB = (string) Str::uuid();
        $principal = 'imagine_portal_s2s';
        $account = '1234567890'; // Synthetic; the SAME account string in both companies.
        $expiry = time() + 600;

        foreach ([$companyA, $companyB] as $company) {
            DB::table('portal_performance_services')->insert([
                'company_id' => $company,
                'contract_ref' => (string) Str::uuid(),
                'contract_version' => 1,
                'company_active' => true,
                'status' => 'active',
                'source_kind' => 'synthetic_persisted',
                'expires_at' => $expiry,
            ]);
        }
        foreach ([[$companyA, $mandateA, 'synthetic-consent-A01', 'synthetic-vault-A01'],
                  [$companyB, $mandateB, 'synthetic-consent-B01', 'synthetic-vault-B01']] as
                 [$company, $mandate, $consent, $vault]) {
            DB::table('portal_performance_account_mandates')->insert([
                'id' => $mandate,
                'company_id' => $company,
                'provider' => 'google_ads',
                'account_id' => $account,
                'service_principal' => $principal,
                'operation' => 'reports.read',
                'purpose' => 'ads_reporting_read',
                'consent_reference' => $consent,
                'credential_ref' => $vault,
                'status' => 'candidate',
                'source_kind' => 'synthetic_candidate',
                'operational_enabled' => false,
                'revision' => 1,
                'expires_at' => $expiry,
            ]);
        }
        $query = DB::table('portal_performance_account_mandates')
            ->where('provider', 'google_ads')->where('account_id', $account)
            ->where('service_principal', $principal);
        $this->assertSame(2, (clone $query)->whereIn('id', [$mandateA, $mandateB])->count());
        foreach ([[$companyA, $mandateA], [$companyB, $mandateB]] as [$company, $mandate]) {
            $this->assertSame(1, DB::table('portal_performance_account_mandates')
                ->where('id', $mandate)->where('company_id', $company)->count());
            $this->assertSame(1, DB::table('portal_performance_account_mandate_events')
                ->where('mandate_id', $mandate)->where('company_id', $company)
                ->where('event_kind', 'created')->count());
        }

        // Same company/provider/account/principal/operation may not fork a
        // second mandate; across DIFFERENT companies it was allowed above.
        $duplicate = (array) DB::table('portal_performance_account_mandates')
            ->where('id', $mandateA)->first();
        $duplicate['id'] = (string) Str::uuid();
        try {
            DB::table('portal_performance_account_mandates')->insert($duplicate);
            $this->fail('Accepted duplicate company/account mandate');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        // Cross-company event forgery must fail the composite foreign key.
        try {
            DB::table('portal_performance_account_mandate_events')->insert([
                'id' => (string) Str::uuid(),
                'mandate_id' => $mandateA,
                'company_id' => $companyB,
                'service_principal' => $principal,
                'revision' => 99,
                'event_kind' => 'revised',
                'correlation_id' => (string) Str::uuid(),
            ]);
            $this->fail('Accepted cross-company mandate audit event');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
        // Account, company, principal and operation identity cannot be moved.
        try {
            DB::table('portal_performance_account_mandates')->where('id', $mandateA)
                ->update(['company_id' => $companyB, 'revision' => 2]);
            $this->fail('Accepted cross-company mandate migration');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        DB::table('portal_performance_account_mandates')->where('id', $mandateA)
            ->update(['status' => 'revoked', 'revoked_at' => time(), 'revision' => 2]);

        $a = DB::table('portal_performance_account_mandates')->where('id', $mandateA)->first();
        $b = DB::table('portal_performance_account_mandates')->where('id', $mandateB)->first();
        $this->assertSame('revoked', $a->status);
        $this->assertSame(2, (int) $a->revision);
        $this->assertSame('candidate', $b->status);
        $this->assertSame(1, (int) $b->revision);
        $this->assertSame('synthetic_candidate', $b->source_kind);
        $this->assertFalse((bool) $b->operational_enabled);
        $this->assertSame(2, DB::table('portal_performance_account_mandate_events')
            ->where('mandate_id', $mandateA)->where('company_id', $companyA)->count());
        $this->assertSame(1, DB::table('portal_performance_account_mandate_events')
            ->where('mandate_id', $mandateB)->where('company_id', $companyB)->count());
        $this->assertSame(1, DB::table('portal_performance_account_mandate_events')
            ->where('mandate_id', $mandateA)->where('event_kind', 'revoked')->count());
        $this->assertSame(0, DB::table('portal_performance_account_mandate_events')
            ->where('mandate_id', $mandateB)->where('event_kind', 'revoked')->count());

        // The actual runtime identity is intentionally NOT granted table read,
        // even for the unaffected company. No GRANT or SQL role changes here.
        foreach (['portal_performance_account_mandates',
                  'portal_performance_account_mandate_events'] as $table) {
            $allowed = DB::selectOne(
                'SELECT CASE WHEN has_table_privilege(?, ?, ?) THEN 1 ELSE 0 END AS allowed',
                ['portal_performance_runtime', $table, 'SELECT']
            );
            $this->assertSame(0, (int) $allowed->allowed);
        }
    }
}
