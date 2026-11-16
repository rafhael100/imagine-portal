<?php
declare(strict_types=1);
namespace Tests\Feature;
use Tests\TestCase;
use App\Domain\PerformanceAdministration\NativePerformanceAuthority;
use App\Domain\PerformanceAdministration\AuthorizedPerformanceRead;
use App\Domain\PortalAccess\Security\PortalAdminTotp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
final class NativePerformanceAuthorityTest extends TestCase {
 private int $actor;private string $secret;private string $email;private string $company;
 protected function setUp():void {
  parent::setUp();if(getenv('ADMIN_PHASE4_PG_CI')!=='1')$this->markTestSkipped('Disposable Phase4 PostgreSQL required');
  config()->set('session.driver','array');config()->set('performance_admin.enabled',true);config()->set('performance_admin.mode','isolated_ci');config()->set('performance_admin.native_read_enabled',true);
  $this->company=(string)Str::uuid();$this->email='phase4-'.Str::uuid().'@example.test';$this->secret=bin2hex(random_bytes(20));
  $this->actor=(int)DB::table('portal_admin_users')->insertGetId(['email'=>$this->email,'password_hash'=>Hash::make('SyntheticPhase4#Password'),
   'totp_secret_ciphertext'=>Crypt::encryptString($this->secret),'is_active'=>true,'last_totp_counter'=>-1,'session_version'=>1,'created_at'=>now(),'updated_at'=>now()]);
 }
 protected function tearDown():void {
  if(isset($this->actor)){
   DB::statement('GRANT INSERT ON portal_performance_access_audit TO portal_performance_runtime');
   DB::table('portal_performance_access_audit')->where('admin_user_id',$this->actor)->delete();DB::table('portal_performance_grants')->where('admin_user_id',$this->actor)->delete();
   DB::table('portal_performance_services')->where('company_id',$this->company)->delete();DB::table('portal_admin_org_grants')->where('admin_user_id',$this->actor)->delete();DB::table('portal_admin_users')->where('id',$this->actor)->delete();
  }DB::purge('performance_authority');parent::tearDown();
 }
 private function login():void {
  $this->post('/portal-admin/login',['email'=>$this->email,'password'=>'SyntheticPhase4#Password'])->assertRedirect('/portal-admin/mfa');
  $this->post('/portal-admin/mfa',['code'=>PortalAdminTotp::code(hex2bin($this->secret),intdiv(time(),30))])->assertRedirect('/portal-admin');
 }
 private function grant():void {
  DB::table('portal_performance_services')->insert(['company_id'=>$this->company,'contract_ref'=>(string)Str::uuid(),'contract_version'=>1,'company_active'=>true,'status'=>'active','source_kind'=>'synthetic_persisted','expires_at'=>time()+600]);
  DB::table('portal_performance_grants')->insert(['id'=>(string)Str::uuid(),'admin_user_id'=>$this->actor,'company_id'=>$this->company,'operation'=>'dashboard.read','role'=>'analyst','revision'=>1,'expires_at'=>time()+600]);
 }
 private function lease(?string $company=null,string $op='dashboard.read'):string {return (new NativePerformanceAuthority)->lease($company??$this->company,$op,(string)Str::uuid());}
 private function denied(callable $call):void {try{$call();$this->fail('Unexpected authorization');}catch(\RuntimeException $e){$this->assertSame('PERFORMANCE_AUTHORITY_DENIED',$e->getMessage());}}
 public function test_password_mfa_and_persisted_service_grant_are_all_required():void {
  $this->grant();$this->post('/portal-admin/login',['email'=>$this->email,'password'=>'SyntheticPhase4#Password']);$this->denied(fn()=>$this->lease());
  $this->post('/portal-admin/mfa',['code'=>PortalAdminTotp::code(hex2bin($this->secret),intdiv(time(),30))]);$this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$this->lease());
  $this->denied(fn()=>$this->lease((string)Str::uuid()));$this->denied(fn()=>$this->lease(null,'reports.read'));
 }
 public function test_seo_grant_and_uncontracted_company_never_authorize_performance():void {
  $this->login();DB::table('portal_admin_org_grants')->insert(['admin_user_id'=>$this->actor,'organization_ref'=>$this->company,'permission'=>'seo.view','status'=>'active','expires_at'=>time()+600,'created_at'=>now(),'updated_at'=>now()]);
  $this->denied(fn()=>$this->lease());
 }
 public function test_suspended_cancelled_expired_and_revoked_services_are_denied():void {
  $this->login();$this->grant();foreach(['suspended','cancelled'] as $state){DB::table('portal_performance_services')->where('company_id',$this->company)->update(['status'=>$state]);$this->denied(fn()=>$this->lease());}
  DB::table('portal_performance_services')->where('company_id',$this->company)->update(['status'=>'active','expires_at'=>time()-1]);$this->denied(fn()=>$this->lease());
  DB::table('portal_performance_services')->where('company_id',$this->company)->update(['expires_at'=>time()+600,'revoked_at'=>time()]);$this->denied(fn()=>$this->lease());
 }
 public function test_session_revocation_and_mfa_expiry_are_current():void {
  $this->login();$this->grant();$this->withSession(['portal_admin_mfa_at'=>time()-901]);$this->denied(fn()=>$this->lease());
  $this->withSession(['portal_admin_mfa_at'=>time()]);DB::table('portal_admin_users')->where('id',$this->actor)->increment('session_version');$this->denied(fn()=>$this->lease());
 }
 public function test_grant_revision_change_during_transport_hides_the_response():void {
  $this->login();$this->grant();$read=new AuthorizedPerformanceRead(new NativePerformanceAuthority,function(){DB::table('portal_performance_grants')->where('admin_user_id',$this->actor)->increment('revision');return ['status'=>'synthetic'];});
  $this->expectExceptionMessage('PERFORMANCE_AUTHORITY_CHANGED');$read->read($this->company,'dashboard.read',(string)Str::uuid());
 }
 public function test_audit_failure_unsafe_role_and_database_outage_fail_closed():void {
  $this->login();$this->grant();DB::statement('REVOKE INSERT ON portal_performance_access_audit FROM portal_performance_runtime');$this->denied(fn()=>$this->lease());DB::statement('GRANT INSERT ON portal_performance_access_audit TO portal_performance_runtime');
  DB::purge('performance_authority');config()->set('database.connections.performance_authority.username','postgres');config()->set('database.connections.performance_authority.password',getenv('DB_PASSWORD'));$this->denied(fn()=>$this->lease());
  DB::purge('performance_authority');config()->set('database.connections.performance_authority.port','1');$this->denied(fn()=>$this->lease());
 }
 public function test_runtime_has_no_cross_company_scope_and_cannot_change_grants():void {
  $this->login();$this->grant();$db=DB::connection('performance_authority');$this->assertSame(0,$db->table('portal_performance_services')->count());$this->assertSame(0,$db->table('portal_performance_grants')->count());
  $this->expectException(\Illuminate\Database\QueryException::class);$db->table('portal_performance_grants')->where('admin_user_id',$this->actor)->update(['role'=>'superadmin']);
 }
 public function test_production_source_promotion_is_deliberately_blocked():void {
  $this->login();$this->grant();DB::table('portal_performance_services')->where('company_id',$this->company)->update(['source_kind'=>'portal_contract_authority']);$this->denied(fn()=>$this->lease());
  $this->app->detectEnvironment(fn()=>'production');$this->denied(fn()=>$this->lease());
 }
 public function test_native_login_mfa_to_real_private_performance_and_correlated_audits():void {
  if(getenv('ADMIN_PHASE4_HTTPS_CI')!=='1')$this->markTestSkipped('Separate HTTPS process required');
  $this->company='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';$this->login();$this->grant();
  $tls=getenv('ADMIN_PHASE4_TLS');config()->set('performance_admin.service_review_enabled',true);
  config()->set('performance_admin.engine_root',getenv('PERFORMANCE_ADMIN_LAB_ENGINE_ROOT'));
  config()->set('performance_admin.service_identity_file',$tls.'/identity.json');
  config()->set('performance_admin.service_review_url','https://performance-ci.internal:19444/v1/admin/read');
  config()->set('performance_admin.service_review_host','performance-ci.internal');config()->set('performance_admin.service_review_port',19444);config()->set('performance_admin.service_review_address','172.30.250.2');
  foreach(['ca'=>'ca.pem','certificate'=>'client.pem','private_key'=>'client.key'] as $key=>$file)config()->set('performance_admin.service_review_'.$key,$tls.'/'.$file);
  $id=(string)Str::uuid();$reader=app(AuthorizedPerformanceRead::class);$result=$reader->read($this->company,'dashboard.read',$id);
  $this->assertSame('unavailable',$result['status']);$this->assertNull($result['metrics']);$this->assertFalse($result['billable']);
  $this->assertEquals($result,$reader->read($this->company,'dashboard.read',$id));
  $this->get('/portal-admin/performance-private/'.$this->company)->assertOk()->assertSee('Dados indisponíveis')->assertSee('Não informado pela fonte');
  $this->assertSame(2,DB::table('portal_performance_access_audit')->where('correlation_id',$id)->where('outcome','completed')->count());
  $owner=new \PDO('pgsql:host=127.0.0.1;dbname=performance_receiver_phase4_ci','postgres',getenv('DB_PASSWORD'));
  $q=$owner->prepare("SELECT count(*) FROM performance_admin_inbox.request_audit WHERE correlation_id=? AND outcome='read_result'");$q->execute([$id]);$this->assertSame(2,(int)$q->fetchColumn());
  $owner->exec('UPDATE performance_admin_inbox.service_delegations SET active=false,revoked_at=now()');
  $this->expectException(\RuntimeException::class);$reader->read($this->company,'dashboard.read',$id);
 }

}
