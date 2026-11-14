<?php

declare(strict_types=1);
namespace App\Http\Controllers;
use App\Homologation\PreviewProfiles;
use Illuminate\Http\Request;

final class FullPortalPreviewController
{
    private function profile(Request $request,string $role): array
    {
        $p=app(PreviewProfiles::class)->current($request);
        abort_unless($p!==null,401);
        abort_unless($p['role']===$role,403);
        view()->share('previewProfile',$p);
        return $p;
    }
    private function page(string $view,array $data=[])
    {
        return response()->view($view,$data)->withHeaders([
          'Cache-Control'=>'no-store, private','X-Robots-Tag'=>'noindex, nofollow',
          'Content-Security-Policy'=>"default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
          'Referrer-Policy'=>'no-referrer','X-Content-Type-Options'=>'nosniff']);
    }
    public function login(Request $request)
    {
        $auth=app(PreviewProfiles::class); $profile=$auth->current($request);
        if ($profile) return redirect('/_preview/portal/'.$profile['role'].'/overview');
        return $this->page('portal.preview.login',['ready'=>$auth->ready()]);
    }
    public function authenticate(Request $request)
    {
        abort_unless(array_diff(array_keys($request->all()),['_token','username','password'])===[],422);
        $data=$request->validate(['username'=>'required|string|max:64','password'=>'required|string|max:256']);
        $intent=$request->session()->get('preview.intent');
        $auth=app(PreviewProfiles::class);
        abort_unless($auth->ready(),503,'Perfis de teste ainda não configurados.');
        if (!$auth->login($request,$data['username'],$data['password'])) {
            return redirect('/_preview/portal')->withErrors(['username'=>'Usuário ou senha inválidos.'])->withInput(['username'=>$data['username']]);
        }
        $p=$auth->current($request);
        return redirect($p['role']==='client'&&isset($p['customer_id'])&&in_array($intent,['onepage','landing-pages','performance'],true)?'/_preview/portal/shop?product='.$intent:'/_preview/portal/'.$p['role'].'/overview');
    }
    public function logout(Request $request)
    {
        $request->session()->invalidate(); $request->session()->regenerateToken();
        return redirect('/_preview/portal');
    }
    public function client(Request $request,string $section)
    {
        if (!app(PreviewProfiles::class)->current($request)) return redirect('/_preview/portal');
        $profile=$this->profile($request,'client');
        if (isset($profile['customer_id'])) {
            abort_unless(in_array($section,['overview','services','available','contracts','finance','requests','journey','delivery-review','files','notices','modules','account','help'],true),404);
            if ($section==='available') return redirect('/_preview/portal/shop');
            return $this->page('portal.preview.customer-panel',['section'=>$section,'orders'=>app(\App\Homologation\CustomerPreviewStore::class)->orders($profile['customer_id'])]);
        }
        // Same restricted PostgreSQL reader used by the test administrator.
        // No simulated active service grants or billing are implied.
        if (in_array($section,['services','finance'],true)) {
            return $this->page('portal.preview.client-orders',[
                'section'=>$section,
                'orders'=>app(\App\Homologation\PreviewOrderListing::class)->rows()
            ]);
        }
        $pages=['overview','services','available','contracts','modules','journey','requests','help','notices','delivery-review'];
        if (in_array($section,['finance','files','account'],true)) return $this->page('portal.preview.client-extra',['section'=>$section]);
        abort_unless(in_array($section,$pages,true),404);
        return $this->page('portal.demo.'.$section);
    }
    public function admin(Request $request,string $section)
    {
        if (!app(PreviewProfiles::class)->current($request)) return redirect('/_preview/portal');
        $this->profile($request,'admin');
        $pages=['overview','clients','orders','services','finance','contracts','requests','settings'];
        abort_unless(in_array($section,$pages,true),404);
        return $this->page('portal.preview.admin',['section'=>$section,'orders'=>[...app(\App\Homologation\PreviewOrderListing::class)->rows(),...app(\App\Homologation\CustomerPreviewStore::class)->orders(null)]]);
    }
    public function logo()
    {
        return response()->file(resource_path('portal-auth/logo.png'),['Content-Type'=>'image/png','X-Content-Type-Options'=>'nosniff','Cache-Control'=>'no-store']);
    }
}
