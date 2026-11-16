<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\AutomationAdministration\AutomationStateReader;
use App\Domain\PerformanceAdministration\AuthorizedPerformanceRead;
use App\Providers\AppServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Routing\RoutingServiceProvider;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Registration only: no kernel, .env, database, HTTP dispatch or reader calls. */
final class ProductToggleRegistrationTest extends TestCase
{
    public static function flags(): iterable
    {
        foreach ([false, true] as $performance) {
            foreach ([false, true] as $native) {
                foreach ([false, true] as $automation) {
                    foreach ([false, true] as $transport) {
                        yield implode('-', array_map(intval(...), [$performance, $native, $automation, $transport]))
                            => [$performance, $native, $automation, $transport];
                    }
                }
            }
        }
    }

    #[DataProvider('flags')]
    public function test_product_flags_keep_registrations_independent(
        bool $performance, bool $native, bool $automation, bool $transport
    ): void {
        $flags = [$performance, $native, $automation, $transport];
        $baseline = $this->snapshot($flags);
        // Every single-flag edge, in both directions, with fresh applications.
        foreach ([0, 1, 2, 3] as $index) {
            $changed = $flags;
            $changed[$index] = !$changed[$index];
            $after = $this->snapshot($changed);
            $other = $index < 2 ? 'automation' : 'performance';
            $this->assertSame($baseline[$other], $after[$other], 'Other product changed for flag '.$index);
            $this->assertSame($baseline['shared'], $after['shared']);
        }
        $this->assertSame($baseline, $this->snapshot($flags));
    }

    private function snapshot(array $flags): array
    {
        [$performance, $native, $automation, $transport] = $flags;
        $previousContainer = Container::getInstance();
        $previousFacade = Facade::getFacadeApplication();
        $app = new Application(dirname(__DIR__, 2));
        $app->instance('env', 'testing');
        $app->instance('config', new Repository([
            'portal_admin' => ['enabled' => true],
            'performance_admin' => [
                'enabled' => $performance, 'native_read_enabled' => $native,
                'mode' => 'isolated_ci', 'service_identity_file' => '',
            ],
            'automation_admin' => [
                'enabled' => $automation, 'transport_enabled' => $transport, 'mode' => 'isolated_ci',
                'ca_file' => '', 'certificate_file' => '', 'private_key_file' => '', 'signing_key_file' => '',
            ],
        ]));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        try {
            $before = $app['config']->all();
            $app->register(EventServiceProvider::class);
            $app->register(RoutingServiceProvider::class);
            $app->register(AppServiceProvider::class);
            require $app->basePath('routes/web.php');
            $app->boot();

            $routes = ['performance' => [], 'automation' => [], 'shared' => []];
            foreach ($app['router']->getRoutes() as $route) {
                $name = $route->getName();
                $product = str_starts_with($name, 'performance-') ? 'performance'
                    : (str_starts_with($name, 'automation-') ? 'automation' : 'shared');
                $routes[$product][$name] = [$route->uri(), $route->methods(), $route->getActionName(),
                    $route->middleware(), $route->wheres];
            }
            $expectedPerformance = $performance ? ['performance-admin.export', 'performance-admin.command', 'performance-admin.index'] : [];
            if ($performance && $native) {
                $expectedPerformance = [...$expectedPerformance, 'performance-private.index', 'performance-private.show'];
            }
            $this->assertSame($expectedPerformance, array_keys($routes['performance']));
            $this->assertSame($automation ? ['automation-admin.index'] : [], array_keys($routes['automation']));
            $this->assertCount(7, $routes['shared']);
            // #917 intentionally binds Performance unconditionally; this is not authorization.
            $this->assertTrue($app->bound(AuthorizedPerformanceRead::class));
            $this->assertInstanceOf(AuthorizedPerformanceRead::class, $app->make(AuthorizedPerformanceRead::class));
            $automationBound = $app->bound(AutomationStateReader::class);
            $this->assertSame($automation && $transport, $automationBound);
            if ($automationBound) {
                $this->assertInstanceOf(AutomationStateReader::class, $app->make(AutomationStateReader::class));
            }
            $this->assertSame($before, $app['config']->all());
            $this->assertFalse($app->bound('db'), 'No database provider in this harness');
            return [
                'performance' => [$routes['performance'], $app->bound(AuthorizedPerformanceRead::class), $app['config']['performance_admin']],
                'automation' => [$routes['automation'], $automationBound, $app['config']['automation_admin']],
                'shared' => $routes['shared'],
            ];
        } finally {
            $app->flush();
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($previousFacade);
            Container::setInstance($previousContainer);
        }
    }
}
