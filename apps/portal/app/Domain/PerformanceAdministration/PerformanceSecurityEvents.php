<?php
declare(strict_types=1);
namespace App\Domain\PerformanceAdministration;
use Illuminate\Support\Facades\Log;
use RuntimeException;
final class PerformanceSecurityEvents {
 public static function projection(string $event,string $correlation):array {
  if(!in_array($event,['authority_denied','service_timeout','service_transport_denied','service_retry','service_read_candidate'],true)||!preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/D',$correlation))throw new RuntimeException('Security event denied');
  return ['schema'=>'performance.security.v1','event'=>$event,'correlation_id'=>$correlation,'source'=>'portal_private_candidate'];
 }
 public static function record(string $event,string $correlation):void {
  Log::channel('performance_security')->info('performance_security',self::projection($event,$correlation));
 }
}
