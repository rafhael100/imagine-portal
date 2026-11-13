<?php

declare(strict_types=1);
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FullPortalPreviewController;
use App\Homologation\PreviewProfiles;

/** Registered only by the dedicated isolated synthetic preview entry point. */
final class FullPortalPreviewServiceProvider extends ServiceProvider
{
    public function register(): void { $this->app->singleton(PreviewProfiles::class); }
    public function boot(): void
    {
        Route::prefix('_preview/portal')->middleware('web')->group(function(): void {
            Route::get('/',[FullPortalPreviewController::class,'login']);
            Route::post('login',[FullPortalPreviewController::class,'authenticate'])->middleware('throttle:6,1');
            Route::post('logout',[FullPortalPreviewController::class,'logout']);
            Route::get('logo',[FullPortalPreviewController::class,'logo']);
            Route::get('client/{section}',[FullPortalPreviewController::class,'client']);
            Route::get('admin/{section}',[FullPortalPreviewController::class,'admin']);
        });
        $this->app['router']->getRoutes()->refreshNameLookups();
    }
}
