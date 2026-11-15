<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A password-only/pending MFA session NEVER reaches the admin shell.
 * The shell has no client resources, grants, OAuth or tenant data yet.
 */
final class PortalAdminMfaGuard
{
    public function handle(Request $request,Closure $next):Response
    {
        $guard=Auth::guard('portal-admin');
        $admin=$guard->user();
        $session=$request->session();
        $at=$session->get('portal_admin_mfa_at');
        $id=$session->get('portal_admin_mfa_id');
        if($admin===null || !$admin->is_active ||
           !is_int($at)||$at<=0||
           !is_int($id)||$id!==(int)$admin->getAuthIdentifier()||
           $at>time()+30||
           $at<time()-(int)config('portal_admin.mfa_verified_seconds',3600)){
            if($admin!==null){
                $guard->logout();
                $session->invalidate();
                $session->regenerateToken();
            }
            return redirect()->route('portal-admin.login');
        }
        return $next($request);
    }
}
