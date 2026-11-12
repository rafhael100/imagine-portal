<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Presentation\PortablePortalStaticPreview;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Tests\TestCase;

final class PortalPortableStaticPreviewTest extends TestCase
{
    private string $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->target = sys_get_temp_dir().'/imagine-offline-'.bin2hex(random_bytes(10));
        $db = $this->createMock(DatabaseManager::class);
        $db->expects(self::never())->method('connection');
        $this->app->instance('db', $db);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->target) && !is_link($this->target)) {
            foreach (scandir($this->target) ?: [] as $file) {
                if ($file !== '.' && $file !== '..') {
                    @unlink($this->target.'/'.$file);
                }
            }
            @rmdir($this->target);
        }
        parent::tearDown();
    }

    public function test_offline_bundle_contains_real_logo_and_ten_navigable_pages(): void
    {
        $exporter = new PortablePortalStaticPreview();
        $pages = $exporter->export($this->target);
        self::assertSame([
            'overview.html', 'services.html', 'available.html', 'contracts.html',
            'modules.html', 'journey.html', 'requests.html',
            'help.html', 'notices.html', 'index.html',
        ], $pages);
        $manifest = json_decode((string) file_get_contents($this->target.'/manifest.json'), true, 15, JSON_THROW_ON_ERROR);
        self::assertSame('imagine-portal-visual-only-preview-v1', $manifest['schema']);
        self::assertTrue($manifest['synthetic_only']);
        self::assertFalse($manifest['authorizes_customer_access']);
        self::assertFalse($manifest['authorizes_payment']);
        self::assertFalse($manifest['authorizes_deployment']);
        self::assertCount(10, $manifest['pages']);
        self::assertSame('678adeceb3a8ecdaeb6b576b9ee18d1bf0396f77',
            $manifest['logo_git_blob_sha1']);

        foreach ($pages as $i => $page) {
            $file = $this->target.'/'.$page;
            self::assertFileExists($file);
            $html = (string) file_get_contents($file);
            self::assertSame($page, $manifest['pages'][$i]['name']);
            self::assertSame(strlen($html), $manifest['pages'][$i]['bytes']);
            self::assertSame(hash('sha256', $html), $manifest['pages'][$i]['sha256']);
            self::assertStringContainsString('Navegação exclusivamente offline', $html);
            self::assertStringContainsString('noindex,nofollow', $html);
            self::assertStringNotContainsString('<script', $html);
            self::assertStringNotContainsString('<form', $html);
            self::assertStringNotContainsString('<iframe', $html);
            self::assertStringNotContainsString('http://', $html);
            self::assertStringNotContainsString('https://', $html);
            $dom = new \DOMDocument('1.0', 'UTF-8');
            $previous = libxml_use_internal_errors(true);
            try {
                self::assertTrue($dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET));
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            self::assertSame(1, $dom->getElementsByTagName('main')->length);
            self::assertSame(1, $dom->getElementsByTagName('h1')->length);
            foreach ($dom->getElementsByTagName('table') as $table) {
                $region = $table->parentNode;
                self::assertInstanceOf(\DOMElement::class, $region);
                self::assertSame('region', $region->getAttribute('role'));
                self::assertSame('0', $region->getAttribute('tabindex'));
                $caption = $table->getElementsByTagName('caption')->item(0);
                self::assertInstanceOf(\DOMElement::class, $caption);
                self::assertSame(trim($caption->textContent), $region->getAttribute('aria-label'));
                foreach ($table->getElementsByTagName('th') as $th) {
                    self::assertSame('col', $th->getAttribute('scope'));
                }
            }
            preg_match_all('/href="([^"]+)"/', $html, $hrefs);
            foreach ($hrefs[1] as $href) {
                self::assertContains($href, [...$pages, '#pi-content']);
            }

            if ($page === 'index.html') {
                self::assertSame(0, substr_count($html, '<img '));
                self::assertStringContainsString('Imagine Portal · prévia visual', $html);
                continue;
            }
            self::assertSame(1, substr_count($html, '<img '));
            self::assertSame(1, substr_count($html, 'data:image/png;base64,'));
            self::assertStringNotContainsString('src="/assets/logo-imagine.png"', $html);
            self::assertStringContainsString('--pi-orange:#f9941d', $html);
            self::assertStringContainsString('aria-current="page"', $html);
        }

        $help = (string) file_get_contents($this->target.'/help.html');
        $notices = (string) file_get_contents($this->target.'/notices.html');
        self::assertStringContainsString('Central de Ajuda', $help);
        self::assertStringContainsString('Central de Avisos', $notices);
        self::assertStringContainsString('Nenhum aviso foi enviado', $notices);
        self::assertStringContainsString('href="help.html"', (string) file_get_contents($this->target.'/index.html'));
        self::assertStringContainsString('href="notices.html"', (string) file_get_contents($this->target.'/index.html'));

        $overview = (string) file_get_contents($this->target.'/overview.html');
        self::assertMatchesRegularExpression('/src="data:image\/png;base64,([A-Za-z0-9+\/=]+)"/', $overview);
        preg_match('/src="data:image\/png;base64,([A-Za-z0-9+\/=]+)"/', $overview, $matches);
        $bytes = base64_decode($matches[1], true);
        self::assertIsString($bytes);
        self::assertSame(5594, strlen($bytes));
        self::assertSame(
            '678adeceb3a8ecdaeb6b576b9ee18d1bf0396f77',
            sha1('blob '.strlen($bytes)."\0".$bytes)
        );
        self::assertFalse(PortablePortalStaticPreview::enablesWebRoutes());
        self::assertFalse(PortablePortalStaticPreview::authorizesCustomerAccess());
    }

    public function test_existing_directory_or_file_is_never_overwritten(): void
    {
        mkdir($this->target, 0700);
        file_put_contents($this->target.'/keep.txt', 'SYNTHETIC_KEEP');
        try {
            (new PortablePortalStaticPreview())->export($this->target);
            self::fail('Exporter overwrote an existing target.');
        } catch (LogicException) {
            self::assertSame('SYNTHETIC_KEEP', file_get_contents($this->target.'/keep.txt'));
        }
    }

    public function test_unnamed_or_unfocusable_offline_table_fails_closed(): void
    {
        $html = '<!DOCTYPE html><html lang="pt-BR"><head><title>EXEMPLO</title></head>'
            .'<body><main><h1>EXEMPLO</h1><div class="pi-table-wrap">'
            .'<table><caption>Não focável</caption><thead><tr><th scope="col">Coluna</th>'
            .'</tr></thead></table></div></main></body></html>';
        $audit = new \ReflectionMethod(PortablePortalStaticPreview::class, 'assertOfflineOnly');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('named keyboard-focusable region');
        $audit->invoke(new PortablePortalStaticPreview(), $html, ['index.html'], null);
    }

    public function test_exporter_does_not_register_http_or_customer_access_routes(): void
    {
        $before = array_map(
            static fn ($route) => [$route->methods(), $route->uri()],
            iterator_to_array($this->app['router']->getRoutes())
        );
        (new PortablePortalStaticPreview())->export($this->target);
        $after = array_map(
            static fn ($route) => [$route->methods(), $route->uri()],
            iterator_to_array($this->app['router']->getRoutes())
        );
        self::assertSame($before, $after);
        foreach (['/portal', '/portal/overview', '/portal/sites/publish', '/login', '/mfa'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }
}
