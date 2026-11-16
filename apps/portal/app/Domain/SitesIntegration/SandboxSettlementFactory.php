<?php
declare(strict_types=1);
namespace App\Domain\SitesIntegration;
use PDO;
use RuntimeException;
use Throwable;
/** Explicit server bootstrap only. No config toggle, route or service-container binding. */
final class SandboxSettlementFactory
{
 public static function fromMountedToken(PDO $db,string $serverOwnedSandboxAccount):AsaasVerifiedSettlementSource
 {
  $path='/run/secrets/asaas_sandbox_api_token';
  try {
   if(!is_file($path)||is_link($path)||!is_readable($path)||filesize($path)>1024||(fileperms($path)&0037)!==0)throw new RuntimeException();
   $token=rtrim(file_get_contents($path),"\r\n");
   return new AsaasVerifiedSettlementSource(new FullInstallationBindingSource($db,new PgSandboxInstallationBindingSource($db,$serverOwnedSandboxAccount)),new AsaasReadOnlyPaymentClient('sandbox',$token));
  }catch(Throwable){throw new RuntimeException('Sandbox settlement dependency unavailable');}
 }
}
