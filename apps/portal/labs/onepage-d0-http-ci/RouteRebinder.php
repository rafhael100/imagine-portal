<?php
declare(strict_types=1);
namespace NativeHttpBriefing;
use App\Domain\Commerce\D0\FirstBriefingRelease;
use App\Http\Controllers\OnepageFirstBriefingController;
use RuntimeException;
/** Harness only: Laravel Route retains its resolved controller across in-process requests. */
final class RouteRebinder
{
 public static function replace($app,FirstBriefingRelease $release):void {
  $app->instance(FirstBriefingRelease::class,$release);
  $app->forgetInstance(OnepageFirstBriefingController::class);
  $count=0;
  foreach($app['router']->getRoutes() as $route){
   if($route->getControllerClass()===OnepageFirstBriefingController::class){$route->flushController();$count++;}
  }
  if($count!==2)throw new RuntimeException('D0_ROUTE_REBUILD_REFUSED');
 }
}
