<?php
declare(strict_types=1);
namespace App\Providers;
use App\Domain\Commerce\D0\FirstBriefingRelease;
use App\Http\Controllers\OnepageFirstBriefingController;
use App\Http\Middleware\{EnsureOnepageCommerceEnabled,PortalBrowserAuthGuard};
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
final class OnepageD0CandidateServiceProvider extends ServiceProvider
{
 public function register():void {
  // Even with the route switch, no operational writer or provider reader is installed.
  $this->app->bindIf(FirstBriefingRelease::class,static fn()=>new FirstBriefingRelease());
 }
 public function boot():void {
  if($this->app['config']->get('portal_onepage_d0.enabled')!==true)return;
  Route::prefix('_development/commerce/onepage')->middleware([EnsureOnepageCommerceEnabled::class,'web',PortalBrowserAuthGuard::class])->group(static function():void {
   Route::post('orders/{orderId}/first-briefing-release',[OnepageFirstBriefingController::class,'attempt']);
   Route::get('orders/{orderId}/briefing-state',[OnepageFirstBriefingController::class,'state']);
  });
 }
}
