<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\PortalAccess\Security\PortalAdminTotp;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class PortalAdminOrganizationSeoGateTest extends TestCase
{
    private int $adminId;
    private string $email;
    private string $secret;

    protected function setUp():void
    {
        parent::setUp();
        if(getenv('PORTAL_ADMIN_F136_PG_CI')!=='1')
            $this->markTestSkipped('F134 requires disposable PostgreSQL18 CI');
        config()->set('portal_admin.enabled',true);
        config()->set('session.driver','array');
        DB::beginTransaction();
        $this->email='f134-'.bin2hex(random_bytes(5)).'@example.test';
        $this->secret=bin2hex(random_bytes(20));
        $this->adminId=(int)DB::table('portal_admin_users')->insertGetId([
            'email'=>$this->email,
            'password_hash'=>Hash::make('StrongTestPassword#134!'),
            'totp_secret_ciphertext'=>Crypt::encryptString($this->secret),
            'is_active'=>true,
            'last_totp_counter'=>-1,
            'created_at'=>now(),
            'updated_at'=>now(),
        ]);
    }

    protected function tearDown():void
    {
        if(getenv('PORTAL_ADMIN_F136_PG_CI')==='1' &&
           DB::transactionLevel()>0)DB::rollBack();
        parent::tearDown();
    }

    private function login():void
    {
        $this->post('/portal-admin/login',[
            'email'=>$this->email,
            'password'=>'StrongTestPassword#134!'
        ])->assertRedirect('/portal-admin/mfa');
        $this->post('/portal-admin/mfa',[
            'code'=>PortalAdminTotp::code(
                hex2bin($this->secret),intdiv(time(),30)
            )
        ])->assertRedirect('/portal-admin');
    }

    private function grant(
        string $org,string $status='active',?int $expiry=null
    ):void {
        DB::table('portal_admin_org_grants')->insert([
            'admin_user_id'=>$this->adminId,
            'organization_ref'=>$org,
            'permission'=>'seo.view',
            'status'=>$status,
            'expires_at'=>$expiry??time()+3600,
            'revoked_at'=>$status==='revoked'?time():null,
            'created_at'=>now(),
            'updated_at'=>now(),
        ]);
    }

    public function test_password_without_mfa_never_reads_organization_scope():void
    {
        $this->grant('org-a');
        $this->get('/portal-admin/seo-review/org-a')
            ->assertRedirect('/portal-admin/login');
        $this->post('/portal-admin/login',[
            'email'=>$this->email,
            'password'=>'StrongTestPassword#134!'
        ])->assertRedirect('/portal-admin/mfa');
        $this->get('/portal-admin/seo-review/org-a')
            ->assertRedirect('/portal-admin/login');
    }

    public function test_only_active_unexpired_server_grant_opens_empty_review():void
    {
        $this->grant('org-a');
        $this->grant('org-expired','active',time()-20);
        $this->grant('org-revoked','revoked');
        $this->login();

        $this->get('/portal-admin/seo-review/org-a')
            ->assertOk()
            ->assertSee('Revisão SEO')
            ->assertSee('Dados indisponíveis nesta etapa')
            ->assertDontSee('org-a')
            ->assertDontSee('googleapis.com');
        foreach([
            '/portal-admin/seo-review/org-expired',
            '/portal-admin/seo-review/org-revoked',
            '/portal-admin/seo-review/org-unknown',
            '/portal-admin/seo-review/org-revoked?admin_user_id='.
                $this->adminId,
        ] as $route)$this->get($route)->assertNotFound();

        // Membership can be revoked while an existing MFA session is valid.
        DB::table('portal_admin_org_grants')
            ->where('admin_user_id',$this->adminId)
            ->where('organization_ref','org-a')
            ->update([
                'status'=>'revoked','revoked_at'=>time(),
                'updated_at'=>now()
            ]);
        $this->get('/portal-admin/seo-review/org-a')->assertNotFound();
    }

    public function test_another_real_admin_session_cannot_reuse_first_membership():void
    {
        $this->grant('org-a');
        $this->login();
        $this->get('/portal-admin/seo-review/org-a')->assertOk();
        $this->post('/portal-admin/logout')
            ->assertRedirect('/portal-admin/login');

        $email='different-'.bin2hex(random_bytes(5)).'@example.test';
        $secret=bin2hex(random_bytes(20));
        DB::table('portal_admin_users')->insert([
            'email'=>$email,'password_hash'=>Hash::make('OtherPassword#134!'),
            'totp_secret_ciphertext'=>Crypt::encryptString($secret),
            'is_active'=>true,'last_totp_counter'=>-1,
            'created_at'=>now(),'updated_at'=>now()
        ]);
        $this->post('/portal-admin/login',[
            'email'=>$email,'password'=>'OtherPassword#134!'
        ])->assertRedirect('/portal-admin/mfa');
        $this->post('/portal-admin/mfa',[
            'code'=>PortalAdminTotp::code(
                hex2bin($secret),intdiv(time(),30)
            )
        ])->assertRedirect('/portal-admin');
        $this->get('/portal-admin/seo-review/org-a')->assertNotFound();
    }

    public function test_disabled_admin_account_loses_organization_scope():void
    {
        $this->grant('org-a');
        $this->login();
        $this->get('/portal-admin/seo-review/org-a')->assertOk();
        DB::table('portal_admin_users')->where('id',$this->adminId)
            ->update(['is_active'=>false]);
        $this->get('/portal-admin/seo-review/org-a')
            ->assertRedirect('/portal-admin/login');
    }
}
