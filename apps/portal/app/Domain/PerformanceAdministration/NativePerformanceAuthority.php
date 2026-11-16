<?php
declare(strict_types=1);
namespace App\Domain\PerformanceAdministration;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
/** Portal-owned authority. No browser identity, inherited SEO grant or production fixture promotion. */
final class NativePerformanceAuthority
{
 public function companies():array {
  // Company identifiers are navigation candidates only. lease() independently validates every selection.
  try{
   if(config('performance_admin.native_read_enabled')!==true||!PrivateDemonstrationPolicy::allowed())return [];
   $admin=Auth::guard('portal-admin')->user()?->fresh();if(!$admin||!$admin->is_active)return [];
   $db=DB::connection('performance_authority');
   $candidates=$db->transaction(function()use($db,$admin){
    $db->statement("SET LOCAL statement_timeout='1500ms'");
    $db->select("SELECT set_config('app.performance_actor',?,true)",[(string)$admin->id]);
    return $db->table('portal_performance_grants')->where('admin_user_id',$admin->id)->where('operation','dashboard.read')->whereNull('revoked_at')->where('expires_at','>',time())->orderBy('company_id')->limit(25)->pluck('company_id')->all();
   });
   $allowed=[];foreach($candidates as $company){try{$this->lease($company,'dashboard.read',(string)Str::uuid());$allowed[]=$company;}catch(Throwable){}}return $allowed;
  }catch(Throwable){return [];}
 }
 public function outcome(string $company,string $operation,string $correlation,string $outcome,?string $expectedLease=null):void {
  if(!in_array($outcome,['completed','failed'],true))throw new RuntimeException('PERFORMANCE_AUDIT_DENIED');
  $lease=$this->lease($company,$operation,$correlation);if($expectedLease!==null&&!hash_equals($expectedLease,$lease))throw new RuntimeException('PERFORMANCE_AUTHORITY_CHANGED');$actor=(int)Auth::guard('portal-admin')->id();
  DB::connection('performance_authority')->transaction(function()use($company,$operation,$correlation,$outcome,$lease,$actor){
   $db=DB::connection('performance_authority');$db->select("SELECT set_config('app.performance_actor',?,true),set_config('app.performance_company',?,true)",[(string)$actor,$company]);
   $db->table('portal_performance_access_audit')->insert(['id'=>(string)Str::uuid(),'admin_user_id'=>$actor,'company_id'=>$company,'operation'=>$operation,'correlation_id'=>$correlation,'outcome'=>$outcome,'lease_digest'=>$lease,'observed_at'=>time()]);
  });
 }
 public function lease(string $company,string $operation,string $correlation):string {
  try{
   if(config('performance_admin.native_read_enabled')!==true||!PrivateDemonstrationPolicy::allowed()||!Str::isUuid($company)||!Str::isUuid($correlation))throw new RuntimeException;
   $request=request();if(!$request->hasSession())throw new RuntimeException;
   $admin=Auth::guard('portal-admin')->user()?->fresh();$session=$request->session();$now=time();
   $mfa=$session->get('portal_admin_mfa_at');$epoch=$session->get('portal_admin_session_version');
   if(!$admin||!$admin->is_active||$session->get('portal_admin_mfa_id')!==(int)$admin->id||!is_int($epoch)||$epoch<1||$epoch!==(int)$admin->session_version||!is_int($mfa)||$mfa>$now||$mfa<=$now-900)throw new RuntimeException;
   $db=DB::connection('performance_authority');
   $ci=app()->environment('testing')&&getenv('CI')==='true'&&getenv('ADMIN_PHASE4_PG_CI')==='1';
   if(!$ci&&$db->getConfig('sslmode')!=='verify-full')throw new RuntimeException;
   return $db->transaction(function()use($db,$company,$operation,$correlation,$admin,$mfa,$epoch,$now){
    $db->statement("SET LOCAL statement_timeout='1500ms'");
    $profile=$db->selectOne("SELECT r.rolsuper OR r.rolbypassrls OR r.rolcreaterole OR r.rolcreatedb OR r.rolreplication OR bool_or(pg_has_role(current_user,c.relowner,'MEMBER')) OR bool_or(has_any_column_privilege(current_user,c.oid,'UPDATE')) OR bool_or(has_table_privilege(current_user,c.oid,'DELETE,TRUNCATE')) OR NOT bool_and(c.relrowsecurity AND c.relforcerowsecurity) AS unsafe FROM pg_roles r CROSS JOIN pg_class c WHERE r.rolname=current_user AND c.oid IN ('portal_performance_services'::regclass,'portal_performance_grants'::regclass,'portal_performance_access_audit'::regclass) GROUP BY r.oid,r.rolsuper,r.rolbypassrls,r.rolcreaterole,r.rolcreatedb,r.rolreplication");
    if(!$profile||$profile->unsafe)throw new RuntimeException;
    $db->select("SELECT set_config('app.performance_actor',?,true),set_config('app.performance_company',?,true)",[(string)$admin->id,$company]);
    $service=$db->table('portal_performance_services')->where('company_id',$company)->first();
    $grant=$db->table('portal_performance_grants')->where('admin_user_id',$admin->id)->where('company_id',$company)->where('operation',$operation)->first();
    $lease=null;
    if($service&&$grant&&($service->source_kind==='synthetic_persisted'||$service->source_kind==='portal_contract_authority')){
     // Private demo is always synthetic; operational source still requires separate homologation.
     if($service->source_kind!=='synthetic_persisted')throw new RuntimeException;
     $bridge=new PerformanceAuthorityBridge(fn()=>['operator_id'=>(int)$admin->id,'company_id'=>$company,'product'=>'performance',
      'operator_active'=>true,'company_active'=>(bool)$service->company_active,'contract_status'=>$service->status,'service_active'=>$service->status==='active',
      'session_epoch'=>$epoch,'revoked_at'=>$grant->revoked_at??$service->revoked_at,'expires_at'=>min((int)$grant->expires_at,(int)$service->expires_at),
      'mfa_at'=>$mfa,'role'=>$grant->role,'permissions'=>['performance.admin.'.$grant->operation],'grant_version'=>(int)$grant->revision,'contract_version'=>(int)$service->contract_version],fn()=>$now);
     try{$base=$bridge->lease((int)$admin->id,$company,$operation,$epoch);$lease=hash('sha256',json_encode([$base,$service->contract_ref,$service->source_kind,$grant->id],JSON_THROW_ON_ERROR));}catch(RuntimeException){}
    }
    $db->table('portal_performance_access_audit')->insert(['id'=>(string)Str::uuid(),'admin_user_id'=>$admin->id,'company_id'=>$company,'operation'=>$operation,
     'correlation_id'=>$correlation,'outcome'=>$lease?'granted':'denied','lease_digest'=>$lease,'observed_at'=>$now]);
    return $lease??'';
   }) ?: throw new RuntimeException;
  }catch(Throwable){throw new RuntimeException('PERFORMANCE_AUTHORITY_DENIED');}
 }
}
