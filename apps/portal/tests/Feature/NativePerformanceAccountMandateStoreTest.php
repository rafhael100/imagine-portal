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

        // The INSERT must itself create an event; no separate application command.
        $event=DB::table('portal_performance_account_mandate_events')
            ->where('mandate_id',$mandate)->where('event_kind','created')->value('id');
        $this->assertTrue(Str::isUuid($event));
        $this->assertSame(1, DB::table('portal_performance_account_mandate_events')->where('id',$event)->count());
        $this->assertSame(1, DB::table('portal_performance_account_mandate_events')->where('mandate_id',$mandate)->count());

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
        $this->assertSame(1, DB::table('portal_performance_account_mandate_events')
            ->where('mandate_id',$mandate)->where('revision',2)->where('event_kind','revised')->count());
        $this->assertSame(2, DB::table('portal_performance_account_mandate_events')->where('mandate_id',$mandate)->count());

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
        // Rejected mutations leave no event; the three accepted transitions
        // each generate an immutable event in the same SQL transaction.
        $this->assertSame(3, DB::table('portal_performance_account_mandate_events')
            ->where('mandate_id',$mandate)->count());
        $this->assertSame(1, DB::table('portal_performance_account_mandate_events')
            ->where('mandate_id',$mandate)->where('revision',3)->where('event_kind','revoked')->count());
        foreach (['portal_performance_account_mandates','portal_performance_account_mandate_events'] as $table) {
            try {
                DB::statement('TRUNCATE TABLE '.$table);
                $this->fail('History truncation accepted: '.$table);
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
        try {
            DB::table('portal_performance_account_mandates')->where('id',$mandate)->delete();
            $this->fail('Mandate deletion accepted');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
        $this->assertSame(3, DB::table('portal_performance_account_mandate_events')
            ->where('mandate_id',$mandate)->count());
    }


    public function test_opaque_references_rejected_on_insert_not_only_by_revision_trigger(): void
    {
        $company=(string)Str::uuid();
        $principal='imagine_portal_s2s';
        DB::table('portal_performance_services')->insert([
            'company_id'=>$company,'contract_ref'=>(string)Str::uuid(),
            'contract_version'=>1,'company_active'=>true,'status'=>'active',
            'source_kind'=>'synthetic_persisted','expires_at'=>time()+600,
        ]);
        $base=[
            'company_id'=>$company,'provider'=>'google_ads',
            'service_principal'=>$principal,'operation'=>'reports.read',
            'purpose'=>'ads_reporting_read','status'=>'candidate',
            'source_kind'=>'synthetic_candidate','operational_enabled'=>false,
            'revision'=>1,'expires_at'=>time()+600,
        ];
        // Boundaries match the strictly ASCII, 8..128 byte engine contract.
        foreach ([
            ['account_id'=>'2234567890','consent_reference'=>'refABCDE','credential_ref'=>str_repeat('X',128)],
            ['account_id'=>'2234567891','consent_reference'=>str_repeat('A',128),'credential_ref'=>'vault001'],
        ] as $valid) {
            $id=(string)Str::uuid();
            DB::table('portal_performance_account_mandates')->insert(array_merge($base,$valid,['id'=>$id]));
            $this->assertSame(1, DB::table('portal_performance_account_mandate_events')
                ->where('mandate_id',$id)->where('revision',1)->where('event_kind','created')->count());
        }

        // Each attempt has a fresh id and unused account id; failure cannot be
        // explained by UNIQUE/FK or an UPDATE revision trigger.
        $invalidRefs=[
            ['consent_reference','short'],
            ['consent_reference','../unsafe'],
            ['consent_reference','https://token.example'],
            ['consent_reference',"line\nbreak"],
            ['consent_reference',str_repeat('a',129)],
            ['credential_ref','x'],
            ['credential_ref','vault/other-tenant'],
            ['credential_ref','contains space'],
            ['credential_ref',"\nsynthetic"],
            ['credential_ref',str_repeat('b',129)],
        ];
        foreach ($invalidRefs as $index=>$entry) {
            [$field,$value]=$entry;
            $id=(string)Str::uuid();
            $row=array_merge($base,[
                'id'=>$id,'account_id'=>'34'.str_pad((string)$index,8,'0',STR_PAD_LEFT),
                'consent_reference'=>'synthetic-consent-001',
                'credential_ref'=>'synthetic-vault-001',
            ],[$field=>$value]);
            try {
                DB::table('portal_performance_account_mandates')->insert($row);
                $this->fail('Invalid reference inserted: '.$field.' case '.$index);
            } catch (QueryException $e) {
                $this->assertSame('23514',(string)$e->getCode(),'Must fail a PostgreSQL CHECK, not a duplicate/FK');
            }
            $this->assertSame(0, DB::table('portal_performance_account_mandates')->where('id',$id)->count());
            $this->assertSame(0, DB::table('portal_performance_account_mandate_events')->where('mandate_id',$id)->count());
        }

        // A writer may not forge another event for an already-audited revision.
        $mandateId=DB::table('portal_performance_account_mandates')
            ->where('company_id',$company)->where('account_id','2234567890')->value('id');
        try {
            DB::table('portal_performance_account_mandate_events')->insert([
                'id'=>(string)Str::uuid(),'mandate_id'=>$mandateId,
                'company_id'=>$company,'service_principal'=>$principal,'revision'=>1,
                'event_kind'=>'created','correlation_id'=>(string)Str::uuid(),
            ]);
            $this->fail('Duplicate event for the same mandate revision was accepted');
        } catch (QueryException $e) {
            $this->assertSame('23505',(string)$e->getCode(),'Must fail revision UNIQUE');
        }
        $this->assertSame(1, DB::table('portal_performance_account_mandate_events')
            ->where('mandate_id',$mandateId)->where('revision',1)->count());
    }
    public function test_future_audit_event_cannot_preseed_revision_and_block_rotation(): void
    {
        $company=(string)Str::uuid();
        $mandate=(string)Str::uuid();
        $principal='imagine_portal_s2s';
        DB::table('portal_performance_services')->insert([
            'company_id'=>$company,'contract_ref'=>(string)Str::uuid(),
            'contract_version'=>1,'company_active'=>true,'status'=>'active',
            'source_kind'=>'synthetic_persisted','expires_at'=>time()+600,
        ]);
        DB::table('portal_performance_account_mandates')->insert([
            'id'=>$mandate,'company_id'=>$company,'provider'=>'google_ads',
            'account_id'=>'9876543210','service_principal'=>$principal,
            'operation'=>'reports.read','purpose'=>'ads_reporting_read',
            'consent_reference'=>'synthetic-consent-guard-001',
            'credential_ref'=>'synthetic-vault-guard-001','status'=>'candidate',
            'source_kind'=>'synthetic_candidate','operational_enabled'=>false,
            'revision'=>1,'expires_at'=>time()+600,
        ]);
        $this->assertSame(1, DB::table('portal_performance_account_mandate_events')
            ->where('mandate_id',$mandate)->where('revision',1)->where('event_kind','created')->count());

        // Before this guard, a writer with audit INSERT could pre-seed revision
        // 2, causing the automatic UNIQUE event to abort a legitimate rotation.
        // FK, shape, company and principal are valid: only chronology can deny.
        try {
            DB::table('portal_performance_account_mandate_events')->insert([
                'id'=>(string)Str::uuid(),'mandate_id'=>$mandate,
                'company_id'=>$company,'service_principal'=>$principal,
                'revision'=>2,'event_kind'=>'revised',
                'correlation_id'=>(string)Str::uuid(),
            ]);
            $this->fail('A future-revision audit event was accepted');
        } catch (QueryException $e) {
            $this->assertSame('23514',(string)$e->getCode(),'Reject future revision, not duplicate or FK');
        }
        $this->assertSame(0, DB::table('portal_performance_account_mandate_events')
            ->where('mandate_id',$mandate)->where('revision',2)->count());

        DB::table('portal_performance_account_mandates')->where('id',$mandate)->update([
            'credential_ref'=>'synthetic-vault-guard-002','revision'=>2,
        ]);
        $this->assertSame(1, DB::table('portal_performance_account_mandate_events')
            ->where('mandate_id',$mandate)->where('revision',2)->where('event_kind','revised')->count());
        $this->assertSame(2, DB::table('portal_performance_account_mandate_events')
            ->where('mandate_id',$mandate)->count());
    }


    public function test_same_provider_account_has_independent_tenants_and_no_cross_tenant_audit(): void
    {
        $companyA=(string)Str::uuid();
        $companyB=(string)Str::uuid();
        $mandateA=(string)Str::uuid();
        $mandateB=(string)Str::uuid();
        $principal='imagine_portal_s2s';
        $account='1234567890';

        foreach ([$companyA,$companyB] as $company) {
            DB::table('portal_performance_services')->insert([
                'company_id'=>$company,'contract_ref'=>(string)Str::uuid(),
                'contract_version'=>1,'company_active'=>true,'status'=>'active',
                'source_kind'=>'synthetic_persisted','expires_at'=>time()+600,
            ]);
        }

        // Equal provider/account/principal must not imply shared mandate.
        foreach ([
            [$companyA,$mandateA,'synthetic-consent-tenant-A','synthetic-vault-tenant-A'],
            [$companyB,$mandateB,'synthetic-consent-tenant-B','synthetic-vault-tenant-B'],
        ] as [$company,$mandate,$consent,$credential]) {
            DB::table('portal_performance_account_mandates')->insert([
                'id'=>$mandate,'company_id'=>$company,'provider'=>'google_ads',
                'account_id'=>$account,'service_principal'=>$principal,
                'operation'=>'reports.read','purpose'=>'ads_reporting_read',
                'consent_reference'=>$consent,'credential_ref'=>$credential,
                'status'=>'candidate','source_kind'=>'synthetic_candidate',
                'operational_enabled'=>false,'revision'=>1,
                'expires_at'=>time()+600,
            ]);
        }
        foreach ([[$companyA,$mandateA],[$companyB,$mandateB]] as [$company,$mandate]) {
            $this->assertSame(1,DB::table('portal_performance_account_mandates')
                ->where('id',$mandate)->where('company_id',$company)->where('account_id',$account)->count());
            $this->assertSame(1,DB::table('portal_performance_account_mandate_events')
                ->where('mandate_id',$mandate)->where('company_id',$company)
                ->where('revision',1)->where('event_kind','created')->count());
        }

        // A forged B event pointing to A must fail before the FK is involved.
        // The trigger checks mandate_id, company_id AND service_principal.
        try {
            DB::table('portal_performance_account_mandate_events')->insert([
                'id'=>(string)Str::uuid(),'mandate_id'=>$mandateA,
                'company_id'=>$companyB,'service_principal'=>$principal,
                'revision'=>1,'event_kind'=>'revised',
                'correlation_id'=>(string)Str::uuid(),
            ]);
            $this->fail('Cross-company event accepted for a foreign mandate');
        } catch (QueryException $e) {
            $this->assertSame('23514',(string)$e->getCode(),
                'Cross-company event must be denied by current-revision guard');
        }

        // A second same-scope mandate in A cannot hide a competing authority.
        try {
            DB::table('portal_performance_account_mandates')->insert([
                'id'=>(string)Str::uuid(),'company_id'=>$companyA,
                'provider'=>'google_ads','account_id'=>$account,
                'service_principal'=>$principal,'operation'=>'reports.read',
                'purpose'=>'ads_reporting_read','consent_reference'=>'synthetic-consent-extra',
                'credential_ref'=>'synthetic-vault-extra',
                'status'=>'candidate','source_kind'=>'synthetic_candidate',
                'operational_enabled'=>false,'revision'=>1,'expires_at'=>time()+600,
            ]);
            $this->fail('Duplicate mandate authority for one company/account accepted');
        } catch (QueryException $e) {
            $this->assertSame('23505',(string)$e->getCode(),
                'Same company/provider/account/principal must be unique');
        }

        // Revoking A must add exactly one event in A, without changing B.
        DB::table('portal_performance_account_mandates')->where('id',$mandateA)->update([
            'status'=>'revoked','revoked_at'=>time(),'revision'=>2,
        ]);
        $this->assertSame('revoked',DB::table('portal_performance_account_mandates')
            ->where('id',$mandateA)->value('status'));
        $this->assertSame('candidate',DB::table('portal_performance_account_mandates')
            ->where('id',$mandateB)->value('status'));
        $this->assertSame(1,DB::table('portal_performance_account_mandate_events')
            ->where('mandate_id',$mandateA)->where('company_id',$companyA)
            ->where('revision',2)->where('event_kind','revoked')->count());
        $this->assertSame(1,DB::table('portal_performance_account_mandate_events')
            ->where('mandate_id',$mandateB)->where('company_id',$companyB)->count());
        $this->assertSame(0,DB::table('portal_performance_account_mandates')
            ->whereIn('id',[$mandateA,$mandateB])->where('operational_enabled',true)->count());
    }
}
