<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\RestoreSafety\LabExternalWitnessReader;
use App\Domain\RestoreSafety\LabRestoreServingGate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class LabRestoreRuntimeBoundaryTest extends TestCase
{
    public function test_recovery_laboratory_has_no_registered_witness_adapter_or_http_controller(): void
    {
        self::assertFalse($this->app->bound(LabExternalWitnessReader::class));
        self::assertFalse((new LabRestoreServingGate())->inspect(null, null, null, null)->canAuthorizeHttp());
        $before = array_map(
            static fn ($route): string => implode(',', $route->methods()).':'.$route->uri(),
            iterator_to_array(Route::getRoutes())
        );

        foreach ([
            '/portal', '/portal/login', '/portal/recovery/release',
            '/portal/recovery/attest', '/portal/sessions',
            '/login', '/mfa', '/admin/restore',
        ] as $path) {
            $this->get($path)->assertNotFound();
        }

        $after = array_map(
            static fn ($route): string => implode(',', $route->methods()).':'.$route->uri(),
            iterator_to_array(Route::getRoutes())
        );
        self::assertSame($before, $after);
        self::assertFalse($this->app['session']->isStarted());
    }
}
