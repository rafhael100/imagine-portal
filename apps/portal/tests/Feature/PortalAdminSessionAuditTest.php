<?php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Throwable;

final class PortalAdminSessionAuditTest extends TestCase
{
    private string $email;
    private int $adminId;

    protected function setUp():void
    {
        parent::setUp();
        if(getenv('PORTAL_ADMIN_F135_PG_CI')!=='1')
            $this->markTestSkipped('F135 only runs in disposable PG18 CI');

        DB::beginTransaction();
        $this->email='audit-'.bin2hex(random_bytes(6)).'@example.test';
        $this->adminId=(int)DB::table('portal_admin_users')
            ->insertGetId([
                'email'=>$this->email,
                'password_hash'=>Hash::make('TestOnlyPassword#135!'),
                'totp_secret_ciphertext'=>'encrypted-ci-placeholder',
                'is_active'=>true,
                'last_totp_counter'=>-1,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
    }

    protected function tearDown():void
    {
        if(getenv('PORTAL_ADMIN_F135_PG_CI')==='1' &&
           DB::transactionLevel()>0)DB::rollBack();
        parent::tearDown();
    }

    private function revoke(bool $deactivate=false):int
    {
        $args=['--email'=>$this->email];
        if($deactivate)$args['--deactivate']=true;
        return Artisan::call('portal-admin:invalidate-sessions',$args);
    }

    private function currentVersion():int
    {
        return (int)DB::table('portal_admin_users')
            ->where('id',$this->adminId)->value('session_version');
    }

    public function test_revocation_and_deactivation_each_append_exactly_one_minimal_event():void
    {
        self::assertSame(1,$this->currentVersion());
        self::assertSame(0,$this->revoke());
        self::assertSame(2,$this->currentVersion());
        self::assertSame(0,$this->revoke(true));
        self::assertSame(3,$this->currentVersion());

        $events=DB::table('portal_admin_security_events')
            ->where('admin_user_id',$this->adminId)
            ->orderBy('version_before')->get();
        self::assertCount(2,$events);
        self::assertSame('sessions_revoked',$events[0]->event_code);
        self::assertSame('admin_deactivated',$events[1]->event_code);
        self::assertSame(1,(int)$events[0]->version_before);
        self::assertSame(2,(int)$events[0]->version_after);
        self::assertSame(2,(int)$events[1]->version_before);
        self::assertSame(3,(int)$events[1]->version_after);
        self::assertSame('ci_synthetic_operator',$events[0]->actor_context);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/D',
            $events[0]->event_ref);
        self::assertFalse((bool)DB::table('portal_admin_users')
            ->where('id',$this->adminId)->value('is_active'));

        $encoded=json_encode($events,JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('TestOnlyPassword',$encoded);
        self::assertStringNotContainsString($this->email,$encoded);
        self::assertStringNotContainsString('totp_secret',$encoded);
    }

    public function test_failed_audit_write_rolls_back_session_version_and_deactivation():void
    {
        // Existing F135 append-only function deliberately refuses EVERY
        // mutation. Attach it temporarily to INSERT in this CI transaction.
        DB::statement(
            'CREATE TRIGGER portal_f135_ci_reject_insert
             BEFORE INSERT ON portal_admin_security_events
             FOR EACH ROW EXECUTE FUNCTION
                 public.portal_f135_reject_security_event_mutation()'
        );
        try{
            self::assertSame(1,$this->revoke(true));
            self::assertSame(1,$this->currentVersion());
            self::assertTrue((bool)DB::table('portal_admin_users')
                ->where('id',$this->adminId)->value('is_active'));
            self::assertSame(0,DB::table('portal_admin_security_events')
                ->where('admin_user_id',$this->adminId)->count());
        }finally{
            DB::statement(
                'DROP TRIGGER IF EXISTS portal_f135_ci_reject_insert
                 ON portal_admin_security_events'
            );
        }
        // Once the audit is writable again the command may commit.
        self::assertSame(0,$this->revoke());
        self::assertSame(2,$this->currentVersion());
    }

    public function test_database_prevents_update_and_delete_of_existing_audit_events():void
    {
        self::assertSame(0,$this->revoke());
        $id=(int)DB::table('portal_admin_security_events')
            ->where('admin_user_id',$this->adminId)->value('id');
        self::assertGreaterThan(0,$id);
        foreach(['update','delete'] as $operation){
            $failed=false;
            try{
                DB::transaction(static function()use($id,$operation):void {
                    $query=DB::table('portal_admin_security_events')
                        ->where('id',$id);
                    if($operation==='update')
                        $query->update(['actor_context'=>'staging_tty_unverified']);
                    else $query->delete();
                });
            }catch(Throwable){$failed=true;}
            self::assertTrue($failed,
                'F135 must refuse event modification: '.$operation);
        }
        self::assertSame(1,DB::table('portal_admin_security_events')
            ->where('id',$id)->count());
    }

    public function test_command_without_explicit_ci_authorization_does_not_mutate():void
    {
        $old=getenv('PORTAL_ADMIN_F134_PG_CI');
        putenv('PORTAL_ADMIN_F134_PG_CI=0');
        try{
            self::assertSame(1,$this->revoke());
        }finally{
            if($old===false)putenv('PORTAL_ADMIN_F134_PG_CI');
            else putenv('PORTAL_ADMIN_F134_PG_CI='.$old);
        }
        self::assertSame(1,$this->currentVersion());
        self::assertSame(0,DB::table('portal_admin_security_events')
            ->where('admin_user_id',$this->adminId)->count());
    }
}
