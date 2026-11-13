<?php

declare(strict_types=1);
namespace App\Http\Controllers;

use App\Homologation\RoleBoundTestPurchase;
use App\Homologation\PurchaseRehearsalScope;
use Illuminate\Http\Request;
use RuntimeException;
use InvalidArgumentException;

final class PurchaseRehearsalController
{
    /**
     * HTTP server scope must match the authenticated, restricted PostgreSQL
     * login. A request field or a scope fixture cannot elevate database access.
     */
    private function store(Request $request): RoleBoundTestPurchase
    {
        $tenant = app(PurchaseRehearsalScope::class)->organization($request);
        abort_unless(in_array($tenant, ['test-org-a','test-org-b'], true), 403);
        abort_unless(app()->bound(RoleBoundTestPurchase::class), 503);
        $store = app(RoleBoundTestPurchase::class);
        abort_unless(hash_equals($store->organization(), $tenant), 403);
        return $store;
    }

    public function index(Request $request)
    {
        $this->store($request);
        return $this->page(null);
    }

    public function create(Request $request)
    {
        $store=$this->store($request);
        $this->fields($request,['product','request_key']);
        $data=$request->validate(['product'=>'required|string|in:onepage,landing-pages,performance','request_key'=>'required|string|regex:/\ATEST-[a-zA-Z0-9-]{8,64}\z/D']);
        try { $order=$store->create($data['request_key'],$data['product']); }
        catch (InvalidArgumentException|RuntimeException) { abort(409,'Não foi possível criar o pedido de teste.'); }
        return redirect()->route('rehearsal.orders.show',['id'=>$order['id']]);
    }

    public function show(Request $request,string $id)
    {
        $store=$this->store($request);
        try { $order=$store->read($id); }
        catch (InvalidArgumentException|RuntimeException) { abort(404); }
        return $this->page($order);
    }

    public function payment(Request $request,string $id)
    {
        $store=$this->store($request);
        $this->fields($request,['revision','outcome','sample_ack']);
        $data=$request->validate(['revision'=>'required|integer|min:0|max:100000','outcome'=>'required|in:approved,pending,declined','sample_ack'=>'accepted']);
        try { $store->payment($id,(int)$data['revision'],$data['outcome']); }
        catch (InvalidArgumentException|RuntimeException) { abort(409,'Atualize o pedido de teste antes de tentar novamente.'); }
        return redirect()->route('rehearsal.orders.show',['id'=>$id]);
    }

    public function briefing(Request $request,string $id)
    {
        $store=$this->store($request);
        $this->fields($request,['revision','business','goal','audience','materials']);
        $data=$request->validate(['revision'=>'required|integer|min:0|max:100000','business'=>'required|string|max:200','goal'=>'required|string|max:200','audience'=>'required|string|max:200','materials'=>'required|string|max:200']);
        $revision=(int)$data['revision']; unset($data['revision']);
        try { $store->briefing($id,$revision,$data); }
        catch (InvalidArgumentException|RuntimeException) { abort(409,'Briefing indisponível para este estado do pedido.'); }
        return redirect()->route('rehearsal.orders.show',['id'=>$id]);
    }

    private function fields(Request $request,array $allowed): void
    {
        // Reject browser-supplied organization, identity and other hidden authority fields.
        abort_unless(array_diff(array_keys($request->all()),[...$allowed,'_token'])===[],422);
    }

    private function page(?array $order)
    {
        // A separate idempotency key for each offer shown on the same page:
        // selecting another product must not reuse the first one's identity.
        $requestKeys=[];
        foreach (['onepage','landing-pages','performance'] as $product) {
            $requestKeys[$product]='TEST-'.bin2hex(random_bytes(16));
        }
        return response()->view('portal.rehearsal.purchase',['order'=>$order,'requestKeys'=>$requestKeys])
            ->withHeaders(['Cache-Control'=>'no-store, private','X-Robots-Tag'=>'noindex, nofollow',
                'Content-Security-Policy'=>"default-src 'none'; style-src 'unsafe-inline'; img-src data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
                'Referrer-Policy'=>'no-referrer','X-Content-Type-Options'=>'nosniff']);
    }
}
