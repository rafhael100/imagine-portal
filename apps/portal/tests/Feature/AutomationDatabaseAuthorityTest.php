<?php
declare(strict_types=1);
namespace Tests\Feature;
use Tests\TestCase;
use App\Domain\AutomationAdministration\DatabaseAutomationAuthority;
use App\Domain\AutomationAdministration\AutomationStateReader;
use App\Domain\PortalAccess\Security\PortalAdminTotp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

final class AutomationDatabaseAuthorityTest extends TestCase
{
 private int $actor; private string $secret;private string $email;
 private const COMPANY='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
 protected function setUp():void {
  parent::setUp();if(getenv('P20_AUTHORITY_PG_CI')!=='1')$this->markTestSkipped('Dedicated disposable PostgreSQL CI required');
  config()->set('session.driver','array');config()->set('automation_admin.transport_enabled',true);
  $this->email='p20-'.Str::uuid().'@example.test';$this->secret=bin2hex(random_bytes(20));
  $this->actor=(int)DB::table('portal_admin_users')->insertGetId(['email'=>$this->email,'password_hash'=>Hash::make('SyntheticP20#Password'),
   'totp_secret_ciphertext'=>Crypt::encryptString($this->secret),'is_active'=>true,'last_totp_counter'=>-1,'session_version'=>1,'created_at'=>now(),'updated_at'=>now()]);
 }
 protected function tearDown():void {
  if(isset($this->actor)){
   DB::statement('GRANT INSERT ON portal_automation_access_audit TO portal_automation_runtime');
   DB::table('portal_automation_access_audit')->where('admin_user_id',$this->actor)->delete();
   DB::table('portal_automation_grants')->where('admin_user_id',$this->actor)->delete();
   DB::table('portal_admin_org_grants')->where('admin_user_id',$this->actor)->delete();
   DB::table('portal_admin_users')->where('id',$this->actor)->delete();
  }DB::purge('automation_authority');parent::tearDown();
 }
 private function login():void {
  $this->post('/portal-admin/login',['email'=>$this->email,'password'=>'SyntheticP20#Password'])->assertRedirect('/portal-admin/mfa');
  $this->post('/portal-admin/mfa',['code'=>PortalAdminTotp::code(hex2bin($this->secret),intdiv(time(),30))])->assertRedirect('/portal-admin');
 }
 private function grant(string $company=self::COMPANY):void {
  DB::table('portal_automation_grants')->insert(['id'=>(string)Str::uuid(),'admin_user_id'=>$this->actor,'company_id'=>$company,'operation'=>'automation.state.read','revision'=>1,'state'=>'active','expires_at'=>time()+600,'created_at'=>now(),'updated_at'=>now()]);
 }
 private function check(string $company=self::COMPANY):bool {return (new DatabaseAutomationAuthority)->allows($this->actor,$company);}
 private function state(string $company):array {return ['schema'=>'CRM_AUTOMATION_STATE_V1','source_kind'=>'synthetic_private_database','company_id'=>$company,'observed_at'=>time(),'settings_version'=>0,'counts'=>['pending'=>1,'completed'=>0,'cancelled'=>0,'failed'=>0],'events'=>[],'ai_provider_calls'=>0,'operational_authorization'=>false];}
 public function test_password_without_mfa_never_uses_the_company_grant():void {
  $this->grant();$this->post('/portal-admin/login',['email'=>$this->email,'password'=>'SyntheticP20#Password']);
  $this->assertFalse($this->check());
 }
 public function test_nominal_mfa_and_specific_persisted_grant_are_required():void {
  $this->login();$this->assertFalse($this->check());$this->grant();$this->assertTrue($this->check());
  $this->assertFalse($this->check('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'));
  $this->assertFalse((new DatabaseAutomationAuthority)->allows($this->actor+999,self::COMPANY));
  $this->assertGreaterThanOrEqual(3,DB::table('portal_automation_access_audit')->where('admin_user_id',$this->actor)->count());
 }
 public function test_seo_permission_cannot_authorize_automation():void {
  $this->login();DB::table('portal_admin_org_grants')->insert(['admin_user_id'=>$this->actor,'organization_ref'=>self::COMPANY,'permission'=>'seo.view','status'=>'active','expires_at'=>time()+600,'created_at'=>now(),'updated_at'=>now()]);
  $this->assertFalse($this->check());
 }
 public function test_expired_revoked_and_disabled_transport_are_denied():void {
  $this->login();$this->grant();DB::table('portal_automation_grants')->where('admin_user_id',$this->actor)->update(['expires_at'=>time()-1]);
  $this->assertFalse($this->check());DB::table('portal_automation_grants')->where('admin_user_id',$this->actor)->update(['expires_at'=>time()+600,'state'=>'revoked','revoked_at'=>time(),'revision'=>2]);
  $this->assertFalse($this->check());config()->set('automation_admin.transport_enabled',false);$this->assertFalse($this->check());
 }
 public function test_session_epoch_and_admin_deactivation_are_revalidated():void {
  $this->login();$this->grant();$this->assertTrue($this->check());
  DB::table('portal_admin_users')->where('id',$this->actor)->increment('session_version');$this->assertFalse($this->check());
  DB::table('portal_admin_users')->where('id',$this->actor)->update(['session_version'=>1,'is_active'=>false]);$this->assertFalse($this->check());
 }
 public function test_revocation_during_source_io_denies_the_existing_reader():void {
  $this->login();$this->grant();
  $reader=new AutomationStateReader(function($company){DB::table('portal_automation_grants')->where('admin_user_id',$this->actor)->update(['state'=>'revoked','revoked_at'=>time(),'revision'=>2]);return $this->state($company);},fn($actor,$company)=>(new DatabaseAutomationAuthority)->allows($actor,$company));
  $this->expectException(\RuntimeException::class);$this->expectExceptionMessage('AUTOMATION_READ_REVOKED');$reader->read($this->actor,self::COMPANY);
 }
 public function test_audit_failure_and_unsafe_database_role_fail_closed():void {
  $this->login();$this->grant();DB::statement('REVOKE INSERT ON portal_automation_access_audit FROM portal_automation_runtime');
  $this->assertFalse($this->check());DB::statement('GRANT INSERT ON portal_automation_access_audit TO portal_automation_runtime');
  DB::purge('automation_authority');config()->set('database.connections.automation_authority.username','postgres');config()->set('database.connections.automation_authority.password',getenv('DB_PASSWORD'));
  $this->assertFalse($this->check());
 }
 public function test_authority_unavailable_and_rls_without_context_return_no_access():void {
  $this->login();$this->grant();$db=DB::connection('automation_authority');
  $this->assertSame(0,$db->table('portal_automation_grants')->count());
  DB::purge('automation_authority');config()->set('database.connections.automation_authority.port','1');
  $this->assertFalse($this->check());
 }
 public function test_existing_reader_binding_remains_closed_after_transport_disable():void {
  $this->login();$this->grant();(new \App\Providers\AppServiceProvider(app()))->register();
  $this->assertTrue(app()->bound(AutomationStateReader::class));$reader=app(AutomationStateReader::class);
  config()->set('automation_admin.transport_enabled',false);
  $this->expectException(\RuntimeException::class);$this->expectExceptionMessage('AUTOMATION_READ_DENIED');$reader->read($this->actor,self::COMPANY);
 }
 public function test_production_cannot_reuse_the_ci_mode():void {
  $this->login();$this->grant();$this->app->detectEnvironment(fn()=>'production');$this->assertFalse($this->check());
 }

}
