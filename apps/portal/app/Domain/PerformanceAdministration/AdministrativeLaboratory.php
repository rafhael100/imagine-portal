<?php
declare(strict_types=1);
namespace App\Domain\PerformanceAdministration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use DomainException;
final class AdministrativeLaboratory
{
    public function transport():PerformanceAdminTransport{return new PinnedLaboratoryTransport();}
    public function memberships():array
    {
        return DB::table('performance_admin_lab_memberships')->where('admin_user_id',Auth::guard('portal-admin')->id())
            ->where('active',true)->where('expires_at','>',time())->orderBy('tenant')->get()->all();
    }
    public function membership(string $tenant,bool $lock=false):object
    {
        $q=DB::table('performance_admin_lab_memberships')->where('admin_user_id',Auth::guard('portal-admin')->id())
            ->where('tenant',$tenant)->where('active',true)->where('expires_at','>',time());
        if($lock){$member=DB::selectOne('select * from public.performance_admin_lab_lock_member(?, ?)',[(int)Auth::guard('portal-admin')->id(),$tenant]);}
        else $member=$q->first();if(!$member)abort(404);return $member;
    }
    public function state(string $tenant,bool $lock=false):array
    {
        $q=DB::table('performance_admin_lab_states')->where('tenant',$tenant);if($lock)$q->lockForUpdate();
        $row=$q->first();if(!$row)abort(404);$state=json_decode($row->payload,true,64,JSON_THROW_ON_ERROR);
        if(($state['tenant']??null)!==$tenant||($state['source']??null)!=='synthetic'||($state['contract']??null)!=='imagine.performance.admin.v1'||$state['revision']!==$row->revision)abort(503);
        return $state;
    }
    public function command(Request $request,string $tenant,array $command):string
    {
        return DB::transaction(function()use($request,$tenant,$command):string{
            // Serialize membership/session revocation against the mutation, not just page render.
            $id=(int)Auth::guard('portal-admin')->id();
            $actor=DB::table('portal_admin_users')->where('id',$id)->lockForUpdate()->first();
            if(!$actor||!$actor->is_active||(int)$actor->session_version!==$request->session()->get('portal_admin_session_version'))abort(403);
            $member=$this->membership($tenant,true);$state=$this->state($tenant,true);
            $context=['actor'=>$id,'tenant'=>$tenant,'role'=>$member->role,'active'=>true,'mfa_at'=>$request->session()->get('portal_admin_mfa_at')];
            if(!$this->transport()->allows($member->role,$command['action']))abort(403);
            // Idempotency is bound to actor + tenant + complete request, never a global key.
            $hash=hash('sha256',json_encode($command,JSON_THROW_ON_ERROR));
            $existing=DB::table('performance_admin_lab_events')->where('tenant',$tenant)->where('admin_user_id',$id)->where('idempotency_key',$command['idempotency_key'])->first();
            if($existing){if(!hash_equals($hash,$existing->request_hash))abort(409,'Chave já utilizada em outra operação.');return 'Operação já registrada; nenhuma duplicação.';}
            try{$result=$this->transport()->command($state,$context,$command,time());}
            catch(DomainException $e){abort(409,'Operação bloqueada: revisão, permissão, validade ou estado incompatível.');}
            DB::table('performance_admin_lab_states')->where('tenant',$tenant)->where('revision',$state['revision'])->update([
                'payload'=>json_encode($result['state'],JSON_THROW_ON_ERROR),'revision'=>$result['state']['revision'],'updated_at'=>now()]);
            DB::table('performance_admin_lab_events')->insert(['tenant'=>$tenant,'admin_user_id'=>$id,'idempotency_key'=>$command['idempotency_key'],
                'request_hash'=>$hash,'event'=>json_encode($result['event'],JSON_THROW_ON_ERROR),'created_at'=>now(),'updated_at'=>now()]);
            return 'Operação de laboratório confirmada e registrada. Nenhuma ação externa executada.';
        },3);
    }
}
