<?php

declare(strict_types=1);
namespace Tests\Feature;
use Tests\TestCase;
use App\Domain\PortalAccess\Security\PortalAdminTotp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

final class AutomationAdministrationTest extends TestCase
{
 private int $actor;
 private string $secret;
 private string $email;
 protected function setUp():void {
  parent::setUp();
  if(getenv('AUTOMATION_ADMIN_PG_CI')!=='1')$this->markTestSkipped('Disposable PostgreSQL CI required');
  DB::beginTransaction();config()->set('session.driver','array');
  $this->email='automation-'.Str::uuid().'@example.test';$this->secret=bin2hex(random_bytes(20));
  $this->actor=(int)DB::table('portal_admin_users')->insertGetId(['email'=>$this->email,'password_hash'=>Hash::make('AutomationLab#Only123'),
   'totp_secret_ciphertext'=>Crypt::encryptString($this->secret),'is_active'=>true,'last_totp_counter'=>-1,'session_version'=>1,'created_at'=>now(),'updated_at'=>now()]);
 }
 protected function tearDown():void{if(DB::transactionLevel()>0)DB::rollBack();parent::tearDown();}
 private function login():void {
  $this->post('/portal-admin/login',['email'=>$this->email,'password'=>'AutomationLab#Only123'])->assertRedirect('/portal-admin/mfa');
  $this->get('/portal-admin/automations')->assertRedirect('/portal-admin/login');
  $this->post('/portal-admin/mfa',['code'=>PortalAdminTotp::code(hex2bin($this->secret),intdiv(time(),30))])->assertRedirect('/portal-admin');
 }
 public function test_mfa_guards_readonly_policy_page_and_filters():void {
  $this->get('/portal-admin/automations')->assertRedirect('/portal-admin/login');$this->login();
  $this->get('/portal-admin/automations')->assertOk()->assertSee('Dados operacionais indisponíveis.')->assertSee('CRM Imagine')->assertSee('Imagine AI Gateway')->assertHeader('X-Robots-Tag','noindex, nofollow');
  $this->get('/portal-admin/automations?product=crm')->assertOk()->assertSee('Regras comerciais por empresa');
  $this->get('/portal-admin/automations?tenant=lab-alfa')->assertUnprocessable();
  $this->get('/portal-admin/automations?product=unknown')->assertSessionHasErrors('product');
  $this->post('/portal-admin/automations',['operation'=>'billing.charge'])->assertStatus(405);
 }
 public function test_current_session_revocation_blocks_the_page():void {
  $this->login();DB::table('portal_admin_users')->where('id',$this->actor)->increment('session_version');
  $this->get('/portal-admin/automations')->assertRedirect('/portal-admin/login');
 }
 public function test_runtime_disable_remains_closed_after_route_registration():void {
  $this->login();config()->set('automation_admin.enabled',false);
  $this->get('/portal-admin/automations')->assertNotFound();
 }
}
