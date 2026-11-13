<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Identity\Browser\PortalBrowserAuthentication;
use App\Http\Controllers\PortalBrowserAuthController;
use App\Http\Middleware\PortalBrowserAuthGuard;
use App\Http\Middleware\PortalRevocationOnlyNoServingGuard;
use App\Infrastructure\Recovery\PortalWitnessOnlyIdentityRead;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * F384 PHPUnit-only routes prove revocation survives witness outage while
 * NO OTHER controller has a serving exception.
 */
final class PortalRevocationOnlyNoServingHttpTest extends TestCase
{
    private const ORIGIN='https://portal.example.test';
    private const ORG='22222222-2222-4222-8222-222222222222';
    private const REF='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const CSRF='internal-f384-only-synthetic-csrf-token';

    private function install(): array
    {
        config([
            'app.url'=>self::ORIGIN,
            'session.driver'=>'array',
            'portal_development_auth.organization'=>self::ORG,
        ]);
        $this->app['session']->forgetDrivers();

        $identity=new class implements PortalBrowserAuthentication {
            public array $revoked=[];
            public int $password=0;
            public int $mfa=0;
            public int $current=0;
            public bool $revokeSucceeds=true;

            public function beginPassword(string $email,string $password,string $organization,string $sourceIp):?string {
                $this->password++;return str_repeat('a',64);
            }
            public function completeMfa(string $pendingReference,string $code,string $sourceIp):?string {
                $this->mfa++;return str_repeat('c',64);
            }
            public function isActiveBusinessSession(string $reference):bool {
                $this->current++;return true;
            }
            public function revoke(string $reference):bool {
                $this->revoked[]=$reference;
                return $this->revokeSucceeds;
            }
        };
        $witness=new class implements PortalWitnessOnlyIdentityRead {
            public int $calls=0;
            public function inspectBusinessSession(string $reference):array {
                $this->calls++;
                throw new \RuntimeException('synthetic independent witness offline');
            }
        };
        $this->app->instance(PortalBrowserAuthentication::class,$identity);
        $this->app->instance(PortalWitnessOnlyIdentityRead::class,$witness);

        Route::middleware([
            'web',PortalBrowserAuthGuard::class,PortalRevocationOnlyNoServingGuard::class,
        ])->group(function ():void {
            Route::post('/__f384/logout',[PortalBrowserAuthController::class,'logout'])
                ->name('portal.f384.revocation-only');
            Route::post('/__f384/password',[PortalBrowserAuthController::class,'password']);
            Route::post('/__f384/mfa',[PortalBrowserAuthController::class,'mfa']);
            Route::get('/__f384/session',[PortalBrowserAuthController::class,'current']);
            Route::get('/__f384/csrf',[PortalBrowserAuthController::class,'csrf']);
            Route::get('/__f384/forged-logout',[PortalBrowserAuthController::class,'logout'])
                ->name('portal.f384.revocation-only');
            Route::post('/__f384/forged-controller',static fn()=>response()->json(['forged'=>true]))
                ->name('portal.f384.revocation-only');
        });
        return [$identity,$witness];
    }

    private function request(string $method,string $path,array $body=[],array $state=[],array $headers=[]):\Illuminate\Testing\TestResponse
    {
        $this->withSession(array_replace(['_token'=>self::CSRF],$state));
        $server=array_replace([
            'HTTP_ORIGIN'=>self::ORIGIN,
            'HTTP_SEC_FETCH_SITE'=>'same-origin',
            'HTTP_X_CSRF_TOKEN'=>self::CSRF,
            'HTTP_ACCEPT'=>'application/json',
            'CONTENT_TYPE'=>'application/json',
            'REMOTE_ADDR'=>'127.0.0.1',
        ],$headers);
        return $this->call($method,self::ORIGIN.$path,[],[],[],$server,
            json_encode($body,JSON_THROW_ON_ERROR));
    }

    private function state():array
    {
        return [
            'portal.browser_auth'=>[
                'phase'=>'business',
                'reference'=>self::REF,
            ],
        ];
    }

    private function denied(\Illuminate\Testing\TestResponse $result):void
    {
        $result->assertStatus(503)->assertExactJson(['error'=>'portal_not_ready']);
        self::assertSame('no-store, private',$result->headers->get('Cache-Control'));
        self::assertSame('DENY',$result->headers->get('X-Frame-Options'));
        self::assertSame('nosniff',$result->headers->get('X-Content-Type-Options'));
    }

    public function test_witness_offline_still_allows_only_real_csrf_protected_durable_logout():void
    {
        [$identity,$witness]=$this->install();
        $this->request('POST','/__f384/logout',[], $this->state())->assertNoContent();
        self::assertSame([self::REF],$identity->revoked);
        self::assertSame(0,$identity->password);
        self::assertSame(0,$identity->mfa);
        self::assertSame(0,$identity->current);
        self::assertSame(0,$witness->calls);
        self::assertNull($this->app['session']->driver()->get('portal.browser_auth'));
    }

    public function test_all_authentication_business_and_same_named_impostors_remain_denied():void
    {
        [$identity,$witness]=$this->install();
        foreach([
            ['POST','/__f384/password',[
                'email'=>'synthetic@example.invalid',
                'password'=>'synthetic-password-only',
                'organization'=>self::ORG,
            ]],
            ['POST','/__f384/mfa',['code'=>'123456']],
            ['GET','/__f384/session',[]],
            ['GET','/__f384/csrf',[]],
            ['GET','/__f384/forged-logout',[]],
            ['POST','/__f384/forged-controller',[]],
        ] as [$method,$path,$body]){
            $this->denied($this->request($method,$path,$body,$this->state()));
        }
        self::assertSame([], $identity->revoked);
        self::assertSame(0,$identity->password);
        self::assertSame(0,$identity->mfa);
        self::assertSame(0,$identity->current);
        self::assertSame(0,$witness->calls);
    }

    public function test_missing_csrf_or_sibling_origin_cannot_trigger_revocation():void
    {
        [$identity,$witness]=$this->install();
        $this->request('POST','/__f384/logout',[],$this->state(),[
            'HTTP_X_CSRF_TOKEN'=>'wrong',
        ])->assertStatus(419);
        $this->request('POST','/__f384/logout',[],$this->state(),[
            'HTTP_ORIGIN'=>'https://crm.example.test',
        ])->assertStatus(419);
        self::assertSame([], $identity->revoked);
        self::assertSame(0,$witness->calls);
    }

    public function test_failed_durable_revoke_is_never_claimed_success():void
    {
        [$identity,$witness]=$this->install();
        $identity->revokeSucceeds=false;
        $this->request('POST','/__f384/logout',[],$this->state())
            ->assertStatus(503)->assertExactJson(['error'=>'authentication_unavailable']);
        self::assertSame([self::REF],$identity->revoked);
        self::assertSame(0,$witness->calls);
        self::assertNull($this->app['session']->driver()->get('portal.browser_auth'));
        // Operational retry/outbox for failed DB revocation is still absent.
    }

    public function test_no_server_side_session_is_idempotent_logout_without_a_db_mutation():void
    {
        [$identity,$witness]=$this->install();
        $this->request('POST','/__f384/logout')->assertNoContent();
        self::assertSame([],$identity->revoked);
        self::assertSame(0,$witness->calls);
    }

    public function test_no_permanent_release_route_or_service_binding_exists():void
    {
        self::assertFalse($this->app->bound(PortalRevocationOnlyNoServingGuard::class));
        $routes=[];
        foreach($this->app['router']->getRoutes() as $route){
            $routes[]=$route->uri();
        }
        self::assertSame(['up'],$routes);
        $this->post('/logout')->assertNotFound();
    }
}
