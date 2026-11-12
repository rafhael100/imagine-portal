<?php

use App\Http\Controllers\PortalBrowserAuthController;
use App\Http\Middleware\EnsurePortalDevelopmentAuthEnabled;
use App\Http\Middleware\PortalBrowserAuthGuard;
use Illuminate\Support\Facades\Route;

// Loaded only by the explicit development provider, never a business route.
Route::prefix('_development/auth')
    ->middleware([EnsurePortalDevelopmentAuthEnabled::class,'web',PortalBrowserAuthGuard::class])
    ->group(function():void {
        Route::get('csrf',[PortalBrowserAuthController::class,'csrf']);
        Route::post('password',[PortalBrowserAuthController::class,'password']);
        Route::post('mfa',[PortalBrowserAuthController::class,'mfa']);
        Route::get('session',[PortalBrowserAuthController::class,'current']);
        Route::post('logout',[PortalBrowserAuthController::class,'logout']);
    });
