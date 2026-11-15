<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Domain\PerformanceAdministration\AdministrativeLaboratory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
final class PerformanceAdminController
{
    public const MODULES=['dashboard'=>'Visão geral','clients'=>'Clientes','channels'=>'Canais','campaigns'=>'Campanhas','agents'=>'Agentes de IA',
        'approvals'=>'Aprovações','finance'=>'Financeiro','reports'=>'Relatórios','incidents'=>'Monitoramento','settings'=>'Configurações'];
    public function index(Request $request,string $module='dashboard')
    {
        if(!isset(self::MODULES[$module]))abort(404);
        $lab=new AdministrativeLaboratory();$memberships=$lab->memberships();
        $tenant=$request->query('tenant',$memberships[0]->tenant??'');
        if(!is_string($tenant))abort(422);
        $member=$tenant!==''?$lab->membership($tenant):null;
        $state=$member?$lab->state($tenant):null;
        $can=fn(string $action)=>$member&&$lab->transport()->allows($member->role,$action);
        if($module==='finance'&&$member&&!$can('finance'))abort(403);
        $filters=$request->validate(['q'=>'nullable|string|max:80','channel'=>'nullable|in:google_ads,meta_ads,chatgpt_ads',
            'status'=>'nullable|in:active,onboarding,monitored,alert,paused,failed,pending,approved,rejected,revoked,consumed,open,acknowledged,resolved,unavailable,simulated',
            'page'=>'nullable|integer|min:1|max:10000','sort'=>'nullable|in:name,status','tab'=>'nullable|in:overview,campaigns,channels,agents,finance,approvals,reports,history',
            'resource'=>'nullable|string|max:64','period'=>'nullable|in:2026-11','objective'=>'nullable|in:Leads,Visitas']);
        $rows=[];$detail=null;
        if($state){
            $key=['channels'=>'channels','campaigns'=>'campaigns','agents'=>'agents','approvals'=>'approvals','incidents'=>'incidents'][$module]??null;
            if($key){$rows=array_values($state[$key]);
                $rows=array_values(array_filter($rows,fn($r)=>(!isset($filters['q'])||mb_stripos($r['name']??$r['title']??'', $filters['q'])!==false)
                    &&(!isset($filters['channel'])||($r['channel']??$r['id'])===$filters['channel'])
                    &&(!isset($filters['status'])||($r['status']??'')===$filters['status'])
                    &&(!isset($filters['objective'])||($r['objective']??'')===$filters['objective'])));
                usort($rows,fn($a,$b)=>strcmp($a[$filters['sort']??'name']??$a['title']??$a['id'],$b[$filters['sort']??'name']??$b['title']??$b['id']));
                if(isset($filters['resource'])){$detail=$state[$key][$filters['resource']]??null;if(!$detail)abort(404);}
            }
        }
        $total=count($rows);$page=(int)($filters['page']??1);$rows=array_slice($rows,($page-1)*10,10);
        $events=$state?DB::table('performance_admin_lab_events')->where('tenant',$tenant)->orderByDesc('id')->limit(50)->get()->map(fn($r)=>json_decode($r->event,true,16,JSON_THROW_ON_ERROR))->all():[];
        $finance=$state&&$can('finance')?$lab->transport()->finance($state):null;
        $scopeCount=count($memberships);
        return view('performance-admin.central',compact('module','memberships','tenant','member','state','can','rows','total','page','filters','events','finance','scopeCount','detail')+['modules'=>self::MODULES]);
    }
    public function command(Request $request,string $tenant)
    {
        $allowed=['_token','action','resource','revision','confirmed','idempotency_key','proposal_version','proposal_digest','module'];
        if(array_diff(array_keys($request->all()),$allowed)!==[])abort(422);
        $data=$request->validate(['action'=>'required|in:agent.pause,agent.resume,approval.approve,approval.reject,approval.revoke,approval.consume,incident.ack,incident.resolve',
            'resource'=>'required|regex:/\A[a-z0-9-]{1,64}\z/D','revision'=>'required|integer|min:1',
            'confirmed'=>'required|accepted','idempotency_key'=>'required|uuid','proposal_version'=>'nullable|integer|min:1',
            'proposal_digest'=>'nullable|regex:/\A[a-f0-9]{64}\z/D','module'=>'required|in:agents,approvals,incidents']);
        $module=$data['module'];unset($data['module']);$data['revision']=(int)$data['revision'];$data['confirmed']=true;
        if(isset($data['proposal_version']))$data['proposal_version']=(int)$data['proposal_version'];
        $notice=(new AdministrativeLaboratory())->command($request,$tenant,$data);
        return redirect()->route('performance-admin.index',['module'=>$module,'tenant'=>$tenant])->with('perf_notice',$notice);
    }
    public function export(Request $request,string $tenant):Response
    {
        $lab=new AdministrativeLaboratory();$lab->membership($tenant);$s=$lab->state($tenant);
        $stream=fopen('php://temp','r+');fputcsv($stream,['campanha','canal','periodo','origem','midia_centavos','impressoes','cliques','leads'],',','"','');
        foreach($s['campaigns'] as $r){$name=$r['name'];if(preg_match('/^[=+@\-\t\r\n]/',$name))$name="'".$name;
            fputcsv($stream,[$name,$r['channel'],$s['period'],'synthetic',$r['spend_cents'],$r['impressions'],$r['clicks'],$r['leads']],',','"','');}
        rewind($stream);$csv=stream_get_contents($stream);fclose($stream);
        return response($csv,200,['Content-Type'=>'text/csv; charset=UTF-8','Content-Disposition'=>'attachment; filename="performance-laboratorio.csv"','Cache-Control'=>'private, no-store']);
    }
}
