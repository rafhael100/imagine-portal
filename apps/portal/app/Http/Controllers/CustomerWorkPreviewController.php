<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Homologation\PreviewProfiles;
use App\Homologation\CustomerWorkPreview;
use Illuminate\Http\Request;

final class CustomerWorkPreviewController
{
    private function context(Request $r): array
    {
        $p=app(PreviewProfiles::class)->current($r);
        abort_unless($p&&($p['role']==='admin'||isset($p['customer_id'])),403);
        view()->share('previewProfile',$p);
        return $p;
    }
    public function show(Request $r,string $id)
    {
        $p=$this->context($r); $owner=$p['role']==='admin'?null:$p['customer_id'];
        $order=app(CustomerWorkPreview::class)->read($owner,$id); abort_unless($order,404);
        return response()->view('portal.preview.customer-work',['order'=>$order,'isAdmin'=>$owner===null]);
    }
    public function performanceSubmit(Request $r,string $id)
    {
        $p=$this->context($r);abort_unless($p['role']==='admin',403);
        $order=app(CustomerWorkPreview::class)->read(null,$id);abort_unless($order,404);
        abort_unless(array_diff(array_keys($r->all()),['_token','revision','period','evidence_ref','actual_google_ads','actual_meta_ads','synthetic_ack'])===[],422);
        $v=$r->validate(['revision'=>'required|integer|min:0|max:100000','period'=>'required|string|max:7','evidence_ref'=>'required|string|max:80','actual_google_ads'=>'nullable|string|max:12','actual_meta_ads'=>'nullable|string|max:12','synthetic_ack'=>'accepted']);
        $actual=[];
        try {
            foreach(['google_ads','meta_ads'] as $channel) if(array_key_exists('actual_'.$channel,$v)&&$v['actual_'.$channel]!==null) $actual[$channel]=\App\Homologation\Commerce\ApprovedOfferCatalog::money($v['actual_'.$channel]);
            app(\App\Homologation\Commerce\PerformanceSettlement::class)->submit($id,(int)$v['revision'],$v['period'],$actual,$v['evidence_ref']);
        }catch(\InvalidArgumentException){abort(422,'Confira a competência, a referência SIM e o gasto de cada canal contratado.');}
        catch(\Throwable){abort(409,'Relatório indisponível. Confira o pagamento e a versão atual do pedido.');}
        return redirect('/_preview/portal/work/'.$id);
    }
    public function performanceRespond(Request $r,string $id)
    {
        $p=$this->context($r);abort_unless($p['role']==='client'&&isset($p['customer_id']),403);
        abort_unless(app(CustomerWorkPreview::class)->read($p['customer_id'],$id),404);
        abort_unless(array_diff(array_keys($r->all()),['_token','revision','version','decision','note','report_ack'])===[],422);
        $v=$r->validate(['revision'=>'required|integer|min:0|max:100000','version'=>'required|integer|min:1|max:20','decision'=>'required|in:acknowledged,disputed','note'=>'nullable|string|max:1000','report_ack'=>'accepted']);
        try {app(\App\Homologation\Commerce\PerformanceSettlement::class)->respond($p['customer_id'],$id,(int)$v['revision'],(int)$v['version'],$v['decision'],$v['note']??'');}
        catch(\InvalidArgumentException){abort(422,'Informe o motivo ao solicitar correção.');}
        catch(\Throwable){abort(409,'Relatório já conferido ou alterado. Atualize a página.');}
        return redirect('/_preview/portal/work/'.$id);
    }
    public function action(Request $r,string $id)
    {
        $p=$this->context($r); $owner=$p['role']==='admin'?null:$p['customer_id'];
        abort_unless(app(CustomerWorkPreview::class)->read($owner,$id),404);
        abort_unless(array_diff(array_keys($r->all()),['_token','revision','action','note'])===[],422);
        $v=$r->validate(['revision'=>'required|integer|min:0|max:100000','action'=>'required|in:start,deliver,approve,request_changes,message','note'=>'nullable|string|max:1000']);
        $allowed=$owner===null?['start','deliver','message']:['approve','request_changes','message'];
        abort_unless(in_array($v['action'],$allowed,true),403);
        abort_unless(app(CustomerWorkPreview::class)->act($owner,$id,(int)$v['revision'],$v['action'],$v['note']??''),409,'Ação indisponível. Atualize o pedido e confira a etapa atual.');
        return redirect('/_preview/portal/work/'.$id);
    }
}
