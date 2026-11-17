<?php
declare(strict_types=1);
namespace Tests\Feature;
use App\Domain\PerformanceAdministration\AuthorizedPerformanceRead;
use App\Domain\PerformanceAdministration\NativePerformanceAuthority;
use App\Domain\PortalAccess\Security\PortalAdminTotp;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

final class NativePerformanceReadNavigationTest extends TestCase
{
 private int $actor;private string $company;private array $calls=[];
 protected function setUp():void {
  parent::setUp();if(getenv('ADMIN_PHASE4_PG_CI')!=='1')$this->markTestSkipped('Disposable native-authority PostgreSQL required');
  config()->set('session.driver','array');config()->set('performance_admin.enabled',true);config()->set('performance_admin.mode','isolated_ci');config()->set('performance_admin.native_read_enabled',true);
  $this->company=(string)Str::uuid();$email='navigation-'.Str::uuid().'@example.test';$secret=bin2hex(random_bytes(20));
  $this->actor=(int)DB::table('portal_admin_users')->insertGetId(['email'=>$email,'password_hash'=>Hash::make('SyntheticNavigation#Password'),
   'totp_secret_ciphertext'=>Crypt::encryptString($secret),'is_active'=>true,'last_totp_counter'=>-1,'session_version'=>1,'created_at'=>now(),'updated_at'=>now()]);
  DB::table('portal_performance_services')->insert(['company_id'=>$this->company,'contract_ref'=>(string)Str::uuid(),'contract_version'=>1,'company_active'=>true,'status'=>'active','source_kind'=>'synthetic_persisted','expires_at'=>time()+600]);
  $this->post('/portal-admin/login',['email'=>$email,'password'=>'SyntheticNavigation#Password'])->assertRedirect('/portal-admin/mfa');
  $this->post('/portal-admin/mfa',['code'=>PortalAdminTotp::code(hex2bin($secret),intdiv(time(),30))])->assertRedirect('/portal-admin');
  $this->app->instance(AuthorizedPerformanceRead::class,new AuthorizedPerformanceRead(new NativePerformanceAuthority,function($company,$operation,$correlation,$query){
   $this->calls[]=compact('company','operation','correlation','query');
   return ['version'=>'imagine.performance.admin.s2s.v1','company_id'=>$company,'operation'=>$operation,'correlation_id'=>$correlation,
    'status'=>'unavailable','metrics'=>null,'external_operations_enabled'=>false,'billable'=>false];
  }));
 }
 protected function tearDown():void {
  if(isset($this->actor)){
   DB::table('portal_performance_access_audit')->where('admin_user_id',$this->actor)->delete();
   DB::table('portal_performance_grants')->where('admin_user_id',$this->actor)->delete();
   DB::table('portal_performance_services')->where('company_id',$this->company)->delete();
   DB::table('performance_admin_lab_memberships')->where('admin_user_id',$this->actor)->delete();
   DB::table('portal_admin_users')->where('id',$this->actor)->delete();
  }
  DB::purge('performance_authority');parent::tearDown();
 }
 private function grant(string $operation):void {
  DB::table('portal_performance_grants')->insert(['id'=>(string)Str::uuid(),'admin_user_id'=>$this->actor,'company_id'=>$this->company,
   'operation'=>$operation,'role'=>'analyst','revision'=>1,'expires_at'=>time()+600]);
 }
 private function url(?string $module=null,array $query=[]):string {
  return '/portal-admin/performance-private/'.$this->company.($module===null?'':'/'.$module).($query===[]?'':'?'.http_build_query($query));
 }
 public function test_clients_or_reports_only_can_select_their_company_without_dashboard():void {
  foreach(['clients'=>'Consulta de clientes.','reports'=>'Consulta de relatórios.'] as $module=>$heading){
   DB::table('portal_performance_grants')->where('admin_user_id',$this->actor)->delete();$this->grant($module.'.read');
   $this->get('/portal-admin/performance-private')->assertOk()->assertSee('Abrir empresa '.$this->company)->assertDontSee('Abrir os dez módulos');
   $this->get($this->url())->assertOk()->assertSee($heading)->assertSee('Dados indisponíveis')->assertSee('aria-current="page"',false)
    ->assertDontSee($this->url('dashboard'),false);
   $this->assertSame($module.'.read',$this->calls[array_key_last($this->calls)]['operation']);
   $before=count($this->calls);$this->get($this->url('dashboard'))->assertForbidden();$this->assertCount($before,$this->calls);
  }
 }
 public function test_each_operation_receives_normalized_filters_and_a_fresh_correlation():void {
  foreach(['dashboard','clients','reports'] as $module)$this->grant($module.'.read');
  foreach(['dashboard','clients','reports'] as $module){
   $response=$this->get($this->url($module,['period'=>'2026-11','page'=>'2']));
   $response->assertOk()->assertSee('value="2026-11"',false)->assertSee('value="2"',false)->assertSee('Período dos dados')->assertSee('Não informado pela fonte')
    ->assertSee('page=2')->assertSee('period=2026-11');
   $this->assertStringContainsString('no-store',$response->headers->get('Cache-Control'));
   $call=$this->calls[array_key_last($this->calls)];$this->assertSame($module.'.read',$call['operation']);$this->assertSame($this->company,$call['company']);
   $this->assertSame(['period'=>'2026-11','page'=>2],$call['query']);$this->assertTrue(Str::isUuid($call['correlation']));
  }
  $this->assertCount(3,array_unique(array_column($this->calls,'correlation')));
  $this->get($this->url('reports'))->assertOk();$this->assertSame(['page'=>1],$this->calls[3]['query']);
 }
 public function test_invalid_filters_never_reach_the_source_and_remain_correctable():void {
  $this->grant('reports.read');
  foreach([
   ['period'=>'2026-13'],['period'=>'2026-00'],['period'=>'2026-1'],['period'=>['2026-11']],
   ['page'=>0],['page'=>10001],['page'=>'1.5'],['page'=>['2']],
   ['company'=>(string)Str::uuid()],['operation'=>'dashboard.read'],['resource'=>'unexpected'],['credential'=>'ignored'],
  ] as $query){
   $response=$this->get($this->url('reports',$query));
   $response->assertUnprocessable()->assertSee('Revise os filtros.')->assertSee('A consulta não foi enviada.')
    ->assertDontSee('Estado da consulta')->assertSee('Consultar');
   $this->assertStringContainsString('no-store',$response->headers->get('Cache-Control'));
  }
  $this->assertSame([],$this->calls);
  $this->get($this->url('reports',['period'=>'2026-12','page'=>10000]))->assertOk();
  $this->assertSame(['period'=>'2026-12','page'=>10000],$this->calls[0]['query']);
 }
 public function test_operation_revocation_hides_only_that_tab_and_direct_access_is_denied():void {
  $this->grant('clients.read');$this->grant('reports.read');
  $this->get($this->url('clients'))->assertOk()->assertSee($this->url('reports'),false);
  DB::table('portal_performance_grants')->where('admin_user_id',$this->actor)->where('operation','clients.read')->update(['revoked_at'=>time(),'revision'=>2]);
  $before=count($this->calls);$this->get($this->url('clients'))->assertForbidden();$this->assertCount($before,$this->calls);
  $this->get($this->url())->assertOk()->assertSee('Consulta de relatórios.')->assertDontSee($this->url('clients'),false);
  $this->get('/portal-admin/performance-private/'.Str::uuid().'/reports')->assertForbidden();
  $this->get($this->url('campaigns'))->assertNotFound();
  $this->assertCount($before+1,$this->calls);
 }
 public function test_lab_link_requires_its_own_current_membership_and_never_adds_native_operations():void {
  $this->grant('reports.read');
  $this->get($this->url())->assertOk()->assertDontSee('Abrir os dez módulos');
  DB::table('performance_admin_lab_memberships')->insert(['admin_user_id'=>$this->actor,'tenant'=>'lab-alfa','role'=>'analyst','active'=>true,'expires_at'=>time()+600]);
  $this->get($this->url())->assertOk()->assertSee('Abrir os dez módulos')->assertDontSee($this->url('clients'),false)->assertDontSee($this->url('dashboard'),false);
  DB::table('performance_admin_lab_memberships')->where('admin_user_id',$this->actor)->update(['expires_at'=>time()-1]);
  $this->get('/portal-admin/performance-private')->assertOk()->assertDontSee('Abrir os dez módulos');
 }
 public function test_direct_unauthorized_scope_records_only_a_sanitized_denial():void {
  $this->grant('reports.read');
  Log::shouldReceive('channel')->once()->with('performance_security')->andReturnSelf();
  Log::shouldReceive('info')->once()->with('performance_security',\Mockery::on(static fn(array $event)=>
   array_keys($event)===['schema','event','correlation_id','source']&&$event['schema']==='performance.security.v1'
   &&$event['event']==='authority_denied'&&Str::isUuid($event['correlation_id'])&&$event['source']==='portal_private_candidate'));
  $this->get('/portal-admin/performance-private/'.Str::uuid().'/reports')->assertForbidden();
  $this->assertSame([],$this->calls);
 }
}
