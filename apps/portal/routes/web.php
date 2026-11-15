<?php
declare(strict_types=1);

use App\Http\Controllers\PortalAdminLoginController;
use App\Http\Controllers\PortalAdminSeoScopeController;
use App\Http\Middleware\PortalAdminFeatureGate;
use App\Http\Middleware\PortalAdminMfaGuard;
use App\Http\Middleware\PortalAdminOrganizationSeoGate;
use Illuminate\Support\Facades\Route;

/*
 * F132: only Portal administration, with feature OFF by default.
 * No customer login, public registration, GSC or SEO data routes are exposed.
 * The web middleware provides cookies, server sessions and CSRF protection.
 */
// Preserve the legacy Portal guarantee: with the flag disabled, NO admin
// route is registered at all. The middleware separately denies runtime
// access if the flag is turned off after route caching.
if(config('portal_admin.enabled')===true){
Route::prefix('portal-admin')
    ->name('portal-admin.')
    ->middleware([PortalAdminFeatureGate::class])
    ->group(function():void {
        Route::get('/login',[
            PortalAdminLoginController::class,'loginForm'
        ])->name('login');
        Route::post('/login',[
            PortalAdminLoginController::class,'submitPassword'
        ])->name('login.submit');
        Route::get('/mfa',[
            PortalAdminLoginController::class,'mfaForm'
        ])->name('mfa');
        Route::post('/mfa',[
            PortalAdminLoginController::class,'submitMfa'
        ])->name('mfa.submit');
        Route::post('/logout',[
            PortalAdminLoginController::class,'logout'
        ])->name('logout');
        Route::get('/',[
            PortalAdminLoginController::class,'home'
        ])->middleware([PortalAdminMfaGuard::class])->name('home');

        // F136: session epoch is checked by MFA guard before the
        // organization-scoped grant lookup can take place.
        Route::get('/seo-review/{organization}',[
            PortalAdminSeoScopeController::class,'show'
        ])->where('organization','[a-zA-Z0-9][a-zA-Z0-9._:-]{0,99}')
          ->middleware([
              PortalAdminMfaGuard::class,
              PortalAdminOrganizationSeoGate::class
          ])->name('seo-review');
    });
}

// ADMIN-P0: explicit opt-in laboratory only; existing admin guard and MFA reused.
if(config('portal_admin.enabled')===true && config('performance_admin.enabled')===true
    && app()->environment(['local','testing'])){
    Route::prefix('portal-admin/performance')->name('performance-admin.')
        ->middleware([PortalAdminFeatureGate::class,PortalAdminMfaGuard::class,\App\Http\Middleware\PerformanceAdminLabGate::class])
        ->group(function():void{
            Route::get('/export/{tenant}',[\App\Http\Controllers\PerformanceAdminController::class,'export'])->where('tenant','lab-alfa|lab-beta')->name('export');
            Route::post('/command/{tenant}',[\App\Http\Controllers\PerformanceAdminController::class,'command'])->where('tenant','lab-alfa|lab-beta')->name('command');
            Route::get('/{module?}',[\App\Http\Controllers\PerformanceAdminController::class,'index'])->name('index');
        });
}
