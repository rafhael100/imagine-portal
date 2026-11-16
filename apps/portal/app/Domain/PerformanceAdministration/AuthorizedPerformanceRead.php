<?php
declare(strict_types=1);
namespace App\Domain\PerformanceAdministration;
use Closure;
use RuntimeException;
/** Revalidate native password/MFA/session + service + grant around each S2S read. */
final class AuthorizedPerformanceRead {
 public function __construct(private NativePerformanceAuthority $authority,private Closure $read){}
 public function read(string $company,string $operation,string $correlation):array {
  $lease=$this->authority->lease($company,$operation,$correlation);
  try{$result=($this->read)($company,$operation,$correlation);}catch(\Throwable){
   try{$this->authority->outcome($company,$operation,$correlation,'failed');}catch(\Throwable){}
   throw new RuntimeException('PERFORMANCE_READ_UNAVAILABLE');
  }
  if(!hash_equals($lease,$this->authority->lease($company,$operation,$correlation)))throw new RuntimeException('PERFORMANCE_AUTHORITY_CHANGED');
  $this->authority->outcome($company,$operation,$correlation,'completed');return $result;
 }
}
