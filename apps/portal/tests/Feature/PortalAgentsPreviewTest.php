<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\DatabaseManager;
use Tests\TestCase;

/**
 * Portal AIA P0: static server-side preview, never a published service.
 */
final class PortalAgentsPreviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db=$this->createMock(DatabaseManager::class);
        $db->expects($this->never())->method('connection');
        $this->app->instance('db',$db);
    }

    public function test_unmounted_agents_preview_preserves_imagine_pillars_and_denial(): void
    {
        $html=view('portal.demo.agents')->render();
        foreach ([
            'Imagine Agentes de IA',
            'Assistente Comercial',
            'Visitas', 'Leads', 'Clientes',
            '1.000', '50', '5',
            'sem provedor de IA', 'Integração indisponível',
            'não compartilha sessões com o CRM',
            '13 cenários OP-01', 'Nenhuma rota',
            'sem publicação para clientes'
        ] as $required) {
            self::assertStringContainsString($required,$html);
        }
        foreach (['<script','<form','<iframe','<img','action=','https://',
                  'http://','fetch(','localStorage','href="http'] as $forbidden) {
            self::assertStringNotContainsString($forbidden,$html);
        }
        preg_match_all('/href="([^"]*)"/',$html,$links);
        self::assertSame(['#pi-content'],$links[1]);
    }

    public function test_existing_modules_show_unavailable_agents_without_real_session(): void
    {
        foreach (['modules','available'] as $page) {
            $html=view('portal.demo.'.$page)->render();
            self::assertStringContainsString('Imagine Agentes de IA',$html);
            self::assertStringContainsString('Acesso', $html); // informational only
            self::assertStringNotContainsString('<script',$html);
            self::assertStringNotContainsString('https://',$html);
        }
        self::assertFalse($this->app['session']->isStarted());
    }

    public function test_preview_does_not_create_any_route(): void
    {
        $before=[];
        foreach ($this->app['router']->getRoutes() as $route) {
            $before[] = [$route->methods(),$route->uri(),$route->getActionName()];
        }
        view('portal.demo.agents')->render();
        $after=[];
        foreach ($this->app['router']->getRoutes() as $route) {
            $after[] = [$route->methods(),$route->uri(),$route->getActionName()];
        }
        self::assertSame($before,$after);
    }
}
