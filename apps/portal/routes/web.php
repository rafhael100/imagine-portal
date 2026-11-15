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
        // F134: the requested organization is only a lookup target;
        // admin identity and fixed seo.view permission come from server state.
        Route::get('/seo-review/{organization}',[
            PortalAdminSeoScopeController::class,'show'
        ])->where('organization','[a-zA-Z0-9][a-zA-Z0-9._:-]{0,99}')
          ->middleware([
              PortalAdminMfaGuard::class,
              PortalAdminOrganizationSeoGate::class
          ])->name('seo-review');
    });
}
