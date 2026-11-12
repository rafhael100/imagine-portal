<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Identity\Browser\DenyingPortalBrowserAuthentication;
use App\Domain\Identity\Browser\PortalBrowserAuthentication;
use App\Http\Middleware\EnsurePortalDevelopmentAuthEnabled;
use App\Providers\PortalDevelopmentAuthServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

final class PortalDevelopmentAuthActivationTest extends TestCase
{
    public function test_default_application_remains_health_only_and_denies_authentication():void
    {
        self::assertFalse(config('portal_development_auth.enabled'));
        self::assertInstanceOf(DenyingPortalBrowserAuthentication::class,$this->app->make(PortalBrowserAuthentication::class));
        $uris=array_map(static fn($route)=>$route->uri(),iterator_to_array(Route::getRoutes()));
        self::assertSame(['up'],$uris);
        $this->getJson('/_development/auth/csrf')->assertNotFound();
    }

    public function test_disabled_switch_does_not_load_missing_secrets_or_require_staging():void
    {
        config(['portal_development_auth.enabled'=>false,'portal_development_auth.secret_file'=>'/missing/private/runtime.json',
            'app.env'=>'production','app.url'=>'http://invalid.example']);
        $provider=new PortalDevelopmentAuthServiceProvider($this->app);
        $provider->register();$provider->boot();
        self::assertInstanceOf(DenyingPortalBrowserAuthentication::class,$this->app->make(PortalBrowserAuthentication::class));
    }

    public function test_optin_refuses_production_debug_http_or_wrong_session_driver():void
    {
        $base=['portal_development_auth.enabled'=>true,'app.env'=>'staging','app.debug'=>false,
            'app.url'=>'https://portal-dev.imaginecomunica.com.br','session.driver'=>'portal_private_pg'];
        foreach([
            ['app.env'=>'production'],['app.debug'=>true],['app.url'=>'http://portal-dev.imaginecomunica.com.br'],
            ['app.url'=>'https://crm-dev.imaginecomunica.com.br'],['session.driver'=>'array'],
        ]as$change){
            config(array_replace($base,$change));
            try{(new PortalDevelopmentAuthServiceProvider($this->app))->register();self::fail('Unsafe activation accepted.');}
            catch(RuntimeException $error){self::assertSame('Explicit uncached private Portal development configuration required.',$error->getMessage());}
        }
    }

    public function test_optin_refuses_shared_or_weakened_session_cookies_and_php_serialization():void
    {
        $base=['portal_development_auth.enabled'=>true,'app.env'=>'staging','app.debug'=>false,
            'app.url'=>'https://portal-dev.imaginecomunica.com.br','session.driver'=>'portal_private_pg',
            'session.cookie'=>'__Host-imagine-portal-session','session.domain'=>null,'session.path'=>'/',
            'session.secure'=>true,'session.http_only'=>true,'session.serialization'=>'json','session.same_site'=>'lax'];
        foreach([
            ['session.domain'=>'.imaginecomunica.com.br'],['session.cookie'=>'crm-session'],['session.path'=>'/portal'],
            ['session.secure'=>false],['session.http_only'=>false],['session.serialization'=>'php'],['session.same_site'=>'none'],
        ]as$change){
            config(array_replace($base,$change));
            try{(new PortalDevelopmentAuthServiceProvider($this->app))->register();self::fail('Unsafe cookies accepted.');}
            catch(RuntimeException $error){self::assertSame('Explicit uncached private Portal development configuration required.',$error->getMessage());}
        }
    }

    public function test_request_gate_never_calls_authentication_when_activation_is_absent():void
    {
        config(['portal_development_auth.enabled'=>false]);
        $called=false;
        $response=(new EnsurePortalDevelopmentAuthEnabled())->handle(Request::create('/_development/auth/session'),
            function()use(&$called){$called=true;return response('unexpected');});
        self::assertSame(404,$response->getStatusCode());
        self::assertFalse($called);
        self::assertStringContainsString('no-store',$response->headers->get('Cache-Control'));
    }
}
