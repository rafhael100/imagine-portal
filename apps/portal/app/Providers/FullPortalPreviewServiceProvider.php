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
        RateLimiter::for('preview-register',static fn(Request $request)=>Limit::perMinute(6)->by($request->ip()));
        RateLimiter::for('preview-login',static fn(Request $request)=>[Limit::perMinute(60)->by('ip:'.$request->ip()),Limit::perMinute(6)->by('account:'.$request->ip().':'.hash('sha256',(string)$request->input('username','')))]);
        Route::prefix('_preview/portal')->middleware('web')->group(function(): void {
            Route::get('/',[FullPortalPreviewController::class,'login']);
            Route::post('login',[FullPortalPreviewController::class,'authenticate'])->middleware('throttle:preview-login');
            Route::post('logout',[FullPortalPreviewController::class,'logout']);
            Route::get('register',[\App\Http\Controllers\CustomerPreviewController::class,'registerForm']);
            Route::post('register',[\App\Http\Controllers\CustomerPreviewController::class,'register'])->middleware('throttle:preview-register');
            Route::get('shop',[\App\Http\Controllers\CustomerPreviewController::class,'shop']);
            Route::post('shop/orders',[\App\Http\Controllers\CustomerPreviewController::class,'create']);
            Route::get('shop/orders/{id}',[\App\Http\Controllers\CustomerPreviewController::class,'show']);
            Route::post('shop/orders/{id}/payment',[\App\Http\Controllers\CustomerPreviewController::class,'payment']);
            Route::post('shop/orders/{id}/briefing',[\App\Http\Controllers\CustomerPreviewController::class,'briefing']);
            Route::get('work/{id}',[\App\Http\Controllers\CustomerWorkPreviewController::class,'show']);
            Route::post('work/{id}',[\App\Http\Controllers\CustomerWorkPreviewController::class,'action']);
            Route::get('logo',[FullPortalPreviewController::class,'logo']);
            Route::get('client/{section}',[FullPortalPreviewController::class,'client']);
            Route::get('admin/{section}',[FullPortalPreviewController::class,'admin']);
        });
        $this->app['router']->getRoutes()->refreshNameLookups();
    }
}
