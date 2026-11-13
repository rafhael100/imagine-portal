<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Presentation\ClientSection;
use App\Presentation\PortablePortalStaticPreview;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\DatabaseManager;
use Tests\TestCase;

final class PortalOfflineSidebarNavigationTest extends TestCase
{
    private string $destination;

    protected function setUp(): void
    {
        parent::setUp();
        $this->destination = sys_get_temp_dir().'/portal-offline-menu-'.bin2hex(random_bytes(12));
        $db=$this->createMock(DatabaseManager::class);
        $db->expects(self::never())->method('connection');
        $this->app->instance('db',$db);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->destination) && !is_link($this->destination)) {
            foreach (scandir($this->destination) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    @unlink($this->destination.'/'.$name);
                }
            }
            @rmdir($this->destination);
        }
        parent::tearDown();
    }

    public function test_unmounted_blade_sidebar_stays_without_any_navigation_or_customer_routes(): void
    {
        $before=count($this->app['router']->getRoutes());
        foreach (ClientSection::cases() as $section) {
            $html=view('portal.demo.'.$section->value)->render();
            self::assertSame(8,substr_count($html,'data-preview-section="'));
            self::assertStringContainsString(
                'Navegação ilustrativa. As opções não abrem outras telas.',$html
            );
            self::assertStringNotContainsString('href="overview.html"',$html);
            self::assertStringNotContainsString('href="preparation.html"',$html);
            self::assertStringNotContainsString('<script',$html);
            self::assertStringNotContainsString('<form',$html);
        }
        self::assertSame($before,count($this->app['router']->getRoutes()));
        foreach (['/portal','/portal/overview','/portal/preparation','/login'] as $uri) {
            $this->get($uri)->assertNotFound();
        }
    }

    public function test_eight_sidebar_links_exist_only_inside_prebuilt_local_file_pages(): void
    {
        $pages=(new PortablePortalStaticPreview())->export($this->destination);
        self::assertCount(11,$pages);
        $names=array_map(static fn (ClientSection $item): string =>
            $item->value.'.html', ClientSection::cases()
        );
        self::assertCount(8,$names);
        foreach ($pages as $page) {
            $html=(string) file_get_contents($this->destination.'/'.$page);
            if ($page === 'index.html') {
                self::assertStringNotContainsString('data-preview-section=',$html);
                continue;
            }

            $dom=new DOMDocument('1.0','UTF-8');
            $prev=libxml_use_internal_errors(true);
            try {
                self::assertTrue($dom->loadHTML(
                    '<?xml encoding="UTF-8">'.$html,LIBXML_NONET
                ));
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($prev);
            }
            $query=new DOMXPath($dom);
            $anchors=$query->query('//aside[contains(@class,"pi-sidebar")]//a[@data-preview-section]');
            self::assertNotFalse($anchors);
            self::assertCount(8,$anchors);
            $found=[];
            $active=0;
            foreach ($anchors as $anchor) {
                $target=$anchor->getAttribute('href');
                self::assertContains($target,$names);
                self::assertSame(
                    $anchor->getAttribute('data-preview-section').'.html',$target
                );
                self::assertNotSame('',trim($anchor->textContent));
                self::assertArrayNotHasKey($target,$found);
                $found[$target]=true;
                if ($anchor->getAttribute('aria-current') === 'page') {
                    $active++;
                    self::assertSame($page,$target);
                }
            }
            self::assertSame(in_array($page,$names,true) ? 1 : 0,$active);
            self::assertStringContainsString(
                'Prévia offline: abra somente as páginas locais.',$html
            );
            self::assertStringNotContainsString(
                'As opções não abrem outras telas.',$html
            );
            self::assertStringNotContainsString('https://',$html);
            self::assertStringNotContainsString('<form',$html);
        }
        self::assertFalse(PortablePortalStaticPreview::enablesWebRoutes());
        self::assertFalse(PortablePortalStaticPreview::authorizesCustomerAccess());
    }
}
