<?php
declare(strict_types=1);
namespace Tests\Feature;
use Tests\TestCase;
use App\Domain\PerformanceAdministration\PinnedLaboratoryTransport;
use App\Domain\PortalAccess\Security\PortalAdminTotp;
use App\Models\PortalAdminUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
final class PerformanceAdministrationTest extends TestCase
{
    private int $actor;
    private string $secret;
    protected function setUp():void
    {
        parent::setUp();
        if(getenv('PERFORMANCE_ADMIN_PG_CI')!=='1')$this->markTestSkipped('Requires dedicated disposable PostgreSQL laboratory');
        DB::beginTransaction();config()->set('session.driver','array');
        $this->secret=bin2hex(random_bytes(20));
        $this->actor=(int)DB::table('portal_admin_users')->insertGetId(['email'=>'perf-'.Str::uuid().'@example.test','password_hash'=>Hash::make('LabPassword#Only123'),
            'totp_secret_ciphertext'=>Crypt::encryptString($this->secret),'is_active'=>true,'last_totp_counter'=>-1,'session_version'=>1,'created_at'=>now(),'updated_at'=>now()]);
        $engine=new PinnedLaboratoryTransport();
        foreach(['lab-alfa','lab-beta'] as $tenant)DB::table('performance_admin_lab_states')->insert(['tenant'=>$tenant,'revision'=>1,'payload'=>json_encode($engine->fixture($tenant,time()),JSON_THROW_ON_ERROR),'created_at'=>now(),'updated_at'=>now()]);
        $this->grant('operations');
    }
    protected function tearDown():void{if(DB::transactionLevel()>0)DB::rollBack();parent::tearDown();}
    private function grant(string $role):void
    {
        DB::table('performance_admin_lab_memberships')->updateOrInsert(['admin_user_id'=>$this->actor,'tenant'=>'lab-alfa'],['role'=>$role,'active'=>true,'expires_at'=>time()+3600]);
    }
    private function login():void
    {
        $email=DB::table('portal_admin_users')->where('id',$this->actor)->value('email');
        $this->post('/portal-admin/login',['email'=>$email,'password'=>'LabPassword#Only123'])->assertRedirect('/portal-admin/mfa');
        $this->post('/portal-admin/mfa',['code'=>PortalAdminTotp::code(hex2bin($this->secret),intdiv(time(),30))])->assertRedirect('/portal-admin');
    }
    private function command(string $action='agent.pause',string $resource='analysis',int $revision=1):array
    {return ['module'=>str_starts_with($action,'approval.')?'approvals':(str_starts_with($action,'incident.')?'incidents':'agents'),'action'=>$action,'resource'=>$resource,'revision'=>$revision,'confirmed'=>'1','idempotency_key'=>(string)Str::uuid()];}
    private function state(string $tenant='lab-alfa'):array{return json_decode(DB::table('performance_admin_lab_states')->where('tenant',$tenant)->value('payload'),true,64,JSON_THROW_ON_ERROR);}
    public function test_all_ten_modules_and_scoped_details_are_navigable():void
    {
        $this->login();$this->grant('superadmin');
        foreach(array_keys(\App\Http\Controllers\PerformanceAdminController::MODULES) as $m)$this->get('/portal-admin/performance/'.$m)->assertOk()->assertSee('Dados sintéticos')->assertDontSee('Empresa Beta');
        $this->get('/portal-admin/performance/clients?tenant=lab-beta')->assertNotFound();
        $this->get('/portal-admin/performance/agents?tenant=lab-beta&resource=analysis')->assertNotFound();
        $this->get('/portal-admin/performance/export/lab-beta')->assertNotFound();
        $this->get('/portal-admin/performance/campaigns?page=2')->assertOk()->assertSee('Página 2');
        $this->get('/portal-admin/performance/campaigns?q=nao-existe')->assertOk()->assertSee('Nenhum registro corresponde');
        $this->get('/portal-admin/performance/campaigns?channel=meta_ads')->assertOk()->assertDontSee('Campanha de laboratório 01');
        $this->get('/portal-admin/performance/campaigns?resource=unknown')->assertNotFound();
        $this->get('/portal-admin/performance/campaigns?page=-1')->assertSessionHasErrors('page');
        foreach(['overview','history'] as $tab)$this->get('/portal-admin/performance/clients?tab='.$tab)->assertOk();
        foreach(['campaigns','channels','agents','finance','approvals','reports'] as $tab)$this->get('/portal-admin/performance/clients?tab='.$tab)->assertRedirect();
        $this->get('/portal-admin/performance/export/lab-alfa')->assertOk()->assertHeader('Content-Type','text/csv; charset=UTF-8')->assertSee('synthetic');
    }
    public function test_login_mfa_membership_expiry_and_revocation_are_rechecked():void
    {
        $this->get('/portal-admin/performance')->assertRedirect('/portal-admin/login');
        $this->login();DB::table('performance_admin_lab_memberships')->where('admin_user_id',$this->actor)->update(['expires_at'=>time()-1]);
        $this->get('/portal-admin/performance?tenant=lab-alfa')->assertNotFound();
        $this->grant('operations');DB::table('portal_admin_users')->where('id',$this->actor)->increment('session_version');
        $this->get('/portal-admin/performance')->assertRedirect('/portal-admin/login');
    }
    public function test_six_roles_enforced_on_server_even_when_request_forges_role():void
    {
        $this->login();
        foreach(['auditor','analyst','finance'] as $role){$this->grant($role);$this->post('/portal-admin/performance/command/lab-alfa',$this->command())->assertForbidden();}
        $this->grant('operations');$this->post('/portal-admin/performance/command/lab-alfa',$this->command()+['role'=>'superadmin'])->assertUnprocessable();
        $this->get('/portal-admin/performance/finance')->assertForbidden();
        $this->grant('finance');$this->get('/portal-admin/performance/finance')->assertOk()->assertSee('R$ 200,00')->assertSee('R$ 170,00');
        foreach(['superadmin','operations','ai_supervisor'] as $role){$this->grant($role);$s=$this->state();$action=$s['agents']['analysis']['status']==='active'?'agent.pause':'agent.resume';$this->post('/portal-admin/performance/command/lab-alfa',$this->command($action,'analysis',$s['revision']))->assertRedirect();}
    }
    public function test_mutation_persists_audit_once_rejects_stale_and_changed_replay():void
    {
        $this->login();$q=$this->command();$this->post('/portal-admin/performance/command/lab-alfa',$q)->assertRedirect();
        self::assertSame('paused',$this->state()['agents']['analysis']['status']);
        $this->post('/portal-admin/performance/command/lab-alfa',$q)->assertRedirect();
        self::assertSame(1,DB::table('performance_admin_lab_events')->count());
        $this->post('/portal-admin/performance/command/lab-alfa',array_replace($q,['resource'=>'strategy']))->assertConflict();
        $this->post('/portal-admin/performance/command/lab-alfa',$this->command('agent.resume'))->assertConflict();
        $this->post('/portal-admin/performance/command/lab-beta',$this->command())->assertNotFound();
        self::assertSame(1,$this->state('lab-beta')['revision']);
        $q=$this->command('agent.resume','analysis',2);unset($q['confirmed']);$this->post('/portal-admin/performance/command/lab-alfa',$q)->assertSessionHasErrors('confirmed');
        $this->withSession(['portal_admin_mfa_at'=>time()-901])->post('/portal-admin/performance/command/lab-alfa',$this->command('agent.resume','analysis',2))->assertConflict();
    }
    public function test_approval_is_bound_revocable_and_cannot_execute_ads_or_finance():void
    {
        $this->login();
        foreach(['budget','payment'] as $id){$s=$this->state();$a=$s['approvals'][$id];$q=$this->command('approval.approve',$id,$s['revision'])+['proposal_version'=>1,'proposal_digest'=>$a['digest']];
            $this->post('/portal-admin/performance/command/lab-alfa',array_replace($q,['proposal_version'=>2]))->assertConflict();
            $this->post('/portal-admin/performance/command/lab-alfa',$q)->assertRedirect();
            $q=$this->command('approval.consume',$id,$s['revision']+1)+['proposal_version'=>1,'proposal_digest'=>$a['digest']];
            $this->post('/portal-admin/performance/command/lab-alfa',$q)->assertConflict();
            $q['action']='approval.revoke';$this->post('/portal-admin/performance/command/lab-alfa',$q)->assertRedirect();
            self::assertSame('revoked',$this->state()['approvals'][$id]['status']);
        }
    }
    public function test_incident_requires_evidence_and_history_is_retained():void
    {
        $this->login();foreach(['sync','task'] as $id){$s=$this->state();$this->post('/portal-admin/performance/command/lab-alfa',$this->command('incident.ack',$id,$s['revision']))->assertRedirect();$s=$this->state();$r=$this->post('/portal-admin/performance/command/lab-alfa',$this->command('incident.resolve',$id,$s['revision']));if($id==='sync')$r->assertConflict();else $r->assertRedirect();}
        self::assertSame('acknowledged',$this->state()['incidents']['sync']['status']);self::assertSame('resolved',$this->state()['incidents']['task']['status']);
        $this->get('/portal-admin/performance/incidents')->assertSee('Resolvido');
    }
    public function test_report_filters_export_and_partial_month_never_imply_billing():void
    {
        $this->login();$this->grant('finance');
        $this->get('/portal-admin/performance/export/lab-alfa?channel=meta_ads')->assertOk()->assertSee('meta_ads')->assertDontSee('google_ads');
        $this->get('/portal-admin/performance/reports?channel=meta_ads&objective=Visitas')->assertOk()->assertSee('Exportar CSV filtrado');
        $this->get('/portal-admin/performance/clients?q=nao-existe')->assertOk()->assertSee('Nenhum cliente corresponde');
        $engine=new PinnedLaboratoryTransport();$state=$this->state();$state['channels']['google_ads']['full_month']=false;
        self::assertSame('partial_policy_pending',$engine->finance($state)['state']);
        DB::table('performance_admin_lab_states')->where('tenant','lab-alfa')->update(['payload'=>json_encode($state,JSON_THROW_ON_ERROR)]);
        $this->get('/portal-admin/performance/finance')->assertOk()->assertSee('Nenhum piso ou cobrança foi aplicado');
    }
    public function test_ui_hides_ineligible_operations_and_preserves_client_context():void
    {
        $this->login();$s=$this->state();
        foreach(['budget','payment','review'] as $id){$s['approvals'][$id]['status']='approved';$s['approvals'][$id]['approved_by']=$this->actor+1;}
        $s['incidents']['sync']['status']='acknowledged';
        DB::table('performance_admin_lab_states')->where('tenant','lab-alfa')->update(['payload'=>json_encode($s,JSON_THROW_ON_ERROR)]);
        foreach(['budget','payment'] as $id)$this->get('/portal-admin/performance/approvals?resource='.$id)->assertOk()->assertDontSee('value="approval.consume"',false);
        $this->get('/portal-admin/performance/approvals?resource=review')->assertOk()->assertSee('value="approval.consume"',false);
        $this->get('/portal-admin/performance/incidents?resource=sync')->assertOk()->assertDontSee('value="incident.resolve"',false);
        $this->get('/portal-admin/performance/campaigns?tenant=lab-alfa&context=client&client_q=Alfa&client_status=active&client_page=2')->assertOk()->assertSee('client_q')->assertSee('Voltar aos clientes com os filtros anteriores');
        $s['approvals']['review']['expires_at']=time()-1;
        DB::table('performance_admin_lab_states')->where('tenant','lab-alfa')->update(['payload'=>json_encode($s,JSON_THROW_ON_ERROR)]);
        $this->get('/portal-admin/performance/approvals?resource=review')->assertOk()->assertDontSee('value="approval.consume"',false);
    }
    public function test_empty_membership_and_logout_do_not_leak_context():void
    {
        $this->login();DB::table('performance_admin_lab_memberships')->where('admin_user_id',$this->actor)->update(['active'=>false]);
        $this->get('/portal-admin/performance')->assertOk()->assertSee('Nenhuma empresa de laboratório autorizada')->assertDontSee('Empresa Alfa');
        $this->post('/portal-admin/logout')->assertRedirect();$this->get('/portal-admin/performance')->assertRedirect('/portal-admin/login');
    }
    public function test_kill_switch_denies_runtime_routes_even_after_registration():void
    {
        $this->login();config()->set('performance_admin.enabled',false);$this->get('/portal-admin/performance')->assertNotFound();
    }
}
