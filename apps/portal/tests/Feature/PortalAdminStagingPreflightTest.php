<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\PortalAccess\Security\PortalAdminStagingSecurityPolicy as Policy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PortalAdminStagingPreflightTest extends TestCase
{
    private array $oldConfig=[];

    protected function setUp():void
    {
        parent::setUp();
        if(getenv('PORTAL_ADMIN_F137_PG_CI')!=='1')
            $this->markTestSkipped('F137 preflight only in disposable PG18');

        foreach([
            'portal_admin.enabled','app.url','app.debug','app.key',
            'database.default','cache.default','session.driver','session.encrypt',
            'session.secure','session.http_only','session.same_site',
        ] as $name) $this->oldConfig[$name]=config($name);

        $this->safeConfig();
    }

    protected function tearDown():void
    {
        foreach($this->oldConfig as $key=>$value)
            config()->set($key,$value);
        parent::tearDown();
    }

    private function safeConfig():void
    {
        config()->set('portal_admin.enabled',true);
        config()->set('app.url','https://portal-staging.example.test');
        config()->set('app.debug',false);
        config()->set('app.key','base64:'.base64_encode(random_bytes(32)));
        config()->set('database.default','pgsql');
        config()->set('cache.default','database');
        config()->set('session.driver','database');
        config()->set('session.encrypt',true);
        config()->set('session.secure',true);
        config()->set('session.http_only',true);
        config()->set('session.same_site','lax');
    }

    public function test_secure_staging_configuration_and_schema_passes_preflight():void
    {
        self::assertSame([],Policy::issues('staging'));
        foreach(['sessions','cache','cache_locks','portal_admin_users',
            'portal_admin_security_events','portal_admin_org_grants'
        ] as $table)self::assertTrue(Schema::hasTable($table));
        self::assertTrue(Schema::hasColumn(
            'portal_admin_users','session_version'
        ));
        self::assertSame(0,Artisan::call('portal-admin:staging-preflight'));
        self::assertStringNotContainsString(
            config('app.key'),Artisan::output()
        );
        self::assertFalse(Policy::authorizesCustomerDataAccess());
        self::assertFalse(Policy::authorizesProductionDeployment());

        // The conventional Laravel database sessions schema persists
        // records without requiring a Redis or CRM database.
        $id=bin2hex(random_bytes(20));
        DB::table('sessions')->insert([
            'id'=>$id,'user_id'=>null,'ip_address'=>'127.0.0.1',
            'user_agent'=>'F137 synthetic test','payload'=>'fake-session-fixture',
            'last_activity'=>time()
        ]);
        try{
            self::assertSame('fake-session-fixture',
                DB::table('sessions')->where('id',$id)->value('payload'));
        }finally{
            DB::table('sessions')->where('id',$id)->delete();
        }
    }

    public function test_http_and_in_memory_sessions_are_rejected():void
    {
        foreach([
            ['app.url','http://portal-staging.example.test'],
            ['session.driver','array'],
            ['cache.default','array'],
            ['session.encrypt',false],
            ['session.secure',false],
            ['session.http_only',false],
            ['session.same_site','none'],
            ['app.debug',true],
            ['database.default','sqlite'],
            ['app.key',''],
            ['portal_admin.enabled',false],
        ] as [$name,$bad]){
            $original=config($name);
            config()->set($name,$bad);
            self::assertNotSame([],Policy::issues('staging'),$name);
            // Changing DB connection here would cause Schema::hasTable to
            // query the wrong database. In that one case test policy only.
            if($name!=='database.default')
                self::assertSame(1,Artisan::call(
                    'portal-admin:staging-preflight'
                ),$name);
            config()->set($name,$original);
        }
    }

    public function test_no_production_or_development_promotion_from_policy():void
    {
        self::assertContains('STAGING_ENV_REQUIRED',Policy::issues('production'));
        self::assertContains('STAGING_ENV_REQUIRED',Policy::issues('local'));
        $flag=getenv('PORTAL_ADMIN_F137_PG_CI');
        putenv('PORTAL_ADMIN_F137_PG_CI=0');
        try{
            self::assertSame(1,Artisan::call(
                'portal-admin:staging-preflight'
            ));
        }finally{
            if($flag===false)putenv('PORTAL_ADMIN_F137_PG_CI');
            else putenv('PORTAL_ADMIN_F137_PG_CI='.$flag);
        }
    }
}

