<?php
namespace Tests\Unit;
use Tests\TestCase;
use App\Domain\PerformanceAdministration\PrivateDemonstrationPolicy as P;
final class PrivateDemonstrationPolicyTest extends TestCase
{
 public function test_private_mode_never_opens_production_or_external_actions():void
 {
  config()->set('performance_admin.enabled',true);config()->set('performance_admin.mode','private_staging');
  $this->app->instance('env','production');self::assertFalse(P::allowed());self::assertFalse(P::authorizesExternalOperations());
  $this->app->instance('env','staging');self::assertFalse(P::allowed());
  $this->app->instance('env','testing');self::assertFalse(P::allowed());
  config()->set('performance_admin.mode','isolated_ci');self::assertTrue(P::allowed());
 }
}
