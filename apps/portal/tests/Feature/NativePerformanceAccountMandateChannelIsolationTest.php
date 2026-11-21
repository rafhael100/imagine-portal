<?php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CI-only: identical account digits within ONE company are distinct by provider.
 * Synthetic candidates, not authorization to call an Ads API.
 */
final class NativePerformanceAccountMandateChannelIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CI') !== 'true' || getenv('ADMIN_PHASE4_PG_CI') !== '1'
            || getenv('APP_ENV') !== 'testing') {
            $this->markTestSkipped('Exact disposable PostgreSQL CI flags required');
        }
        if (DB::getDriverName() !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'performance_phase4_ci') {
            $this->fail('Refuse non-disposable database');
        }
    }

    public function test_provider_specific_revocation_preserves_other_channel_and_audit(): void
    {
        $company = (string) Str::uuid();
        $google = (string) Str::uuid();
        $meta = (string) Str::uuid();
        $account = '2345678901'; // CI marker; same digits intentionally.
        $principal = 'imagine_portal_s2s';
        $expires = time() + 600;
        DB::table('portal_performance_services')->insert([
            'company_id' => $company,
            'contract_ref' => (string) Str::uuid(),
            'contract_version' => 1,
            'company_active' => true,
            'status' => 'active',
            'source_kind' => 'synthetic_persisted',
            'expires_at' => $expires,
        ]);
        foreach ([
            ['google_ads', $google, 'synthetic-consent-google-001', 'synthetic-vault-google-001'],
            ['meta_ads', $meta, 'synthetic-consent-meta-001', 'synthetic-vault-meta-001'],
        ] as [$provider, $id, $consent, $vault]) {
            DB::table('portal_performance_account_mandates')->insert([
                'id' => $id, 'company_id' => $company,
                'provider' => $provider, 'account_id' => $account,
                'service_principal' => $principal,
                'operation' => 'reports.read', 'purpose' => 'ads_reporting_read',
                'consent_reference' => $consent, 'credential_ref' => $vault,
                'status' => 'candidate', 'source_kind' => 'synthetic_candidate',
                'operational_enabled' => false, 'revision' => 1,
                'expires_at' => $expires,
            ]);
        }

        $scope = DB::table('portal_performance_account_mandates')
            ->where('company_id', $company)->where('account_id', $account)
            ->where('service_principal', $principal);
        $this->assertSame(2, (clone $scope)->count());
        $this->assertSame(1, (clone $scope)->where('provider', 'google_ads')->count());
        $this->assertSame(1, (clone $scope)->where('provider', 'meta_ads')->count());

        DB::table('portal_performance_account_mandates')->where('id', $google)->update([
            'status' => 'revoked', 'revoked_at' => time(), 'revision' => 2,
        ]);
        DB::table('portal_performance_account_mandates')->where('id', $meta)->update([
            'credential_ref' => 'synthetic-vault-meta-002', 'revision' => 2,
        ]);
        $googleRow = DB::table('portal_performance_account_mandates')->where('id', $google)->first();
        $metaRow = DB::table('portal_performance_account_mandates')->where('id', $meta)->first();
        $this->assertSame('revoked', $googleRow->status);
        $this->assertSame(2, (int) $googleRow->revision);
        $this->assertSame('candidate', $metaRow->status);
        $this->assertSame(2, (int) $metaRow->revision);
        $this->assertSame('synthetic-vault-meta-002', $metaRow->credential_ref);
        $this->assertSame('synthetic_candidate', $metaRow->source_kind);
        $this->assertFalse((bool) $metaRow->operational_enabled);

        $events = DB::table('portal_performance_account_mandate_events');
        $this->assertSame(2, (clone $events)->where('mandate_id', $google)
            ->where('company_id', $company)->count());
        $this->assertSame(1, (clone $events)->where('mandate_id', $google)
            ->where('event_kind', 'revoked')->where('revision', 2)->count());
        $this->assertSame(2, (clone $events)->where('mandate_id', $meta)
            ->where('company_id', $company)->count());
        $this->assertSame(1, (clone $events)->where('mandate_id', $meta)
            ->where('event_kind', 'revised')->where('revision', 2)->count());
        $this->assertSame(0, (clone $events)->where('mandate_id', $meta)
            ->where('event_kind', 'revoked')->count());

        // Terminal guard: even same-status writes must be refused without
        // affecting the other channel or appending a spurious audit event.
        try {
            DB::table('portal_performance_account_mandates')->where('id', $google)->update([
                'status' => 'revoked', 'consent_reference' => 'synthetic-consent-rewritten-001',
                'revision' => 3,
            ]);
            $this->fail('Revoked Google candidate was rewritten');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Revoked account mandate is immutable', $e->getMessage());
        }
        $this->assertSame(2, (clone $events)->where('mandate_id', $google)->count());
        $this->assertSame(2, (clone $events)->where('mandate_id', $meta)->count());
        $this->assertSame('candidate', DB::table('portal_performance_account_mandates')
            ->where('id', $meta)->value('status'));
        $this->assertSame('synthetic-consent-google-001', DB::table('portal_performance_account_mandates')
            ->where('id', $google)->value('consent_reference'));
        // No authorization to either provider is granted to the runtime role.
        $this->assertSame(0, (int) DB::selectOne(
            "SELECT CASE WHEN has_table_privilege('portal_performance_runtime', 'portal_performance_account_mandates', 'SELECT') THEN 1 ELSE 0 END AS allowed"
        )->allowed);
    }
}
