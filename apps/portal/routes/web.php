<?php
declare(strict_types=1);

use App\Http\Controllers\PortalAdminLoginController;
use App\Http\Middleware\PortalAdminFeatureGate;
use App\Http\Middleware\PortalAdminMfaGuard;
use Illuminate\Support\Facades\Route;

/*
 * F132: only Portal administration, with feature OFF by default.
 * No customer login, public registration, GSC or SEO data routes are exposed.
 * The web middleware provides cookies, server sessions and CSRF protection.
 */
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
    });
