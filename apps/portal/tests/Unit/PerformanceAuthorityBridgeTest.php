<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use App\Domain\PerformanceAdministration\PerformanceAuthorityBridge;
final class PerformanceAuthorityBridgeTest extends TestCase
{
 public function test_native_snapshot_requires_product_mfa_contract_and_operation_and_revalidates():void
 {
  $now=1794780000;$s=['operator_id'=>7,'company_id'=>'company_alfa','product'=>'performance','operator_active'=>true,'company_active'=>true,
   'contract_status'=>'active','service_active'=>true,'session_epoch'=>2,'revoked_at'=>null,'expires_at'=>$now+60,'mfa_at'=>$now,
   'role'=>'operations','permissions'=>['performance.admin.dashboard.read'],'grant_version'=>1,'contract_version'=>1];
  $p=new PerformanceAuthorityBridge(function()use(&$s){return $s;},fn()=>$now);
  $lease=$p->lease(7,'company_alfa','dashboard.read',2);self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$lease);
  foreach(['product'=>'seo','permissions'=>['seo.view'],'service_active'=>false,'contract_status'=>'suspended','company_id'=>'company_beta',
   'session_epoch'=>3,'mfa_at'=>$now-900,'expires_at'=>$now,'revoked_at'=>$now] as $key=>$bad){
   $old=$s[$key];$s[$key]=$bad;try{$p->lease(7,'company_alfa','dashboard.read',2);self::fail('Accepted '.$key);}catch(\RuntimeException $e){self::assertSame('Performance access denied',$e->getMessage());}$s[$key]=$old;
  }
  $s['grant_version']=2;self::assertNotSame($lease,$p->lease(7,'company_alfa','dashboard.read',2));
 }
}
