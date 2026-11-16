<?php
declare(strict_types=1);
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Support\Facades\{DB,Hash,Crypt};
use Illuminate\Support\Str;
use App\Domain\PortalAccess\Security\PortalAdminTotp;
use App\Domain\AutomationAdministration\DatabaseAutomationAuthority;
use App\Domain\PerformanceAdministration\{NativePerformanceAuthority,AuthorizedPerformanceRead};
final class IntegratedProductAuthorityTest extends TestCase {
 private int $actor;private string $company;private string $secret;private string $email;
 protected function setUp():void {
  parent::setUp();if(getenv('ADMIN_PHASE5_CI')!=='1')$this->markTestSkipped('Owned combined CI database required');
  $this->company=(string)Str::uuid();$this->secret=bin2hex(random_bytes(20));$this->email='phase5-'.Str::uuid().'@example.test';
  config()->set('session.driver','array');config()->set('automation_admin.transport_enabled',true);config()->set('performance_admin.native_read_enabled',true);
  $this->actor=DB::table('portal_admin_users')->insertGetId(['email'=>$this->email,'password_hash'=>Hash::make('SyntheticPhase5#Password'),'totp_secret_ciphertext'=>Crypt::encryptString($this->secret),'is_active'=>true,'last_totp_counter'=>-1,'session_version'=>1,'created_at'=>now(),'updated_at'=>now()]);
  $this->post('/portal-admin/login',['email'=>$this->email,'password'=>'SyntheticPhase5#Password'])->assertRedirect('/portal-admin/mfa');
  $this->post('/portal-admin/mfa',['code'=>PortalAdminTotp::code(hex2bin($this->secret),intdiv(time(),30))])->assertRedirect('/portal-admin');
 }
 protected function tearDown():void {
  if(isset($this->actor)){
   foreach(['portal_automation_access_audit','portal_automation_grants','portal_performance_access_audit','portal_performance_grants'] as $table)DB::table($table)->where('admin_user_id',$this->actor)->delete();
   DB::table('portal_performance_services')->where('company_id',$this->company)->delete();DB::table('portal_admin_users')->where('id',$this->actor)->delete();
  }parent::tearDown();
 }
 private function automation():void {DB::table('portal_automation_grants')->insert(['id'=>(string)Str::uuid(),'admin_user_id'=>$this->actor,'company_id'=>$this->company,'operation'=>'automation.state.read','revision'=>1,'state'=>'active','expires_at'=>time()+600,'created_at'=>now(),'updated_at'=>now()]);}
 private function performance():void {
  DB::table('portal_performance_services')->insert(['company_id'=>$this->company,'contract_ref'=>(string)Str::uuid(),'contract_version'=>1,'company_active'=>true,'status'=>'active','source_kind'=>'synthetic_persisted','expires_at'=>time()+600]);
  DB::table('portal_performance_grants')->insert(['id'=>(string)Str::uuid(),'admin_user_id'=>$this->actor,'company_id'=>$this->company,'operation'=>'dashboard.read','role'=>'analyst','revision'=>1,'expires_at'=>time()+600]);
 }
 public function test_automation_grant_cannot_call_performance_source():void {
  $this->automation();$this->assertTrue((new DatabaseAutomationAuthority)->allows($this->actor,$this->company));$calls=0;
  $reader=new AuthorizedPerformanceRead(new NativePerformanceAuthority,function()use(&$calls){$calls++;return [];});
  try{$reader->read($this->company,'dashboard.read',(string)Str::uuid());$this->fail('Cross-product grant accepted');}catch(\RuntimeException){$this->assertSame(0,$calls);}
 }
 public function test_performance_grant_never_grants_automation():void {
  $this->performance();$this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/',(new NativePerformanceAuthority)->lease($this->company,'dashboard.read',(string)Str::uuid()));
  $this->assertFalse((new DatabaseAutomationAuthority)->allows($this->actor,$this->company));
 }
 public function test_both_specific_grants_work_and_administrator_revocation_denies_both():void {
  $this->performance();$this->automation();$this->assertTrue((new DatabaseAutomationAuthority)->allows($this->actor,$this->company));
  $this->assertNotEmpty((new NativePerformanceAuthority)->lease($this->company,'dashboard.read',(string)Str::uuid()));
  DB::table('portal_admin_users')->where('id',$this->actor)->increment('session_version');$this->assertFalse((new DatabaseAutomationAuthority)->allows($this->actor,$this->company));
  $this->expectExceptionMessage('PERFORMANCE_AUTHORITY_DENIED');(new NativePerformanceAuthority)->lease($this->company,'dashboard.read',(string)Str::uuid());
 }
 public function test_runtime_database_credentials_are_not_interchangeable():void {
  $this->performance();$this->automation();$this->assertNotSame(config('database.connections.automation_authority.username'),config('database.connections.performance_authority.username'));
  foreach([['automation_authority','portal_performance_grants'],['performance_authority','portal_automation_grants']] as [$connection,$table]){
   try{DB::connection($connection)->table($table)->count();$this->fail('Cross-product SQL access');}catch(\Illuminate\Database\QueryException){$this->assertTrue(true);}
  }
 }
 public function test_contract_replacement_during_read_invalidates_the_exact_lease():void {
  $this->performance();$id=(string)Str::uuid();
  $reader=new AuthorizedPerformanceRead(new NativePerformanceAuthority,function(){DB::table('portal_performance_services')->where('company_id',$this->company)->update(['contract_ref'=>(string)Str::uuid()]);return ['status'=>'synthetic'];});
  try{$reader->read($this->company,'dashboard.read',$id);$this->fail('Changed contract accepted');}catch(\RuntimeException $e){$this->assertSame('PERFORMANCE_AUTHORITY_CHANGED',$e->getMessage());}
  $this->assertSame(0,DB::table('portal_performance_access_audit')->where('correlation_id',$id)->where('outcome','completed')->count());
 }
 public function test_completion_audit_cannot_substitute_a_different_authority_lease():void {
  $this->performance();$id=(string)Str::uuid();$authority=new NativePerformanceAuthority;$lease=$authority->lease($this->company,'dashboard.read',$id);
  DB::table('portal_performance_grants')->where('admin_user_id',$this->actor)->increment('revision');
  try{$authority->outcome($this->company,'dashboard.read',$id,'completed',$lease);$this->fail('Completion changed authority');}catch(\RuntimeException $e){$this->assertSame('PERFORMANCE_AUTHORITY_CHANGED',$e->getMessage());}
  $this->assertSame(0,DB::table('portal_performance_access_audit')->where('correlation_id',$id)->where('outcome','completed')->count());
 }

}
