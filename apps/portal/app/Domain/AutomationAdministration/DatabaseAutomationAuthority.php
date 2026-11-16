<?php
declare(strict_types=1);
namespace App\Domain\AutomationAdministration;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Portal-owned human authority; independent of CRM service identity and SEO/Performance grants. */
final class DatabaseAutomationAuthority
{
 public function allows(int $operator,string $company):bool {
  try {
   if(config('automation_admin.transport_enabled')!==true || !AutomationPolicyCatalog::available() || $operator<1 || !Str::isUuid($company))return false;
   $request=request();if(!$request->hasSession())return false;
   $admin=Auth::guard('portal-admin')->user()?->fresh();$session=$request->session();$now=time();
   $mfa=$session->get('portal_admin_mfa_at');$version=$session->get('portal_admin_session_version');
   if(!$admin||!$admin->is_active||(int)$admin->id!==$operator||$session->get('portal_admin_mfa_id')!==$operator
    ||!is_int($mfa)||$mfa>$now+30||$mfa<$now-(int)config('portal_admin.mfa_verified_seconds',3600)
    ||!is_int($version)||$version<1||$version!==(int)$admin->session_version)return false;
   $db=DB::connection('automation_authority');
   $ci=app()->environment('testing')&&getenv('CI')==='true'&&getenv('P20_AUTHORITY_PG_CI')==='1';
   if(!$ci&&$db->getConfig('sslmode')!=='verify-full')return false;
   return $db->transaction(function()use($db,$operator,$company,$now,$version){
    $db->statement("SET LOCAL statement_timeout='1500ms'");
    $profile=$db->selectOne("SELECT r.rolsuper OR r.rolbypassrls OR r.rolcreaterole OR r.rolcreatedb OR r.rolreplication OR bool_or(pg_has_role(current_user,c.relowner,'MEMBER')) OR bool_or(has_any_column_privilege(current_user,c.oid,'UPDATE')) OR bool_or(has_table_privilege(current_user,c.oid,'DELETE,TRUNCATE')) OR NOT bool_and(c.relrowsecurity AND c.relforcerowsecurity) AS unsafe FROM pg_roles r CROSS JOIN pg_class c WHERE r.rolname=current_user AND c.oid IN ('public.portal_automation_grants'::regclass,'public.portal_automation_access_audit'::regclass) GROUP BY r.oid,r.rolsuper,r.rolbypassrls,r.rolcreaterole,r.rolcreatedb,r.rolreplication");
    if(!$profile||$profile->unsafe)return false;
    $db->select("SELECT set_config('app.automation_admin_id',?,true),set_config('app.automation_company_id',?,true)",[(string)$operator,$company]);
    $grant=$db->table('portal_automation_grants')->where('admin_user_id',$operator)->where('company_id',$company)
     ->where('operation','automation.state.read')->where('state','active')->whereNull('revoked_at')->where('expires_at','>',$now)->first();
    $allowed=$grant!==null;
    // Audit is mandatory: a failed INSERT rolls back and denies the read.
    $db->table('portal_automation_access_audit')->insert(['id'=>(string)Str::uuid(),'admin_user_id'=>$operator,'company_id'=>$company,
     'operation'=>'automation.state.read','outcome'=>$allowed?'granted':'denied','grant_revision'=>$grant?->revision,
     'session_version'=>$version,'observed_at'=>$now]);
    return $allowed;
   });
  } catch(Throwable){return false;}
 }
}
