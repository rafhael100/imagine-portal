<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Homologation\CustomerPreviewStore;
use App\Homologation\PreviewProfiles;
use Illuminate\Http\Request;

final class CustomerPreviewController
{
    private const OFFERS=['onepage','landing-pages','performance'];
    /**
     * Synthetic customer pages contain forms, CSRF tokens and test account data.
     * Keep them out of browser, proxy and search-engine caches; disallow frames
     * and any outbound form destination even in the isolated preview.
     */
    private function protectedView(string $name, array $data = [])
    {
        return response()->view($name, $data)->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
        ]);
    }

    public function registerForm(Request $r)
    {
        return $this->protectedView('portal.preview.register');
    }
    public function register(Request $r)
    {
        $this->fields($r,['username','password','password_confirmation','name','company']);
        $v=$r->validate(['username'=>'required|string|regex:/\Ademo-[a-z0-9-]{3,40}\z/D','password'=>'required|string|min:12|max:72|confirmed','name'=>'required|string|max:100','company'=>'required|string|max:100']);
        abort_unless(strlen($v['password'])<=72,422);
        try { app(CustomerPreviewStore::class)->register($v['username'],$v['password'],$v['name'],$v['company']); }
        catch (\Throwable) { return redirect('/_preview/portal/register')->withErrors(['username'=>'Não foi possível criar a conta. Escolha outro usuário ou tente mais tarde.']); }
        $intent=$r->session()->get('preview.intent');
        abort_unless(app(PreviewProfiles::class)->login($r,$v['username'],$v['password']),503);
        return redirect(in_array($intent,self::OFFERS,true)?'/_preview/portal/shop?product='.$intent:'/_preview/portal/client/overview');
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
        if ($product!==null) $r->session()->put('preview.intent',$product);
        else $r->session()->forget('preview.intent'); // Explicitly choose a different offer.
        $p=app(PreviewProfiles::class)->current($r);
        if (!$p) return redirect('/_preview/portal');
        if (!isset($p['customer_id'])) return $this->protectedView('portal.preview.personal-required');
        $this->owner($r); return $this->page(null,$product);
    }
    public function create(Request $r)
    {
        $p=$this->owner($r); $this->fields($r,['product','request_key']);
        $v=$r->validate(['product'=>'required|in:onepage,landing-pages,performance','request_key'=>'required|string|regex:/\ATEST-[a-zA-Z0-9-]{8,64}\z/D']);
        // An offer brought from Imagine Comercial is preserved through signup.
        // A modified POST cannot silently switch the selected service.
        $intent=$r->session()->get('preview.intent');
        abort_unless($intent===null || (is_string($intent) && hash_equals($intent,$v['product'])),422);
        try { $order=app(CustomerPreviewStore::class)->create($p['customer_id'],$v['request_key'],$v['product']); }
        catch (\Throwable) { abort(409,'Não foi possível criar o pedido de teste.'); }
        return redirect('/_preview/portal/shop/orders/'.$order['id']);
    }
    public function show(Request $r,string $id)
    {
        $p=$this->owner($r); $o=app(CustomerPreviewStore::class)->read($p['customer_id'],$id); abort_unless($o,404); return $this->page($o);
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
    private function page(?array $order,?string $product=null)
    {
        $keys=[]; foreach(self::OFFERS as $offer) $keys[$offer]='TEST-'.bin2hex(random_bytes(16));
        $offers=(new \App\Homologation\PreviewCommercialCatalog(resource_path('portal/catalog/approved-commercial-catalog.json')))->offers();
        return $this->protectedView('portal.preview.customer-purchase',['order'=>$order,'requestKeys'=>$keys,'selectedProduct'=>$product,'commercialOffers'=>$offers]);
    }
}
