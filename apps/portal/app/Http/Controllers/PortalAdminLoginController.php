<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\PortalAccess\Security\PortalAdminTotp;
use App\Models\PortalAdminUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Throwable;

/**
 * Minimal REAL password + pre-provisioned TOTP session flow, behind an
 * OFF-by-default HTTP feature gate. It does not grant any client tenant,
 * CRM, GSC or SEO permissions and contains no enrollment endpoints.
 */
final class PortalAdminLoginController
{
    public function loginForm():View
    {
        return view('portal-admin.login');
    }

    public function submitPassword(Request $request):RedirectResponse
    {
        $data=$request->validate([
            'email'=>['required','email','max:190'],
            'password'=>['required','string','max:512'],
        ]);
        $email=strtolower(trim((string)$data['email']));
        $password=(string)$data['password'];
        $key='portal_admin_password:'.hash(
            'sha256',$email.'|'.$request->ip()
        );
        $limit=(int)config('portal_admin.max_password_attempts',5);
        if(RateLimiter::tooManyAttempts($key,$limit))
            return back()->withErrors([
                'credentials'=>'Não foi possível autenticar.'
            ]);

        $account=PortalAdminUser::query()
            ->where('email',$email)->first();
        // Keep unknown-account password checking expensive too, without
        // reflecting existence or status of the account to the requester.
        $hash=$account?->getAuthPassword() ?? Hash::make(
            'invalid-portal-admin-account'
        );
        $valid=Hash::check($password,$hash);
        if(!$account || !$account->is_active || !$valid){
            RateLimiter::hit($key,300);
            return back()->withErrors([
                'credentials'=>'Não foi possível autenticar.'
            ]);
        }
        RateLimiter::clear($key);
        Auth::guard('portal-admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put([
            'portal_admin_pending_id'=>(int)$account->getKey(),
            'portal_admin_pending_at'=>time(),
        ]);
        // Do not issue an authenticated guard session before TOTP.
        return redirect()->route('portal-admin.mfa');
    }

    public function mfaForm(Request $request):View|RedirectResponse
    {
        if($this->pendingAccount($request)===null)
            return redirect()->route('portal-admin.login');
        return view('portal-admin.mfa');
    }

    public function submitMfa(Request $request):RedirectResponse
    {
        $data=$request->validate([
            'code'=>['required','string','regex:/\A[0-9]{6}\z/D'],
        ]);
        $admin=$this->pendingAccount($request);
        if($admin===null)return redirect()->route('portal-admin.login');

        $key='portal_admin_mfa:'.hash(
            'sha256',(string)$admin->getKey().'|'.$request->ip()
        );
        if(RateLimiter::tooManyAttempts($key,
            (int)config('portal_admin.max_mfa_attempts',5)))
            return back()->withErrors([
                'code'=>'Código inválido ou expirado.'
            ]);

        try{
            $hex=Crypt::decryptString(
                (string)$admin->totp_secret_ciphertext
            );
            $counter=PortalAdminTotp::match($hex,$data['code'],time());
        }catch(Throwable){
            $counter=null;
        }
        if($counter===null){
            RateLimiter::hit($key,300);
            return back()->withErrors([
                'code'=>'Código inválido ou expirado.'
            ]);
        }

        // Compare-and-swap across concurrent sessions: replay of the same
        // TOTP time step cannot issue multiple sessions for the same user.
        $updated=DB::table('portal_admin_users')
            ->where('id',$admin->getKey())
            ->where('is_active',true)
            ->where('last_totp_counter','<',$counter)
            ->update([
                'last_totp_counter'=>$counter,
                'updated_at'=>now(),
            ]);
        if($updated!==1){
            RateLimiter::hit($key,300);
            return back()->withErrors([
                'code'=>'Código inválido ou expirado.'
            ]);
        }

        RateLimiter::clear($key);
        Auth::guard('portal-admin')->login($admin);
        $request->session()->regenerate();
        $request->session()->forget([
            'portal_admin_pending_id','portal_admin_pending_at'
        ]);
        $request->session()->put([
            'portal_admin_mfa_id'=>(int)$admin->getKey(),
            'portal_admin_mfa_at'=>time(),
        ]);
        return redirect()->route('portal-admin.home');
    }

    public function home():View
    {
        // No customer records, tenant list, service grants or OAuth here.
        return view('portal-admin.home');
    }

    public function logout(Request $request):RedirectResponse
    {
        Auth::guard('portal-admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('portal-admin.login');
    }

    private function pendingAccount(Request $request):?PortalAdminUser
    {
        $id=$request->session()->get('portal_admin_pending_id');
        $since=$request->session()->get('portal_admin_pending_at');
        $now=time();
        if(!is_int($id)||$id<1||
            !is_int($since)||$since>$now+30 ||
            $since<$now-(int)config('portal_admin.pending_seconds',300))
            return null;
        $admin=PortalAdminUser::query()->find($id);
        return $admin?->is_active ? $admin : null;
    }
}
