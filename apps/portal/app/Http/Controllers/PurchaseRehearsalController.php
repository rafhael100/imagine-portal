<?php

declare(strict_types=1);
namespace App\Http\Controllers;

use App\Homologation\PersistedTestPurchase;
use App\Homologation\PurchaseRehearsalScope;
use Illuminate\Http\Request;
use RuntimeException;
use InvalidArgumentException;

final class PurchaseRehearsalController
{
    private function tenant(Request $request): string
    {
        $tenant=app(PurchaseRehearsalScope::class)->organization($request);
        abort_unless(in_array($tenant,['test-org-a','test-org-b'],true),403);
        return $tenant;
    }

    public function index(Request $request)
    {
        $this->tenant($request);
        return $this->page(null);
    }

    public function create(Request $request)
    {
        $tenant=$this->tenant($request);
        $this->fields($request,['product','request_key']);
        $data=$request->validate(['product'=>'required|string|in:onepage,landing-pages,performance','request_key'=>'required|string|regex:/\ATEST-[a-zA-Z0-9-]{8,64}\z/D']);
        try { $order=app(PersistedTestPurchase::class)->create($tenant,$data['request_key'],$data['product']); }
        catch (InvalidArgumentException|RuntimeException) { abort(409,'Não foi possível criar o pedido de teste.'); }
        return redirect()->route('rehearsal.orders.show',['id'=>$order['id']]);
    }

    public function show(Request $request,string $id)
    {
        $tenant=$this->tenant($request);
        try { $order=app(PersistedTestPurchase::class)->read($tenant,$id); }
        catch (InvalidArgumentException|RuntimeException) { abort(404); }
        return $this->page($order);
    }

    public function payment(Request $request,string $id)
    {
        $tenant=$this->tenant($request);
        $this->fields($request,['revision','outcome','sample_ack']);
        $data=$request->validate(['revision'=>'required|integer|min:0|max:100000','outcome'=>'required|in:approved,pending,declined','sample_ack'=>'accepted']);
        try { app(PersistedTestPurchase::class)->payment($tenant,$id,(int)$data['revision'],$data['outcome']); }
        catch (InvalidArgumentException|RuntimeException) { abort(409,'Atualize o pedido de teste antes de tentar novamente.'); }
        return redirect()->route('rehearsal.orders.show',['id'=>$id]);
    }

    public function briefing(Request $request,string $id)
    {
        $tenant=$this->tenant($request);
        $this->fields($request,['revision','business','goal','audience','materials']);
        $data=$request->validate(['revision'=>'required|integer|min:0|max:100000','business'=>'required|string|max:200','goal'=>'required|string|max:200','audience'=>'required|string|max:200','materials'=>'required|string|max:200']);
        $revision=(int)$data['revision']; unset($data['revision']);
        try { app(PersistedTestPurchase::class)->briefing($tenant,$id,$revision,$data); }
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
        return response()->view('portal.rehearsal.purchase',['order'=>$order,'requestKey'=>'TEST-'.bin2hex(random_bytes(16))])
            ->withHeaders(['Cache-Control'=>'no-store, private','X-Robots-Tag'=>'noindex, nofollow',
                'Content-Security-Policy'=>"default-src 'none'; style-src 'unsafe-inline'; img-src data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
                'Referrer-Policy'=>'no-referrer','X-Content-Type-Options'=>'nosniff']);
    }
}
