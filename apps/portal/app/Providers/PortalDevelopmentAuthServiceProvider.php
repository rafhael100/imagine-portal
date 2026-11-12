<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Identity\Browser\PortalBrowserAuthentication;
use App\Infrastructure\Identity\PortalBrowserPrivateRuntime;
use App\Infrastructure\Identity\PortalBrowserSecretFile;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PortalBrowserAuthController;
use App\Http\Middleware\EnsurePortalDevelopmentAuthEnabled;
use App\Http\Middleware\PortalBrowserAuthGuard;
use RuntimeException;

/** Explicit development-only activation. Business authority bindings stay DENY. */
final class PortalDevelopmentAuthServiceProvider extends ServiceProvider
{
    private const ORIGIN='https://portal-dev.imaginecomunica.com.br';

    public function register(): void
    {
        if (!$this->enabled()) return;
        $this->assertConfiguration();
        $this->app->singleton(PortalBrowserPrivateRuntime::class, static function($app): PortalBrowserPrivateRuntime {
            $path=$app['config']->get('portal_development_auth.secret_file');
            if (!is_string($path)) throw new RuntimeException('Private Portal development credentials required.');
            return new PortalBrowserPrivateRuntime(PortalBrowserSecretFile::load($path,self::ORIGIN));
        });
        $this->app->singleton(PortalBrowserAuthentication::class, static fn($app): PortalBrowserAuthentication =>
            $app->make(PortalBrowserPrivateRuntime::class)->authentication());
    }

    public function boot(): void
    {
        if (!$this->enabled()) return;
        $this->assertConfiguration();
        // The cipher is checked for key independence by the existing factory.
        $creator=$this->app->make(PortalBrowserPrivateRuntime::class)
            ->sessionCreator($this->app->make('encrypter'));
        $this->app->make('session')->extend('portal_private_pg',$creator);
        // Register only after every explicit development prerequisite has passed.
        Route::prefix('_development/auth')
            ->middleware([EnsurePortalDevelopmentAuthEnabled::class,'web',PortalBrowserAuthGuard::class])
            ->group(function():void {
                Route::get('login',function() {
                    $organization=config('portal_development_auth.organization');
                    $ready=is_string($organization)&&preg_match('/\\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\\z/D',$organization)===1;
                    return response()->view('portal.auth.private-login',[
                        'organization'=>$ready?$organization:'',
                        'ready'=>$ready,
                    ])->withHeaders([
                        'Content-Security-Policy'=>"default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'",
                        'Permissions-Policy'=>'camera=(), microphone=(), geolocation=()',
                    ]);
                });
                foreach(['ui.css'=>'text/css; charset=utf-8','ui.js'=>'text/javascript; charset=utf-8','logo.png'=>'image/png']as$file=>$type){
                    Route::get($file,static fn()=>response()->file(public_path('portal-auth/'.$file),[
                        'Content-Type'=>$type,'X-Content-Type-Options'=>'nosniff',
                        'Cache-Control'=>'no-store, private',
                    ]));
                }
                Route::get('csrf',[PortalBrowserAuthController::class,'csrf']);
                Route::post('password',[PortalBrowserAuthController::class,'password']);
                Route::post('mfa',[PortalBrowserAuthController::class,'mfa']);
                Route::get('session',[PortalBrowserAuthController::class,'current']);
                Route::post('logout',[PortalBrowserAuthController::class,'logout']);
            });
    }

    private function enabled(): bool
    {
        return $this->app['config']->get('portal_development_auth.enabled')===true;
    }

    private function assertConfiguration(): void
    {
        $c=$this->app['config'];
        if ($c->get('app.env')!=='staging'||$c->get('app.debug')!==false
            ||$c->get('app.url')!==self::ORIGIN
            ||$c->get('session.driver')!=='portal_private_pg'
            ||$c->get('session.cookie')!=='__Host-imagine-portal-session'
            ||$c->get('session.secure')!==true||$c->get('session.http_only')!==true
            ||$c->get('session.domain')!==null||$c->get('session.path')!=='/'
            ||$c->get('session.serialization')!=='json'
            ||!in_array($c->get('session.same_site'),['lax','strict'],true)
            ||$this->app->routesAreCached()||$this->app->configurationIsCached()) {
            throw new RuntimeException('Explicit uncached private Portal development configuration required.');
        }
    }
}
