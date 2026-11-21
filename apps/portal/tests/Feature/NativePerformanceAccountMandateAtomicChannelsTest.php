<?php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Synthetic-only atomicity: a rejected change to Google must roll back the
 * already-audited Meta rotation in the SAME transaction. Neither provider is
 * an official authority and no Ads call, credential or permission is used.
 */
final class NativePerformanceAccountMandateAtomicChannelsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('CI') !== 'true'
            || getenv('ADMIN_PHASE4_PG_CI') !== '1'
            || getenv('APP_ENV') !== 'testing') {
            $this->markTestSkipped('Only attested disposable PostgreSQL18 CI');
        }
        if (DB::getDriverName() !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'performance_phase4_ci') {
            $this->fail('Refuse anything except the disposable Phase8 database');
        }
    }

    public function test_failed_google_mutation_rolls_back_meta_rotation_and_both_audits(): void
    {
        $company = (string) Str::uuid();
        $google = (string) Str::uuid();
        $meta = (string) Str::uuid();
        $principal = 'imagine_portal_s2s';
        $expiry = time() + 600;

        DB::table('portal_performance_services')->insert([
            'company_id' => $company, 'contract_ref' => (string) Str::uuid(),
            'contract_version' => 1, 'company_active' => true,
            'status' => 'active', 'source_kind' => 'synthetic_persisted',
            'expires_at' => $expiry,
        ]);
        foreach ([
            ['google_ads', $google, '3456789012', 'google'],
            ['meta_ads', $meta, '3456789012', 'meta'],
        ] as [$provider, $id, $account, $suffix]) {
            DB::table('portal_performance_account_mandates')->insert([
                'id' => $id, 'company_id' => $company,
                'provider' => $provider, 'account_id' => $account,
                'service_principal' => $principal, 'operation' => 'reports.read',
                'purpose' => 'ads_reporting_read',
                'consent_reference' => 'synthetic-consent-' . $suffix . '-001',
                'credential_ref' => 'synthetic-vault-' . $suffix . '-001',
                'status' => 'candidate', 'source_kind' => 'synthetic_candidate',
                'operational_enabled' => false, 'revision' => 1,
                'expires_at' => $expiry,
            ]);
        }

        $rows = DB::table('portal_performance_account_mandates')->where('company_id', $company);
        $events = DB::table('portal_performance_account_mandate_events')->where('company_id', $company);
        $this->assertSame(2, (clone $rows)->count());
        $this->assertSame(2, (clone $events)->where('event_kind', 'created')->count());

        // One transaction, two different providers: the first UPDATE and its
        // audit event must be rolled back when the second UPDATE hits the
        // precise immutable-identity trigger (not a generic SQL rejection).
        $deniedByIdentityTrigger = false;
        try {
            DB::transaction(static function () use ($meta, $google): void {
                DB::table('portal_performance_account_mandates')->where('id', $meta)->update([
                    'credential_ref' => 'synthetic-vault-meta-002', 'revision' => 2,
                ]);
                DB::table('portal_performance_account_mandates')->where('id', $google)->update([
                    'provider' => 'meta_ads', 'revision' => 2,
                ]);
            });
        } catch (QueryException $exception) {
            $deniedByIdentityTrigger = str_contains(
                $exception->getMessage(), 'Account mandate identity is immutable'
            );
        }
        $this->assertTrue($deniedByIdentityTrigger, 'Expected immutable-identity trigger rejection');

        $this->assertSame('synthetic-vault-meta-001',
            (clone $rows)->where('id', $meta)->value('credential_ref'));
        $this->assertSame(1, (int) (clone $rows)->where('id', $meta)->value('revision'));
        $this->assertSame('google_ads', (clone $rows)->where('id', $google)->value('provider'));
        $this->assertSame(1, (int) (clone $rows)->where('id', $google)->value('revision'));
        $this->assertSame(2, (clone $events)->count());
        $this->assertSame(0, (clone $events)->where('revision', 2)->count());

        // Positive control: with a valid transaction both provider-specific
        // changes commit and emit exactly one audit event per channel.
        DB::transaction(static function () use ($meta, $google): void {
            DB::table('portal_performance_account_mandates')->where('id', $meta)->update([
                'credential_ref' => 'synthetic-vault-meta-002', 'revision' => 2,
            ]);
            DB::table('portal_performance_account_mandates')->where('id', $google)->update([
                'status' => 'revoked', 'revoked_at' => time(), 'revision' => 2,
            ]);
        });
        $this->assertSame('revoked', (clone $rows)->where('id', $google)->value('status'));
        $this->assertSame('candidate', (clone $rows)->where('id', $meta)->value('status'));
        $this->assertSame('synthetic-vault-meta-002',
            (clone $rows)->where('id', $meta)->value('credential_ref'));
        $this->assertSame(4, (clone $events)->count());
        $this->assertSame(1, (clone $events)->where('mandate_id', $google)
            ->where('event_kind', 'revoked')->where('revision', 2)->count());
        $this->assertSame(1, (clone $events)->where('mandate_id', $meta)
            ->where('event_kind', 'revised')->where('revision', 2)->count());
        $this->assertSame(0, (clone $rows)->where('operational_enabled', true)->count());
    }
}
