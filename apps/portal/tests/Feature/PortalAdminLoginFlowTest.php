<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\PortalAccess\Security\PortalAdminTotp;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class PortalAdminLoginFlowTest extends TestCase
{
    private string $email;
    private string $secret;
    private int $adminId;

    protected function setUp():void
    {
        parent::setUp();
        // The default generic Laravel workflow has NO database. Run only
        // in the dedicated disposable PostgreSQL18 F132 matrix.
        if(getenv('PORTAL_ADMIN_F132_PG_CI')!=='1')
            $this->markTestSkipped('F132 uses disposable PostgreSQL18 CI');

        config()->set('portal_admin.enabled',true);
        config()->set('session.driver','array');
        DB::beginTransaction();
        $this->email='admin-'.bin2hex(random_bytes(5)).'@example.test';
        $this->secret=bin2hex(random_bytes(20));
        $this->adminId=(int)DB::table('portal_admin_users')->insertGetId([
            'email'=>$this->email,
            'password_hash'=>Hash::make('VeryLongPassword#132!'),
            'totp_secret_ciphertext'=>Crypt::encryptString($this->secret),
            'is_active'=>true,
            'last_totp_counter'=>-1,
            'created_at'=>now(),
            'updated_at'=>now(),
        ]);
    }

    protected function tearDown():void
    {
        if(getenv('PORTAL_ADMIN_F132_PG_CI')==='1' &&
           DB::transactionLevel()>0)DB::rollBack();
        parent::tearDown();
    }

    private function password(string $password='VeryLongPassword#132!'):array
    {
        return ['email'=>$this->email,'password'=>$password];
    }

    private function otp():string
    {
        return PortalAdminTotp::code(
            hex2bin($this->secret),intdiv(time(),30)
        );
    }

    public function test_password_alone_never_issues_admin_session():void
    {
        $this->get('/portal-admin/login')
            ->assertOk()->assertSee('Portal Administrativo');
        $this->post('/portal-admin/login',$this->password())
            ->assertRedirect('/portal-admin/mfa');
        $this->get('/portal-admin')->assertRedirect('/portal-admin/login');
        $this->assertGuest('portal-admin');
    }

    public function test_mfa_required_and_once_only_then_logout_invalidates_session():void
    {
        $this->post('/portal-admin/login',$this->password())
            ->assertRedirect('/portal-admin/mfa');
        $this->post('/portal-admin/mfa',['code'=>'999XYZ'])
            ->assertSessionHasErrors();
        $this->post('/portal-admin/mfa',['code'=>$this->otp()])
            ->assertRedirect('/portal-admin');
        $this->assertAuthenticatedAs(
            \App\Models\PortalAdminUser::findOrFail($this->adminId),
            'portal-admin'
        );
        $this->get('/portal-admin')->assertOk()
            ->assertSee('Administração autenticada')
            ->assertSee('Sem autorização de clientes')
            ->assertDontSee('googleapis.com');

        $this->post('/portal-admin/logout')
            ->assertRedirect('/portal-admin/login');
        $this->get('/portal-admin')
            ->assertRedirect('/portal-admin/login');

        // A new password challenge does not reset the stored one-time
        // counter. Reusing the exact same OTP interval must fail.
        $this->post('/portal-admin/login',$this->password())
            ->assertRedirect('/portal-admin/mfa');
        $this->post('/portal-admin/mfa',['code'=>$this->otp()])
            ->assertSessionHasErrors('code');
        $this->assertGuest('portal-admin');
    }

    public function test_wrong_password_is_generic_and_rate_limited():void
    {
        for($n=0;$n<5;$n++){
            $this->post('/portal-admin/login',
                $this->password('wrong-pass-'.$n))
                ->assertSessionHasErrors('credentials');
        }
        $this->post('/portal-admin/login',$this->password())
            ->assertSessionHasErrors('credentials');
        $this->assertGuest('portal-admin');
    }

    public function test_mfa_without_pending_password_or_after_expiry_is_denied():void
    {
        $this->post('/portal-admin/mfa',['code'=>$this->otp()])
            ->assertRedirect('/portal-admin/login');
        $this->withSession([
            'portal_admin_pending_id'=>$this->adminId,
            'portal_admin_pending_at'=>time()-600
        ])->post('/portal-admin/mfa',['code'=>$this->otp()])
            ->assertRedirect('/portal-admin/login');
        $this->assertGuest('portal-admin');
    }

    public function test_inactive_admin_cannot_start_password_session():void
    {
        DB::table('portal_admin_users')->where('id',$this->adminId)
            ->update(['is_active'=>false]);
        $this->post('/portal-admin/login',$this->password())
            ->assertSessionHasErrors('credentials');
        $this->assertGuest('portal-admin');
    }

    public function test_deactivated_admin_is_refused_even_after_mfa_login():void
    {
        $this->post('/portal-admin/login',$this->password())
            ->assertRedirect('/portal-admin/mfa');
        $this->post('/portal-admin/mfa',['code'=>$this->otp()])
            ->assertRedirect('/portal-admin');
        $this->get('/portal-admin')->assertOk();
        DB::table('portal_admin_users')->where('id',$this->adminId)
            ->update(['is_active'=>false]);
        $this->get('/portal-admin')
            ->assertRedirect('/portal-admin/login');
        $this->assertGuest('portal-admin');
    }

    public function test_password_and_mfa_do_not_create_client_routes():void
    {
        $this->post('/portal-admin/login',$this->password())
            ->assertRedirect('/portal-admin/mfa');
        $this->post('/portal-admin/mfa',['code'=>$this->otp()])
            ->assertRedirect('/portal-admin');
        foreach(['/portal','/portal/services','/api/seo/build',
            '/portal/google/oauth/connect','/register'] as $path)
            $this->get($path)->assertNotFound();
    }
}
