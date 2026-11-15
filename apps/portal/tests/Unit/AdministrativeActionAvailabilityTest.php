<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Domain\PerformanceAdministration\AdministrativeActionAvailability as A;
use PHPUnit\Framework\TestCase;
final class AdministrativeActionAvailabilityTest extends TestCase
{
 public function test_consume_requires_internal_valid_distinct_approved_actor_and_permission():void
 {
  $now=1800000000;$r=['kind'=>'internal_note','status'=>'approved','expires_at'=>$now+60,'version'=>1,'digest'=>str_repeat('a',64),'approved_by'=>2,'requested_by'=>3];
  $can=fn($a)=>true;
  self::assertArrayHasKey('approval.consume',A::actions('approvals',$r,$can,1,$now,$now));
  foreach([['kind'=>'financial'],['kind'=>'ads_budget'],['approved_by'=>1],['approved_by'=>null],['expires_at'=>$now],['status'=>'revoked'],['digest'=>'bad']] as $bad)
   self::assertArrayNotHasKey('approval.consume',A::actions('approvals',array_replace($r,$bad),$can,1,$now,$now));
  self::assertSame([],A::actions('approvals',$r,fn($a)=>false,1,$now,$now));
  self::assertSame([],A::actions('approvals',$r,$can,1,$now-901,$now));
 }
 public function test_resolution_requires_verified_evidence():void
 {
  self::assertSame([],A::actions('incidents',['status'=>'acknowledged','resolution_evidence'=>'unavailable'],fn($a)=>true,1,1800000000,1800000000));
  self::assertArrayHasKey('incident.resolve',A::actions('incidents',['status'=>'acknowledged','resolution_evidence'=>'synthetic_verified'],fn($a)=>true,1,1800000000,1800000000));
 }
}
