<?php
namespace Tests\Unit;
use Tests\TestCase;
use App\Domain\PerformanceAdministration\PerformanceSecurityEvents as E;
final class PerformanceSecurityEventsTest extends TestCase {
 public function test_projection_has_only_fixed_fields_and_correlation():void {
  $id='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
  $e=E::projection('service_timeout',$id);$this->assertSame(['schema','event','correlation_id','source'],array_keys($e));$this->assertSame($id,$e['correlation_id']);
 }
 public function test_untrusted_event_cannot_inject_log_payload():void {$this->expectException(\RuntimeException::class);E::projection("user=secret\n",'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');}
 public function test_non_uuid_correlation_cannot_leak_headers():void {$this->expectException(\RuntimeException::class);E::projection('authority_denied','email@example.test');}
}
