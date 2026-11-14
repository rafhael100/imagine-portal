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
        // Preview-only abuse fence. Key is derived from the VERIFIED server
        // session, not a posted owner/customer_id or arbitrary IP header.
        // Separate customers of the same fictitious organization do not
        // consume one another's write quota; anonymous requests have IP scope.
        RateLimiter::for('preview-order-write',static function(Request $request) {
            $profile=app(PreviewProfiles::class)->current($request);
            $subject=isset($profile['customer_id'])
                ? 'customer:'.$profile['customer_id']
                : 'anonymous:'.$request->ip();
            return Limit::perMinute(12)->by('synthetic-preview-write:'.$subject);
        });
        Route::prefix('_preview/portal')->middleware('web')->group(function(): void {
            Route::get('/',[FullPortalPreviewController::class,'login']);
            Route::post('login',[FullPortalPreviewController::class,'authenticate'])->middleware('throttle:preview-login');
            Route::post('logout',[FullPortalPreviewController::class,'logout']);
            Route::get('register',[\App\Http\Controllers\CustomerPreviewController::class,'registerForm']);
            Route::post('register',[\App\Http\Controllers\CustomerPreviewController::class,'register'])->middleware('throttle:preview-register');
            Route::get('shop',[\App\Http\Controllers\CustomerPreviewController::class,'shop']);
            Route::post('shop/orders',[\App\Http\Controllers\CustomerPreviewController::class,'create'])->middleware('throttle:preview-order-write');
            Route::get('shop/orders/{id}',[\App\Http\Controllers\CustomerPreviewController::class,'show']);
            Route::post('shop/orders/{id}/payment',[\App\Http\Controllers\CustomerPreviewController::class,'payment'])->middleware('throttle:preview-order-write');
            Route::post('shop/orders/{id}/briefing',[\App\Http\Controllers\CustomerPreviewController::class,'briefing'])->middleware('throttle:preview-order-write');
            Route::get('logo',[FullPortalPreviewController::class,'logo']);
            Route::get('client/{section}',[FullPortalPreviewController::class,'client']);
            Route::get('admin/{section}',[FullPortalPreviewController::class,'admin']);
        });
        $this->app['router']->getRoutes()->refreshNameLookups();
    }
}
