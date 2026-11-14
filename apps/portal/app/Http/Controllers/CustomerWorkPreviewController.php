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
        return response()->view('portal.preview.customer-work',['order'=>$order,'isAdmin'=>$owner===null])->withHeaders(['Cache-Control'=>'no-store, private','X-Robots-Tag'=>'noindex, nofollow','Referrer-Policy'=>'no-referrer','X-Content-Type-Options'=>'nosniff']);
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
