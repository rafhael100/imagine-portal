<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Presentation\DemoPortalStaticExporter;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Tests\TestCase;

final class DemoPortalStaticExporterTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryDirectory = sys_get_temp_dir() . '/portal-f58-' . bin2hex(random_bytes(10));
        $db = $this->createMock(DatabaseManager::class);
        $db->expects(self::never())->method('connection');
        $this->app->instance('db', $db);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->temporaryDirectory)) {
            foreach (glob($this->temporaryDirectory . '/*.html') ?: [] as $page) {
                unlink($page);
            }
            rmdir($this->temporaryDirectory);
        }
        parent::tearDown();
    }

    public function test_export_creates_navigable_index_and_read_only_review_preview(): void
    {
        $pages = (new DemoPortalStaticExporter())->export($this->temporaryDirectory);
        self::assertCount(9, $pages);
        self::assertContains('index.html', $pages);
        self::assertContains('requests.html', $pages);
        self::assertContains('delivery-review.html', $pages);

        $index = file_get_contents($this->temporaryDirectory . '/index.html');
        self::assertIsString($index);
        self::assertStringContainsString('Somente dados fictícios', $index);

        $review = file_get_contents($this->temporaryDirectory . '/delivery-review.html');
        self::assertIsString($review);
        self::assertStringContainsString('href="index.html"', $review);
        self::assertStringContainsString('DEMO-AURORA-001', $review);
        self::assertStringContainsString('Revisão de entregas', $review);
        self::assertStringContainsString('SIMULAÇÃO LOCAL', $review);
        foreach (['<form', '<script', '<button', 'http://', 'https://'] as $prohibited) {
            self::assertStringNotContainsString($prohibited, $review);
        }
        self::assertFalse(\\App\\Presentation\\DemoCommercialDeliveryReview::isOperational());
        self::assertFalse(\\App\\Presentation\\DemoCommercialDeliveryReview::canResend());
        self::assertFalse(\\App\\Presentation\\DemoCommercialDeliveryReview::canConfirmCrmDelivery());

        foreach (['overview', 'services', 'available', 'contracts', 'modules', 'journey', 'requests'] as $section) {
            self::assertContains($section . '.html', $pages);
            self::assertFileExists($this->temporaryDirectory . '/' . $section . '.html');
            self::assertStringContainsString('href="' . $section . '.html"', $index);
            $html = file_get_contents($this->temporaryDirectory . '/' . $section . '.html');
            self::assertIsString($html);
            self::assertStringContainsString('href="index.html"', $html);
            self::assertStringContainsString('Navegação da prévia offline', $html);
            self::assertStringContainsString('Protótipo interno', $html);
            self::assertStringNotContainsString('<form', $html);
            self::assertStringNotContainsString('<script', $html);
            self::assertStringNotContainsString('http://', $html);
            self::assertStringNotContainsString('https://', $html);
        }
    }

    public function test_existing_output_is_never_overwritten(): void
    {
        $exporter = new DemoPortalStaticExporter();
        $exporter->export($this->temporaryDirectory);
        $sentinel = file_get_contents($this->temporaryDirectory . '/index.html');
        $this->expectException(LogicException::class);
        try {
            $exporter->export($this->temporaryDirectory);
        } finally {
            self::assertSame($sentinel, file_get_contents($this->temporaryDirectory . '/index.html'));
        }
    }

    public function test_export_does_not_register_routes_or_grant_real_access(): void
    {
        $before = [];
        foreach ($this->app['router']->getRoutes() as $route) {
            $before[] = [$route->uri(), $route->methods()];
        }

        (new DemoPortalStaticExporter())->export($this->temporaryDirectory);

        $after = [];
        foreach ($this->app['router']->getRoutes() as $route) {
            $after[] = [$route->uri(), $route->methods()];
        }
        self::assertSame($before, $after);
        self::assertFalse(DemoPortalStaticExporter::authorizesCustomerAccess());
        self::assertFalse(DemoPortalStaticExporter::enablesWebRoutes());
    }

    public function test_export_fails_closed_outside_local_or_testing(): void
    {
        $original = $this->app->environment();
        $this->app->detectEnvironment(fn () => 'production');
        try {
            $this->expectException(LogicException::class);
            (new DemoPortalStaticExporter())->export($this->temporaryDirectory);
        } finally {
            $this->app->detectEnvironment(fn () => $original);
            self::assertDirectoryDoesNotExist($this->temporaryDirectory);
        }
    }
}
