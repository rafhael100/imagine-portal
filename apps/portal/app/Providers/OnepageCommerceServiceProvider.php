<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Commerce\OnepageOrderReader;
use App\Domain\Commerce\OnepageOrderStore;
use App\Http\Controllers\OnepageOrderController;
use App\Http\Middleware\EnsureOnepageCommerceEnabled;
use App\Http\Middleware\PortalBrowserAuthGuard;
use App\Infrastructure\Commerce\OnepagePrivateRuntime;
use App\Infrastructure\Commerce\PostgresOnepageOrders;
use App\Infrastructure\Identity\PortalBrowserSecretFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/** Private native buyer routes; all unrelated authority bindings remain denied. */
final class OnepageCommerceServiceProvider extends ServiceProvider
{
    private const ORIGIN = 'https://portal-dev.imaginecomunica.com.br';

    public function register(): void
    {
        if (!$this->enabled()) return;
        $this->assertConfiguration();
        $this->app->singleton(OnepagePrivateRuntime::class, static function ($app): OnepagePrivateRuntime {
            $identityPath = $app['config']->get('portal_development_auth.secret_file');
            $path = $app['config']->get('portal_onepage.connection_file');
            if (!is_string($identityPath) || !is_string($path)) throw new RuntimeException('Private Onepage configuration required.');
            return OnepagePrivateRuntime::load($path, PortalBrowserSecretFile::load($identityPath, self::ORIGIN), self::ORIGIN);
        });
        $this->app->singleton(OnepageOrderStore::class, static function ($app): OnepageOrderStore {
            $runtime = $app->make(OnepagePrivateRuntime::class);
            return new PostgresOnepageOrders($runtime->connect(), $runtime->databaseName());
        });
        $this->app->singleton(OnepageOrderReader::class, static fn ($app): OnepageOrderReader => $app->make(OnepageOrderStore::class));
    }

    public function boot(): void
    {
        if (!$this->enabled()) return;
        $this->assertConfiguration();
        Route::prefix('_development/commerce/onepage')
            ->middleware([EnsureOnepageCommerceEnabled::class, 'web', PortalBrowserAuthGuard::class])
            ->group(static function (): void {
                Route::post('quotes', [OnepageOrderController::class, 'create']);
                Route::get('quotes/{quoteId}', [OnepageOrderController::class, 'resume']);
                Route::get('quotes/{quoteId}/review', [OnepageOrderController::class, 'review']);
                Route::post('quotes/{quoteId}/accept', [OnepageOrderController::class, 'accept']);
                Route::get('orders/{orderId}', [OnepageOrderController::class, 'show']);
            });
    }

    private function enabled(): bool
    {
        return $this->app['config']->get('portal_onepage.enabled') === true;
    }

    private function assertConfiguration(): void
    {
        $c = $this->app['config'];
        if ($c->get('portal_development_auth.enabled') !== true
            || $c->get('app.env') !== 'staging' || $c->get('app.debug') !== false
            || $c->get('app.url') !== self::ORIGIN || $c->get('session.driver') !== 'portal_private_pg'
            || $c->get('session.cookie') !== '__Host-imagine-portal-session'
            || $c->get('session.secure') !== true || $c->get('session.http_only') !== true
            || $c->get('session.domain') !== null || $c->get('session.path') !== '/'
            || $c->get('session.serialization') !== 'json'
            || !in_array($c->get('session.same_site'), ['lax', 'strict'], true)
            || $this->app->routesAreCached() || $this->app->configurationIsCached()) {
            throw new RuntimeException('Explicit native Onepage development configuration required.');
        }
    }
}
