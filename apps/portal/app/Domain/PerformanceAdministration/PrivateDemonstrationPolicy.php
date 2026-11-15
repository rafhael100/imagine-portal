<?php
declare(strict_types=1);
namespace App\Domain\PerformanceAdministration;
use App\Domain\PortalAccess\Security\PortalAdminStagingSecurityPolicy;
final class PrivateDemonstrationPolicy
{
 public static function allowed():bool
 {
  if(config('performance_admin.enabled')!==true)return false;
  if(app()->environment(['local','testing']))return config('performance_admin.mode')==='isolated_ci';
  return app()->environment('staging')&&config('performance_admin.mode')==='private_staging'
      &&config('performance_admin.synthetic_only')===true&&PortalAdminStagingSecurityPolicy::issues('staging')===[];
 }
 public static function authorizesExternalOperations():bool{return false;}
}
