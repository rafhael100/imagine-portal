<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\DatabaseManager;
use Tests\TestCase;

final class PortalServicePreparationViewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $mock=$this->createMock(DatabaseManager::class);
        $mock->expects(self::never())->method('connection');
        $this->app->instance('db',$mock);
    }

    public function test_new_preparation_screen_uses_original_imagine_brand_and_detailed_checklist(): void
    {
        $html=view('portal.demo.preparation')->render();
        foreach([
            'Preparação dos serviços',
            'O que falta preparar em cada serviço',
            'Preparação por serviço',
            'Sites Imagine · Onepage',
            'Sites Imagine · Landing Page',
            'Performance AI',
            'Google Ads e Meta',
            'CRM Imagine possui login e sessão próprios',
            'Universidade Imagine é benefício',
            'Sem identidade Portal ativa e MFA validado',
        ] as $copy){
            self::assertStringContainsString($copy,$html,$copy);
        }
        self::assertStringContainsString('--pi-orange:#f9941d',$html);
        self::assertStringContainsString('src="/assets/logo-imagine.png"',$html);
        self::assertStringContainsString('aria-current="page"',$html);
        self::assertStringContainsString('noindex,nofollow',$html);
        self::assertStringNotContainsString('<form',$html);
        self::assertStringNotContainsString('<script',$html);
        self::assertStringNotContainsString('https://',$html);
    }

    public function test_new_screen_has_keyboard_table_and_no_resource_routes(): void
    {
        $html=view('portal.demo.preparation')->render();
        self::assertStringContainsString('role="region"',$html);
        self::assertStringContainsString('tabindex="0"',$html);
        self::assertStringContainsString('scope="col"',$html);
        self::assertStringContainsString('<caption>Preparação por serviço',$html);
        foreach(['/portal/preparation','/portal/sites/publish','/portal/performance/activate'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }
}
