<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * F134: bind admin identity (from session guard) and requested organization
 * to a separately stored ACTIVE, non-expired seo.view grant.
 *
 * The organization name comes from the URL only as a lookup target; no
 * actor, permission, tenant-owner or grant can be supplied by the browser.
 * This DOES NOT verify a real contracted service or Google property owner.
 */
final class PortalAdminOrganizationSeoGate
{
    public function handle(Request $request,Closure $next):Response
    {
        $actor=Auth::guard('portal-admin')->user();
        $org=$request->route('organization');
        $id=$actor?->getAuthIdentifier();
        if(!is_int($id) && !(is_string($id)&&ctype_digit($id)))
            abort(404);
        $id=(int)$id;

        $mfaId=$request->session()->get('portal_admin_mfa_id');
        $mfaAt=$request->session()->get('portal_admin_mfa_at');
        if($id<1 || !is_int($mfaId) || $mfaId!==$id ||
           !is_int($mfaAt) ||
           $mfaAt>time()+30 ||
           $mfaAt<time()-(int)config('portal_admin.mfa_verified_seconds',3600) ||
           !is_string($org) ||
           preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{0,99}\z/D',$org)!==1)
            abort(404);

        // Always resolve actor from the authenticated DB session; an ID from
        // a request body, query parameter or cookie is never accepted.
        if(!DB::table('portal_admin_users')
            ->where('id',$id)->where('is_active',true)->exists())
            abort(404);
        $allowed=DB::table('portal_admin_org_grants')
            ->where('admin_user_id',$id)
            ->where('organization_ref',$org)
            ->where('permission','seo.view')
            ->where('status','active')
            ->where('expires_at','>',time())
            ->whereNull('revoked_at')
            ->exists();

        if(!$allowed)abort(404);
        return $next($request);
    }
}
