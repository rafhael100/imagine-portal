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

    public function test_six_step_guided_preview_never_enables_real_customer_actions(): void
    {
        $pages=(new PortablePortalStaticPreview())->export($this->destination);
        self::assertCount(11,$pages);

        $sequence=[
            'overview.html','available.html','requests.html',
            'preparation.html','journey.html','services.html',
        ];
        $index=(string)file_get_contents($this->destination.'/index.html');
        self::assertStringContainsString(
            '<a data-guide-start href="overview.html">Iniciar o roteiro de exemplo</a>',
            $index
        );
        self::assertStringContainsString('Teste guiado em seis etapas',$index);
        self::assertStringNotContainsString('data-guided-flow="portal"',$index);

        foreach ($sequence as $position=>$name) {
            $html=(string)file_get_contents($this->destination.'/'.$name);
            $dom=new DOMDocument('1.0','UTF-8');
            $prior=libxml_use_internal_errors(true);
            try {
                self::assertTrue($dom->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET));
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($prior);
            }
            $xp=new DOMXPath($dom);
            $nav=$xp->query('//nav[@data-guided-flow="portal"]');
            self::assertNotFalse($nav);
            self::assertCount(1,$nav);
            self::assertSame((string)($position+1),$nav->item(0)->getAttribute('data-step-number'));
            self::assertSame('Roteiro guiado de homologação fictícia',
                $nav->item(0)->getAttribute('aria-label'));
            $active=$xp->query('//nav[@data-guided-flow="portal"]//span[@aria-current="step"]');
            self::assertNotFalse($active);
            self::assertCount(1,$active);

            $prev=$xp->query('//nav[@data-guided-flow="portal"]//a[@data-guide-prev]');
            $next=$xp->query('//nav[@data-guided-flow="portal"]//a[@data-guide-next]');
            $end=$xp->query('//nav[@data-guided-flow="portal"]//a[@data-guide-end]');
            self::assertNotFalse($prev);
            self::assertNotFalse($next);
            self::assertNotFalse($end);
            self::assertCount($position===0 ? 0 : 1,$prev);
            self::assertCount($position===5 ? 0 : 1,$next);
            self::assertCount($position===5 ? 1 : 0,$end);
            if ($position > 0) self::assertSame($sequence[$position-1],
                $prev->item(0)->getAttribute('href'));
            if ($position < 5) self::assertSame($sequence[$position+1],
                $next->item(0)->getAttribute('href'));
            if ($position===5) self::assertSame('index.html',
                $end->item(0)->getAttribute('href'));
            self::assertStringContainsString('Navegação ilustrativa e offline.',$html);
            self::assertStringNotContainsString('<form',$html);
            self::assertStringNotContainsString('<script',$html);
            self::assertStringNotContainsString('https://',$html);
        }
        foreach (['contracts.html','modules.html','help.html','notices.html'] as $name) {
            $html=(string)file_get_contents($this->destination.'/'.$name);
            self::assertStringNotContainsString('data-guided-flow="portal"',$html);
        }
        self::assertFalse(PortablePortalStaticPreview::enablesWebRoutes());
        self::assertFalse(PortablePortalStaticPreview::authorizesCustomerAccess());
    }
}
