<?php

declare(strict_types=1);
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FullPortalPreviewController;
use App\Homologation\PreviewProfiles;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

/** Registered only by the dedicated isolated synthetic preview entry point. */
final class FullPortalPreviewServiceProvider extends ServiceProvider
{
    public function register(): void { $this->app->singleton(PreviewProfiles::class); }
    public function boot(): void
    {
        RateLimiter::for('preview-login',static fn(Request $request)=>Limit::perMinute(6)->by($request->ip()));
        Route::prefix('_preview/portal')->middleware('web')->group(function(): void {
            Route::get('/',[FullPortalPreviewController::class,'login']);
            Route::post('login',[FullPortalPreviewController::class,'authenticate'])->middleware('throttle:preview-login');
            Route::post('logout',[FullPortalPreviewController::class,'logout']);
            Route::get('logo',[FullPortalPreviewController::class,'logo']);
            Route::get('client/{section}',[FullPortalPreviewController::class,'client']);
            Route::get('admin/{section}',[FullPortalPreviewController::class,'admin']);
        });
        $this->app['router']->getRoutes()->refreshNameLookups();
    }
}
