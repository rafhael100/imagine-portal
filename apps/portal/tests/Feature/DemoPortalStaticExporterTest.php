<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Presentation\DemoPortalStaticExporter;
use App\Presentation\DemoPortalArtifactManifest;
use App\Presentation\DemoPortalNavigationAudit;
use App\Presentation\DemoPortalOfflineExtras;
use App\Presentation\DemoPortalReviewWalkthrough;
use App\Presentation\ClientSection;
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
            if (is_file($this->temporaryDirectory.'/manifest.json')) {
                unlink($this->temporaryDirectory.'/manifest.json');
            }
            rmdir($this->temporaryDirectory);
        }
        parent::tearDown();
    }

    public function test_reviewed_commercial_to_client_journey_is_only_offline_links(): void
    {
        (new DemoPortalStaticExporter())->export($this->temporaryDirectory);
        $index=(string)file_get_contents($this->temporaryDirectory.'/index.html');
        self::assertStringContainsString('Jornada comercial → Portal', $index);
        self::assertStringContainsString('Não há compras, pagamentos, login', $index);

        $dom=new \DOMDocument('1.0','UTF-8');
        $previous=libxml_use_internal_errors(true);
        try {
            self::assertTrue($dom->loadHTML($index,LIBXML_NONET));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath=new \DOMXPath($dom);
        $nodes=$xpath->query('//section[@aria-labelledby="pi-commercial-portal-review"]/ol/li/a');
        self::assertNotFalse($nodes);
        self::assertCount(4, \App\Presentation\DemoCommercialPortalJourney::steps());
        self::assertSame(4,$nodes->length);
        foreach(\App\Presentation\DemoCommercialPortalJourney::steps() as $i=>$step) {
            $link=$nodes->item($i);
            self::assertInstanceOf(\DOMElement::class,$link);
            self::assertSame($step['href'],$link->getAttribute('href'));
            self::assertSame($step['title'],trim($link->textContent));
            self::assertStringContainsString($step['description'],$index);
            self::assertFileExists($this->temporaryDirectory.'/'.$step['href']);
            self::assertStringNotContainsString('http://',$step['href']);
            self::assertStringNotContainsString('https://',$step['href']);
        }
        self::assertFalse(\App\Presentation\DemoCommercialPortalJourney::authorizesCommercialCheckout());
        self::assertFalse(\App\Presentation\DemoCommercialPortalJourney::authorizesPortalLogin());
        self::assertFalse(\App\Presentation\DemoCommercialPortalJourney::authorizesAccountPurchase());
        self::assertFalse(\App\Presentation\DemoCommercialPortalJourney::transfersCustomerData());
        self::assertCount(11,\App\Presentation\DemoPortalArtifactManifest::pageNames());
        self::assertTrue((new DemoPortalNavigationAudit())->verify($this->temporaryDirectory));
        foreach(['<form','<input','<button','<script','<iframe','https://','http://'] as $forbidden) {
            self::assertStringNotContainsString($forbidden,$index);
        }
    }

    public function test_offline_export_restores_original_imagine_logo_and_orange_theme(): void
    {
        $original=dirname(base_path(),2).'/assets/logo-imagine.png';
        self::assertFileExists($original);
        $bytes=file_get_contents($original);
        self::assertIsString($bytes);
        self::assertSame("\x89PNG\r\n\x1a\n",substr($bytes,0,8));

        (new DemoPortalStaticExporter())->export($this->temporaryDirectory);
        $expected="url('data:image/png;base64,".base64_encode($bytes)."')";

        foreach(['overview','services','available','contracts','modules',
            'journey','requests','delivery-review','help','notices']as$slug){
            $html=file_get_contents($this->temporaryDirectory.'/'.$slug.'.html');
            self::assertIsString($html);
            self::assertStringContainsString($expected,$html);
            self::assertStringContainsString('--pi-accent:#f9941d',$html);
            self::assertStringContainsString(
                DemoPortalNavigationAudit::OFFLINE_CSP,$html
            );
            self::assertStringNotContainsString(
                "url('/_development/branding/logo-imagine.png')",$html
            );
            self::assertStringNotContainsString("img-src 'self' data:",$html);
        }
        self::assertTrue(
            (new DemoPortalNavigationAudit())->verify($this->temporaryDirectory)
        );
    }

    public function test_export_creates_navigable_index_and_read_only_review_preview(): void
    {
        $pages = (new DemoPortalStaticExporter())->export($this->temporaryDirectory);
        self::assertCount(11, $pages);
        self::assertContains('index.html', $pages);
        self::assertContains('requests.html', $pages);
        self::assertContains('delivery-review.html', $pages);
        self::assertContains('help.html', $pages);
        self::assertContains('notices.html', $pages);

        $index = file_get_contents($this->temporaryDirectory . '/index.html');
        self::assertIsString($index);
        self::assertStringContainsString('Somente dados fictícios', $index);
        self::assertStringContainsString('href="index.html" aria-current="page"', $index);

        $review = file_get_contents($this->temporaryDirectory . '/delivery-review.html');
        self::assertIsString($review);
        self::assertStringContainsString('href="index.html"', $review);
        self::assertStringContainsString('DEMO-AURORA-001', $review);
        self::assertStringContainsString('Revisão de entregas', $review);
        self::assertStringContainsString('SIMULAÇÃO LOCAL', $review);
        self::assertStringContainsString(
            'href="delivery-review.html" aria-current="page"', $review,
        );
        foreach (['<form', '<script', '<button', 'http://', 'https://'] as $prohibited) {
            self::assertStringNotContainsString($prohibited, $review);
        }
        self::assertFalse(\App\Presentation\DemoCommercialDeliveryReview::isOperational());
        self::assertFalse(\App\Presentation\DemoCommercialDeliveryReview::canResend());
        self::assertFalse(\App\Presentation\DemoCommercialDeliveryReview::canConfirmCrmDelivery());
        self::assertFalse(DemoPortalOfflineExtras::authorizesAccess());
        self::assertNull(ClientSection::tryFrom('help'));
        self::assertNull(ClientSection::tryFrom('notices'));

        foreach ([
            'help' => ['Central de Ajuda', 'Nenhum atendimento', 'Onepage'],
            'notices' => ['Central de Avisos', 'Nenhum aviso foi enviado', 'Landing Page'],
        ] as $slug => $expected) {
            $html = file_get_contents($this->temporaryDirectory.'/'.$slug.'.html');
            self::assertIsString($html);
            self::assertStringContainsString('href="index.html"', $html);
            self::assertStringContainsString(
                'href="'.$slug.'.html" aria-current="page"', $html,
            );
            self::assertStringContainsString('Navegação da prévia offline', $html);
            self::assertStringContainsString($expected[0], $html);
            self::assertStringContainsString($expected[1], $html);
            self::assertStringContainsString($expected[2], $html);
            foreach (['<form', '<input', '<button', '<script', '<iframe', 'http://', 'https://'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $html);
            }
            self::assertStringContainsString('href="'.$slug.'.html"', $index);
        }

        foreach (['overview', 'services', 'available', 'contracts', 'modules', 'journey', 'requests'] as $section) {
            self::assertContains($section . '.html', $pages);
            self::assertFileExists($this->temporaryDirectory . '/' . $section . '.html');
            self::assertStringContainsString('href="' . $section . '.html"', $index);
            $html = file_get_contents($this->temporaryDirectory . '/' . $section . '.html');
            self::assertIsString($html);
            self::assertStringContainsString('href="index.html"', $html);
            self::assertStringContainsString(
                'href="'.$section.'.html" aria-current="page"', $html,
            );
            self::assertStringContainsString('Navegação da prévia offline', $html);
            self::assertStringContainsString('Protótipo interno', $html);
            self::assertStringNotContainsString('<form', $html);
            self::assertStringNotContainsString('<script', $html);
            self::assertStringNotContainsString('http://', $html);
            self::assertStringNotContainsString('https://', $html);
        }
    }

    public function test_index_guides_reviewer_through_each_offline_screen_without_client_actions(): void
    {
        (new DemoPortalStaticExporter())->export($this->temporaryDirectory);
        $html = (string) file_get_contents($this->temporaryDirectory.'/index.html');
        self::assertStringContainsString('Roteiro de avaliação interna', $html);
        self::assertStringContainsString('Não registram tarefas nem ações de clientes', $html);

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            self::assertTrue($document->loadHTML($html));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new \DOMXPath($document);
        $items = $xpath->query('//section[@aria-labelledby="pi-review-route"]/ol/li');
        self::assertNotFalse($items);
        self::assertCount(10, DemoPortalReviewWalkthrough::steps());
        self::assertSame(10, $items->length);

        $seen = [];
        foreach (DemoPortalReviewWalkthrough::steps() as $position => $step) {
            $link = $xpath->query('./a', $items->item($position))?->item(0);
            self::assertInstanceOf(\DOMElement::class, $link);
            self::assertSame($step['href'], $link->getAttribute('href'));
            self::assertSame($step['title'], trim($link->textContent));
            self::assertStringContainsString($step['description'], $html);
            self::assertFileExists($this->temporaryDirectory.'/'.$step['href']);
            self::assertArrayNotHasKey($step['href'], $seen);
            $seen[$step['href']] = true;
        }
        $expected = array_diff(DemoPortalArtifactManifest::pageNames(), ['index.html']);
        sort($expected);
        $actual = array_keys($seen);
        sort($actual);
        self::assertSame($expected, $actual);
        self::assertFalse(DemoPortalReviewWalkthrough::authorizesAction());
        self::assertStringNotContainsString('<form', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertTrue((new DemoPortalNavigationAudit())->verify($this->temporaryDirectory));
    }

    public function test_cli_reports_actual_manifest_page_count(): void
    {
        $originalStorage = $this->app->storagePath();
        $testStorage = $this->temporaryDirectory.'-storage';
        self::assertTrue(mkdir($testStorage.'/app', 0700, true));
        $this->app->useStoragePath($testStorage);
        $destination = storage_path('app/portal-demo-preview');

        try {
            (new DemoPortalStaticExporter())->export($destination);
            $count = count(DemoPortalArtifactManifest::pageNames());
            self::assertSame(11, $count);
            // Use the real console kernel; InteractsWithConsole::artisan()
            // depends on optional Mockery, which is absent from the pinned
            // production-only Composer lockfile used by isolated CI.
            $output = new \Symfony\Component\Console\Output\BufferedOutput();
            $exitCode = \Illuminate\Support\Facades\Artisan::call('portal:demo-verify', [], $output);
            self::assertSame(0, $exitCode);
            self::assertStringContainsString(
                'Verified '.$count.' synthetic HTML preview pages and local navigation. No publication or customer access.',
                $output->fetch(),
            );
        } finally {
            $this->app->useStoragePath($originalStorage);
            foreach (glob($destination.'/*') ?: [] as $path) {
                if (is_file($path) && !is_link($path)) {
                    unlink($path);
                }
            }
            if (is_dir($destination)) {
                rmdir($destination);
            }
            rmdir($testStorage.'/app');
            rmdir($testStorage);
        }
    }

    public function test_exact_eleven_page_bundle_contains_verifiable_local_manifest(): void
    {
        $pages = (new DemoPortalStaticExporter())->export($this->temporaryDirectory);
        self::assertSame(DemoPortalArtifactManifest::pageNames(), $pages);
        self::assertFileExists($this->temporaryDirectory.'/manifest.json');
        self::assertTrue((new DemoPortalArtifactManifest())->verify($this->temporaryDirectory));
        self::assertTrue((new DemoPortalNavigationAudit())->verify($this->temporaryDirectory));
        $json = json_decode(
            (string) file_get_contents($this->temporaryDirectory.'/manifest.json'),
            true,
            12,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame('imagine.portal.synthetic-preview.manifest.v1', $json['schema']);
        self::assertSame(true, $json['synthetic_only']);
        self::assertSame(false, $json['authorizes_customer_access']);
        self::assertSame(false, $json['authorizes_deployment']);
        self::assertCount(11, $json['pages']);
        self::assertSame(0600,
            fileperms($this->temporaryDirectory.'/manifest.json') & 0777);
    }

    public function test_all_exported_tables_have_keyboard_focusable_named_scroll_regions(): void
    {
        $pages = (new DemoPortalStaticExporter())->export($this->temporaryDirectory);
        $tableCount = 0;
        foreach ($pages as $page) {
            $html = (string) file_get_contents($this->temporaryDirectory.'/'.$page);
            $document = new \DOMDocument('1.0', 'UTF-8');
            $previous = libxml_use_internal_errors(true);
            try {
                self::assertTrue($document->loadHTML($html, LIBXML_NONET));
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            $xpath = new \DOMXPath($document);
            foreach ($document->getElementsByTagName('table') as $table) {
                ++$tableCount;
                $region = $table->parentNode;
                self::assertInstanceOf(\DOMElement::class, $region);
                self::assertContains('pi-table-wrap',
                    preg_split('/\s+/', trim($region->getAttribute('class'))));
                self::assertSame('region', $region->getAttribute('role'));
                self::assertSame('0', $region->getAttribute('tabindex'));
                $caption = $xpath->query('./caption', $table)?->item(0);
                self::assertInstanceOf(\DOMElement::class, $caption);
                self::assertSame(trim($caption->textContent), $region->getAttribute('aria-label'));
            }
        }
        self::assertGreaterThanOrEqual(4, $tableCount);
    }

    public function test_unfocusable_table_scroll_region_fails_offline_navigation_audit(): void
    {
        (new DemoPortalStaticExporter())->export($this->temporaryDirectory);
        $path = $this->temporaryDirectory.'/requests.html';
        $html = (string) file_get_contents($path);
        $mutated = preg_replace(
            '/(<div class="pi-table-wrap" role="region" aria-label="[^"]+") tabindex="0">/',
            '$1>',
            $html,
            1,
        );
        self::assertIsString($mutated);
        self::assertNotSame($html, $mutated);
        file_put_contents($path, $mutated);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('keyboard-focusable scroll region');
        (new DemoPortalNavigationAudit())->verify($this->temporaryDirectory);
    }

    public function test_manipulated_html_is_rejected_even_if_the_manifest_exists(): void
    {
        (new DemoPortalStaticExporter())->export($this->temporaryDirectory);
        file_put_contents($this->temporaryDirectory.'/index.html', '\nInjected fake status', FILE_APPEND);
        $this->expectException(LogicException::class);
        (new DemoPortalArtifactManifest())->verify($this->temporaryDirectory);
    }

    public function test_extra_unapproved_file_is_rejected(): void
    {
        (new DemoPortalStaticExporter())->export($this->temporaryDirectory);
        file_put_contents($this->temporaryDirectory.'/rogue.txt', 'not allowed');
        try {
            $this->expectException(LogicException::class);
            (new DemoPortalArtifactManifest())->verify($this->temporaryDirectory);
        } finally {
            unlink($this->temporaryDirectory.'/rogue.txt');
        }
    }

    public function test_missing_or_symlinked_html_fails_closed(): void
    {
        (new DemoPortalStaticExporter())->export($this->temporaryDirectory);
        unlink($this->temporaryDirectory.'/delivery-review.html');
        symlink($this->temporaryDirectory.'/index.html',
            $this->temporaryDirectory.'/delivery-review.html');
        try {
            $this->expectException(LogicException::class);
            (new DemoPortalArtifactManifest())->verify($this->temporaryDirectory);
        } finally {
            unlink($this->temporaryDirectory.'/delivery-review.html');
        }
    }

    public function test_injected_external_or_parent_path_link_fails_navigation_audit(): void
    {
        (new DemoPortalStaticExporter())->export($this->temporaryDirectory);
        $page = $this->temporaryDirectory.'/overview.html';
        file_put_contents($page, '<a href="../private.html">Invalid navigation</a>', FILE_APPEND);
        $this->expectException(LogicException::class);
        (new DemoPortalNavigationAudit())->verify($this->temporaryDirectory);
    }

    public function test_page_without_skip_to_main_link_fails_navigation_audit(): void
    {
        (new DemoPortalStaticExporter())->export($this->temporaryDirectory);
        $page = $this->temporaryDirectory.'/requests.html';
        $html = (string) file_get_contents($page);
        $without = str_replace(
            '<a class="pi-skip" href="#pi-content">Pular para o conteúdo</a>',
            '',
            $html,
        );
        self::assertNotSame($html, $without);
        file_put_contents($page, $without);
        $this->expectException(LogicException::class);
        (new DemoPortalNavigationAudit())->verify($this->temporaryDirectory);
    }

    public function test_missing_robots_header_fails_navigation_audit(): void
    {
        (new DemoPortalStaticExporter())->export($this->temporaryDirectory);
        $file = $this->temporaryDirectory.'/index.html';
        $html = (string) file_get_contents($file);
        $without = str_replace(
            '<meta name="robots" content="noindex,nofollow">',
            '',
            $html,
        );
        self::assertNotSame($html, $without);
        file_put_contents($file, $without);
        $this->expectException(LogicException::class);
        (new DemoPortalNavigationAudit())->verify($this->temporaryDirectory);
    }

    public function test_offline_navigation_audit_rejects_removed_content_security_policy(): void
    {
        (new DemoPortalStaticExporter())->export($this->temporaryDirectory);
        $file = $this->temporaryDirectory.'/index.html';
        $html = (string) file_get_contents($file);
        $tampered = str_replace(
            'http-equiv="Content-Security-Policy"',
            'http-equiv="X-Unprotected-Preview"',
            $html,
        );
        self::assertNotSame($html, $tampered);
        file_put_contents($file, $tampered);
        $this->expectException(LogicException::class);
        (new DemoPortalNavigationAudit())->verify($this->temporaryDirectory);
    }

    public function test_offline_navigation_audit_rejects_inline_event_handlers(): void
    {
        (new DemoPortalStaticExporter())->export($this->temporaryDirectory);
        $file = $this->temporaryDirectory.'/delivery-review.html';
        $html = (string) file_get_contents($file);
        $tampered = str_replace(
            '<main id="pi-content"',
            '<main onclick="alert(1)" id="pi-content"',
            $html,
        );
        self::assertNotSame($html, $tampered);
        file_put_contents($file, $tampered);
        $this->expectException(LogicException::class);
        (new DemoPortalNavigationAudit())->verify($this->temporaryDirectory);
    }

    public function test_offline_navigation_audit_rejects_embedded_remote_iframe(): void
    {
        (new DemoPortalStaticExporter())->export($this->temporaryDirectory);
        $file = $this->temporaryDirectory.'/index.html';
        $html = (string) file_get_contents($file);
        $tampered = str_replace(
            '</main>',
            '<iframe src="https://example.invalid"></iframe></main>',
            $html,
        );
        self::assertNotSame($html, $tampered);
        file_put_contents($file, $tampered);
        $this->expectException(LogicException::class);
        (new DemoPortalNavigationAudit())->verify($this->temporaryDirectory);
    }

    public function test_wrong_active_navigation_destination_is_rejected(): void
    {
        (new DemoPortalStaticExporter())->export($this->temporaryDirectory);
        $file = $this->temporaryDirectory.'/services.html';
        $html = (string) file_get_contents($file);
        $tampered = str_replace(
            'href="services.html" aria-current="page"',
            'href="overview.html" aria-current="page"',
            $html,
        );
        self::assertNotSame($html, $tampered);
        file_put_contents($file, $tampered);
        $this->expectException(LogicException::class);
        (new DemoPortalNavigationAudit())->verify($this->temporaryDirectory);
    }

    public function test_missing_active_navigation_announcement_is_rejected(): void
    {
        (new DemoPortalStaticExporter())->export($this->temporaryDirectory);
        $file = $this->temporaryDirectory.'/index.html';
        $html = (string) file_get_contents($file);
        $tampered = str_replace(' aria-current="page"', '', $html);
        self::assertNotSame($html, $tampered);
        file_put_contents($file, $tampered);
        $this->expectException(LogicException::class);
        (new DemoPortalNavigationAudit())->verify($this->temporaryDirectory);
    }

    public function test_replaced_claim_is_left_untouched_by_original_exporter(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Renaming an open claim marker requires Unix filesystem semantics.');
        }
        $claim = $this->temporaryDirectory.'.exporting';
        $moved = $claim.'.original';
        $this->app['view']->composer('portal.demo.delivery-review',
            static function () use ($claim, $moved): void {
                self::assertFileExists($claim);
                self::assertTrue(rename($claim, $moved));
                self::assertSame(strlen('new owner'), file_put_contents($claim, 'new owner'));
            });

        try {
            (new DemoPortalStaticExporter())->export($this->temporaryDirectory);
            self::assertSame('new owner', file_get_contents($claim),
                'a predecessor must not remove or modify the replacement claim');
            self::assertFileExists($moved);
        } finally {
            if (is_file($claim) && !is_link($claim)) {
                unlink($claim);
            }
            if (is_file($moved) && !is_link($moved)) {
                unlink($moved);
            }
        }
    }

    public function test_stale_or_competing_export_claim_prevents_new_output(): void
    {
        $claim = $this->temporaryDirectory . '.exporting';
        self::assertSame(0, file_put_contents($claim, ''));
        try {
            $this->expectException(LogicException::class);
            (new DemoPortalStaticExporter())->export($this->temporaryDirectory);
        } finally {
            self::assertDirectoryDoesNotExist($this->temporaryDirectory);
            self::assertFileExists($claim);
            unlink($claim);
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

    public function test_failed_review_render_never_leaves_partial_html_or_target(): void
    {
        // Force failure late, after the exporter has staged the seven client
        // previews but before it can rename the complete eleven-page bundle.
        $this->app['view']->composer('portal.demo.delivery-review',
            static function (): void {
                throw new \RuntimeException('Intentional F134 fixture failure');
            });

        try {
            (new DemoPortalStaticExporter())->export($this->temporaryDirectory);
            self::fail('Invalid preview rendering must abort the export');
        } catch (\RuntimeException $e) {
            self::assertSame('Intentional F134 fixture failure', $e->getMessage());
        }
        self::assertDirectoryDoesNotExist($this->temporaryDirectory);
        self::assertSame([], glob($this->temporaryDirectory . '.staging-*') ?: []);
        self::assertFileDoesNotExist($this->temporaryDirectory . '.exporting');
    }

    public function test_completed_demo_export_has_private_directory_and_file_permissions(): void
    {
        $files = (new DemoPortalStaticExporter())->export($this->temporaryDirectory);
        self::assertCount(11, $files);
        self::assertSame(0700, fileperms($this->temporaryDirectory) & 0777);
        foreach ($files as $name) {
            self::assertSame(0600,
                fileperms($this->temporaryDirectory . '/' . $name) & 0777);
        }
        self::assertSame([], glob($this->temporaryDirectory . '.staging-*') ?: []);
        self::assertFileDoesNotExist($this->temporaryDirectory . '.exporting');
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
