<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Homologation\CustomerPreviewStore;
use App\Homologation\PreviewProfiles;
use App\Homologation\Commerce\ApprovedOfferCatalog;
use App\Homologation\Commerce\CustomerPreviewHandoff;
use Illuminate\Http\Request;

final class CustomerPreviewController
{
    private const OFFERS=['onepage','landing-pages','performance'];
    public function registerForm(Request $r) { return response()->view('portal.preview.register'); }
    public function register(Request $r)
    {
        $this->fields($r,['username','password','password_confirmation','name','company']);
        $v=$r->validate(['username'=>'required|string|regex:/\Ademo-[a-z0-9-]{3,40}\z/D','password'=>'required|string|min:12|max:72|confirmed','name'=>'required|string|max:100','company'=>'required|string|max:100']);
        abort_unless(strlen($v['password'])<=72,422);
        try { app(CustomerPreviewStore::class)->register($v['username'],$v['password'],$v['name'],$v['company']); }
        catch (\Throwable) { return redirect('/_preview/portal/register')->withErrors(['username'=>'Não foi possível criar a conta. Escolha outro usuário ou tente mais tarde.']); }
        $intent=$r->session()->get('preview.intent');
        $channel=$r->session()->get('preview.intent_channel');
        abort_unless(app(PreviewProfiles::class)->login($r,$v['username'],$v['password']),503);
        try { $target=CustomerPreviewHandoff::shopUrl($intent,$channel); }
        catch (\InvalidArgumentException) { $target=null; }
        return redirect($target??'/_preview/portal/client/overview');
    }
    private function owner(Request $r): array
    {
        $p=app(PreviewProfiles::class)->current($r);
        abort_unless($p&&$p['role']==='client'&&isset($p['customer_id']),403);
        view()->share('previewProfile',$p); return $p;
    }
    public function shop(Request $r)
    {
        $product=$r->query('product');
        abort_unless($product===null||in_array($product,self::OFFERS,true),422);
        try { $channel=CustomerPreviewHandoff::channel($product,$r->query('channel')); }
        catch (\InvalidArgumentException) { abort(422,'Canal de origem inválido'); }
        if ($product!==null) {
            $r->session()->put('preview.intent',$product);
            if ($channel!==null) $r->session()->put('preview.intent_channel',$channel);
            else $r->session()->forget('preview.intent_channel');
        } else {
            $r->session()->forget(['preview.intent','preview.intent_channel']);
        }
        $p=app(PreviewProfiles::class)->current($r);
        if (!$p) return redirect('/_preview/portal');
        if (!isset($p['customer_id'])) return response()->view('portal.preview.personal-required');
        $this->owner($r); return $this->page(null,$product,$channel);
    }
    public function create(Request $r)
    {
        $p=$this->owner($r); $this->fields($r,['product','request_key','offer_version','offer_ack','google_media','meta_media']);
        $v=$r->validate(['product'=>'required|in:onepage,landing-pages,performance','request_key'=>'required|string|regex:/\ATEST-[a-zA-Z0-9-]{8,64}\z/D','offer_version'=>'required|string|size:64','offer_ack'=>'accepted','google_media'=>'nullable|string|max:12','meta_media'=>'nullable|string|max:12']);
        $catalog=new ApprovedOfferCatalog();
        abort_unless(hash_equals($catalog->version(),$v['offer_version']),409,'A oferta mudou. Atualize e confira as condições.');
        $media=[];
        try {
            foreach (['google_media'=>'google_ads','meta_media'=>'meta_ads'] as $field=>$channel) if (($v[$field]??'')!=='') $media[$channel]=ApprovedOfferCatalog::money($v[$field]);
            $quote=$catalog->quote($v['product'],$media);
        } catch (\InvalidArgumentException) { abort(422,'Informe investimento de pelo menos R$ 700 por canal selecionado; deixe vazio o canal não contratado.'); }
        try { $order=app(CustomerPreviewStore::class)->create($p['customer_id'],$v['request_key'],$v['product'],$quote); }
        catch (\Throwable) { abort(409,'Não foi possível criar o pedido de teste.'); }
        return redirect('/_preview/portal/shop/orders/'.$order['id']);
    }
    public function show(Request $r,string $id)
    {
        $p=$this->owner($r); $o=app(CustomerPreviewStore::class)->read($p['customer_id'],$id); abort_unless($o,404);
        $o['sandbox']=app(CustomerPreviewStore::class)->sandboxStatus($p['customer_id'],$id);
        return $this->page($o);
    }
    public function sandbox(Request $r,string $id)
    {
        $p=$this->owner($r);
        $this->fields($r,['revision','sandbox_ack']);
        abort_unless(app(CustomerPreviewStore::class)->read($p['customer_id'],$id),404);
        $v=$r->validate([
            'revision'=>'required|integer|min:0|max:100000',
            'sandbox_ack'=>'accepted',
        ]);
        try {
            app(CustomerPreviewStore::class)->sandboxRequest(
                $p['customer_id'],$id,(int)$v['revision']
            );
        } catch (\Throwable) {
            abort(409,'Cobrança sandbox indisponível. Confira a etapa e o histórico.');
        }
        return redirect('/_preview/portal/shop/orders/'.$id);
    }

    public function payment(Request $r,string $id)
    {
        $p=$this->owner($r); $this->fields($r,['revision','outcome','sample_ack']);
        abort_unless(app(CustomerPreviewStore::class)->read($p['customer_id'],$id),404);
        $v=$r->validate(['revision'=>'required|integer|min:0|max:100000','outcome'=>'required|in:approved,pending,declined','sample_ack'=>'accepted']);
        abort_unless(app(CustomerPreviewStore::class)->payment($p['customer_id'],$id,(int)$v['revision'],$v['outcome']),409);
        return redirect('/_preview/portal/shop/orders/'.$id);
    }
    public function briefing(Request $r,string $id)
    {
        $p=$this->owner($r); $this->fields($r,['revision','business','goal','audience','materials']);
        abort_unless(app(CustomerPreviewStore::class)->read($p['customer_id'],$id),404);
        $v=$r->validate(['revision'=>'required|integer|min:0|max:100000','business'=>'required|string|max:200','goal'=>'required|string|max:200','audience'=>'required|string|max:200','materials'=>'required|string|max:200']);
        $revision=(int)$v['revision']; unset($v['revision']);
        abort_unless(app(CustomerPreviewStore::class)->briefing($p['customer_id'],$id,$revision,$v),409);
        return redirect('/_preview/portal/shop/orders/'.$id);
    }
    private function fields(Request $r,array $allowed): void { abort_unless(array_diff(array_keys($r->all()),[...$allowed,'_token'])===[],422); }
    private function page(?array $order,?string $product=null,?string $channel=null)
    {
        $keys=[]; foreach(self::OFFERS as $offer) $keys[$offer]='TEST-'.bin2hex(random_bytes(16));
        $catalog=new ApprovedOfferCatalog();
        $calendar=$order===null ? [] :
            \App\Homologation\Commerce\PreviewBillingCalendar::forOrder(
                $order['offer_snapshot']??null,
                $order['offer_accepted_at']??null,
                $order['product']
            );
        return response()->view('portal.preview.customer-purchase',['order'=>$order,'requestKeys'=>$keys,'selectedProduct'=>$product,'selectedChannel'=>$channel,'approvedOffers'=>$catalog->offers(),'offerVersion'=>$catalog->version(),'billingCalendar'=>$calendar]);
    }
}
