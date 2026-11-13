<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Presentation\DemoServicePreparation;
use App\Presentation\DemoServiceRequests;
use App\Presentation\ServiceFollowUp;
use App\Presentation\RequestSummary;
use Illuminate\Database\DatabaseManager;
use Tests\TestCase;

final class PortalServicePreparationWaveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $database = $this->createMock(DatabaseManager::class);
        $database->expects(self::never())->method('connection');
        $this->app->instance('db', $database);
    }

    public function test_services_page_has_all_three_preparation_checklists_with_imagine_brand(): void
    {
        $html = view('portal.demo.services')->render();
        foreach ([
            'Preparação dos serviços', 'Checklist de preparação por serviço',
            'Sites Imagine · Onepage', 'Sites Imagine · Landing Page',
            'Performance AI', 'Google Ads e Meta', 'Consentimento',
            'DEMO-REQ-ONEPAGE', 'DEMO-REQ-LANDING', 'DEMO-REQ-PERFORMANCE',
        ] as $expected) {
            self::assertStringContainsString($expected, $html);
        }
        self::assertSame(3, count(DemoServicePreparation::rows()));
        self::assertSame(1, substr_count($html, 'src="/assets/logo-imagine.png"'));
        self::assertStringContainsString('--pi-orange:#f9941d', $html);
        self::assertStringContainsString('role="region"', $html);
        self::assertStringContainsString('tabindex="0"', $html);
        self::assertStringContainsString('noindex,nofollow', $html);
        foreach (['<form', '<input', '<script', '<iframe', 'https://', 'http://'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html);
        }
        self::assertFalse(DemoServicePreparation::authorizesPublication());
        self::assertFalse(DemoServicePreparation::authorizesAds());
        self::assertFalse(DemoServicePreparation::authorizesCustomerAccess());
        $this->get('/portal/services')->assertNotFound();
    }

    public function test_missing_request_refuses_all_rows(): void
    {
        $requests = DemoServiceRequests::requests();
        array_pop($requests);
        self::assertSame([], DemoServicePreparation::fromSources(
            DemoServiceRequests::services(), $requests,
        ));
    }

    public function test_duplicate_service_refuses_all_rows(): void
    {
        $services = DemoServiceRequests::services();
        $services[1] = $services[0];
        self::assertSame([], DemoServicePreparation::fromSources(
            $services, DemoServiceRequests::requests(),
        ));
    }

    public function test_foreign_request_link_refuses_all_rows(): void
    {
        $requests = DemoServiceRequests::requests();
        $old = $requests[1];
        $requests[1] = new RequestSummary(
            'DEMO-REQ-LANDING', 'DEMO-SVC-ONEPAGE', $old->title, $old->summary,
            $old->state, $old->updates,
        );
        self::assertSame([], DemoServicePreparation::fromSources(
            DemoServiceRequests::services(), $requests,
        ));
    }

    public function test_wrong_product_refuses_all_rows(): void
    {
        $services = DemoServiceRequests::services();
        $old = $services[0];
        $services[0] = new ServiceFollowUp(
            $old->reference, 'Serviço estranho', $old->state,
            $old->summary, $old->nextUpdate,
        );
        self::assertSame([], DemoServicePreparation::fromSources(
            $services, DemoServiceRequests::requests(),
        ));
    }

    public function test_non_list_and_unexpected_types_refuse_all_rows(): void
    {
        self::assertSame([], DemoServicePreparation::fromSources(
            [1 => DemoServiceRequests::services()[0]], DemoServiceRequests::requests(),
        ));
        $requests = DemoServiceRequests::requests();
        $requests[2] = 'not a request';
        self::assertSame([], DemoServicePreparation::fromSources(
            DemoServiceRequests::services(), $requests,
        ));
    }
}
