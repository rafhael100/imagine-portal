<?php

declare(strict_types=1);
namespace Tests\Unit;
use App\Domain\AutomationAdministration\AutomationStateReader;
use PHPUnit\Framework\TestCase;
final class AutomationStateReaderTest extends TestCase
{
 private const COMPANY='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
 private function snapshot():array{return ['schema'=>'CRM_AUTOMATION_STATE_V1','source_kind'=>'synthetic_private_database','company_id'=>self::COMPANY,'observed_at'=>time(),
  'settings_version'=>1,'counts'=>['pending'=>1,'completed'=>2,'cancelled'=>0,'failed'=>0],'events'=>[['action'=>'automation.task_created','at'=>time()-1]],'ai_provider_calls'=>0,'operational_authorization'=>false];}
 public function test_scoped_source_and_authority_are_checked_before_and_after():void {
  $calls=0;$reader=new AutomationStateReader(fn($company)=>$this->snapshot(),function($admin,$company)use(&$calls){$calls++;return $admin===7&&$company===self::COMPANY;});
  self::assertSame(2,$reader->read(7,self::COMPANY)['counts']['completed']);self::assertSame(2,$calls);
 }
 public function test_denied_scope_never_calls_transport():void {
  $reader=new AutomationStateReader(fn()=>throw new \LogicException('MUST_NOT_CALL'),fn()=>false);
  $this->expectException(\RuntimeException::class);$reader->read(7,self::COMPANY);
 }
 public function test_revocation_during_read_is_not_a_cached_grant():void {
  $calls=0;$reader=new AutomationStateReader(fn()=>$this->snapshot(),function()use(&$calls){return ++$calls===1;});
  $this->expectException(\RuntimeException::class);$reader->read(7,self::COMPANY);
 }
 public function test_mismatched_company_is_denied():void {
  $value=$this->snapshot();$value['company_id']='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
  $reader=new AutomationStateReader(fn()=>$value,fn()=>true);$this->expectException(\RuntimeException::class);$reader->read(7,self::COMPANY);
 }
 public function test_stale_or_operational_claims_are_rejected():void {
  $value=$this->snapshot();$value['operational_authorization']=true;
  $reader=new AutomationStateReader(fn()=>$value,fn()=>true);$this->expectException(\RuntimeException::class);$reader->read(7,self::COMPANY);
 }
}
