<?php
declare(strict_types=1);
namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Tests only a disposable PostgreSQL instance, never an operational mandate. */
final class NativePerformanceAccountMandateStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('ADMIN_PHASE4_PG_CI') !== '1') {
            $this->markTestSkipped('Explicit disposable Phase4 PostgreSQL flag required');
        }
        if (DB::getDriverName() !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'performance_phase4_ci') {
            $this->fail('Mandate tests require exact disposable PostgreSQL database');
        }
    }

    public function test_schema_is_isolated_non_operational_and_append_only(): void
    {
        foreach (['portal_performance_account_mandates','portal_performance_account_mandate_events'] as $table) {
            $flags = DB::selectOne('SELECT CASE WHEN relrowsecurity THEN 1 ELSE 0 END AS rls, CASE WHEN relforcerowsecurity THEN 1 ELSE 0 END AS forced FROM pg_class WHERE oid=CAST(? AS regclass)', [$table]);
            $this->assertNotNull($flags);
            $this->assertSame(1, (int)$flags->rls);
            $this->assertSame(1, (int)$flags->forced);
            foreach (['SELECT','INSERT','UPDATE','DELETE','TRUNCATE'] as $priv) {
                $allowed = DB::selectOne('SELECT CASE WHEN has_table_privilege(?, ?, ?) THEN 1 ELSE 0 END AS allowed', ['portal_performance_runtime',$table,$priv]);
                $this->assertSame(0, (int)$allowed->allowed, 'Runtime must not have '.$priv.' on '.$table);
            }
        }

        $company=(string)Str::uuid();
        $mandate=(string)Str::uuid();
        $principal='imagine_portal_s2s';
        DB::table('portal_performance_services')->insert([
            'company_id'=>$company,'contract_ref'=>(string)Str::uuid(),
            'contract_version'=>1,'company_active'=>true,'status'=>'active',
            'source_kind'=>'synthetic_persisted','expires_at'=>time()+600,
        ]);
        $base=[
            'id'=>$mandate,'company_id'=>$company,'provider'=>'google_ads',
            'account_id'=>'1234567890','service_principal'=>$principal,
            'operation'=>'reports.read','purpose'=>'ads_reporting_read',
            'consent_reference'=>'synthetic-consent-ref-001',
            'credential_ref'=>'synthetic-vault-ref-001','status'=>'candidate',
            'source_kind'=>'synthetic_candidate','operational_enabled'=>false,
            'revision'=>1,'expires_at'=>time()+600,
        ];
        DB::table('portal_performance_account_mandates')->insert($base);
        $this->assertSame('candidate', DB::table('portal_performance_account_mandates')->where('id',$mandate)->value('status'));
        $this->assertFalse((bool)DB::table('portal_performance_account_mandates')->where('id',$mandate)->value('operational_enabled'));

        $event=(string)Str::uuid();
        DB::table('portal_performance_account_mandate_events')->insert([
            'id'=>$event,'mandate_id'=>$mandate,'company_id'=>$company,
            'service_principal'=>$principal,'revision'=>1,'event_kind'=>'created',
            'correlation_id'=>(string)Str::uuid(),
        ]);
        $this->assertSame(1, DB::table('portal_performance_account_mandate_events')->where('id',$event)->count());

        foreach ([
            ['provider'=>'meta_ads'],
            ['account_id'=>'bad-id'],
            ['purpose'=>'ads_write'],
            ['operational_enabled'=>true],
            ['source_kind'=>'official_verified'],
            ['credential_ref'=>'../../unsafe'],
            ['consent_reference'=>'x'],
            ['revision'=>0],
            ['status'=>'revoked'],
        ] as $changes) {
            try {
                DB::table('portal_performance_account_mandates')->where('id',$mandate)->update($changes);
                $this->fail('Invalid mandate database mutation accepted: '.json_encode($changes));
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
        try {
            DB::table('portal_performance_account_mandate_events')->where('id',$event)->update(['revision'=>2]);
            $this->fail('Append-only event update accepted');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
        try {
            DB::table('portal_performance_account_mandate_events')->where('id',$event)->delete();
            $this->fail('Append-only event deletion accepted');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
        $runtime = DB::connection('performance_authority');
        try {
            $runtime->table('portal_performance_account_mandates')->count();
            $this->fail('Runtime must not read stored candidate');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
        $this->assertSame(1, DB::table('portal_performance_account_mandate_events')->where('id',$event)->count());

        // A legitimate opaque-reference rotation requires a strictly newer revision.
        DB::table('portal_performance_account_mandates')->where('id',$mandate)->update([
            'credential_ref'=>'synthetic-vault-ref-002','revision'=>2,
        ]);
        $this->assertSame('synthetic-vault-ref-002',
            DB::table('portal_performance_account_mandates')->where('id',$mandate)->value('credential_ref'));
        $this->assertSame(2,
            (int)DB::table('portal_performance_account_mandates')->where('id',$mandate)->value('revision'));

        // Once revoked, the candidate cannot be silently reactivated.
        DB::table('portal_performance_account_mandates')->where('id',$mandate)->update([
            'status'=>'revoked','revoked_at'=>time(),'revision'=>3,
        ]);
        try {
            DB::table('portal_performance_account_mandates')->where('id',$mandate)->update([
                'status'=>'candidate','revoked_at'=>null,'revision'=>4,
            ]);
            $this->fail('Revoked mandate reactivated');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
        $this->assertSame('revoked',
            DB::table('portal_performance_account_mandates')->where('id',$mandate)->value('status'));
    }
}
