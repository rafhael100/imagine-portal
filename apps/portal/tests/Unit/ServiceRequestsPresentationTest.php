<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Presentation\DemoServiceRequests;
use App\Presentation\IllustrativeUpdate;
use App\Presentation\JourneyState;
use App\Presentation\RequestSummary;
use App\Presentation\ServiceFollowUp;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TypeError;

final class ServiceRequestsPresentationTest extends TestCase
{
    public function test_fixtures_are_deterministic_independent_and_reference_only_their_example_services(): void
    {
        self::assertEquals(DemoServiceRequests::services(), DemoServiceRequests::services());
        self::assertEquals(DemoServiceRequests::requests(), DemoServiceRequests::requests());
        $services = DemoServiceRequests::services();
        self::assertCount(3, $services);
        self::assertSame(['Sites Imagine · Onepage', 'Sites Imagine · Landing Page', 'Performance AI'], array_column($services, 'product'));
        $references = array_column($services, 'reference');
        self::assertCount(3, array_unique($references));
        foreach (DemoServiceRequests::requests() as $request) {
            self::assertContains($request->serviceReference, $references);
            self::assertInstanceOf(JourneyState::class, $request->state);
            foreach ($request->updates as $update) {
                self::assertInstanceOf(IllustrativeUpdate::class, $update);
            }
        }
        self::assertSame(JourneyState::Example, JourneyState::tryFrom('paid administrator') ?? JourneyState::Example);
    }

    public function test_references_empty_oversized_text_and_untyped_update_lists_are_rejected(): void
    {
        $invalid = [
            fn () => new ServiceFollowUp('customer-123', 'Fictício', JourneyState::Example, 'Exemplo', 'Sem data'),
            fn () => new ServiceFollowUp('DEMO-SVC-X', ' ', JourneyState::Example, 'Exemplo', 'Sem data'),
            fn () => new ServiceFollowUp('DEMO-SVC-X', 'Fictício', JourneyState::Example, str_repeat('a', 2001), 'Sem data'),
            fn () => new IllustrativeUpdate(' ', 'Exemplo', 'Exemplo'),
            fn () => new IllustrativeUpdate('Exemplo', str_repeat('a', 2001), 'Exemplo'),
            fn () => new RequestSummary('real-123', 'DEMO-SVC-X', 'Exemplo', 'Exemplo', JourneyState::Example),
            fn () => new RequestSummary('DEMO-REQ-X', 'real-service', 'Exemplo', 'Exemplo', JourneyState::Example),
            fn () => new RequestSummary('DEMO-REQ-X', 'DEMO-SVC-X', '', 'Exemplo', JourneyState::Example),
            fn () => new RequestSummary('DEMO-REQ-X', 'DEMO-SVC-X', 'Exemplo', str_repeat('a', 2001), JourneyState::Example),
            fn () => new RequestSummary('DEMO-REQ-X', 'DEMO-SVC-X', 'Exemplo', 'Exemplo', JourneyState::Example, ['injected']),
            fn () => new RequestSummary('DEMO-REQ-X', 'DEMO-SVC-X', 'Exemplo', 'Exemplo', JourneyState::Example, ['key' => new IllustrativeUpdate('1', 'Exemplo', 'Exemplo')]),
            fn () => new RequestSummary('DEMO-REQ-X', 'DEMO-SVC-X', 'Exemplo', 'Exemplo', JourneyState::Example, array_fill(0, 21, new IllustrativeUpdate('1', 'Exemplo', 'Exemplo'))),
        ];
        foreach ($invalid as $construct) {
            try {
                $construct();
                self::fail('Invalid fictional presentation input was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_arbitrary_status_string_cannot_be_a_service_state(): void
    {
        $this->expectException(TypeError::class);
        new ServiceFollowUp('DEMO-SVC-X', 'Exemplo', 'paid', 'Fictício', 'Sem data');
    }

    public function test_arbitrary_status_string_cannot_be_a_request_state(): void
    {
        $this->expectException(TypeError::class);
        new RequestSummary('DEMO-REQ-X', 'DEMO-SVC-X', 'Exemplo', 'Fictício', 'approved');
    }

    public function test_presentation_records_are_readonly_and_have_no_actor_organization_or_permission_fields(): void
    {
        foreach ([ServiceFollowUp::class, RequestSummary::class, IllustrativeUpdate::class] as $class) {
            $reflection = new \ReflectionClass($class);
            self::assertTrue($reflection->isReadOnly());
            self::assertSame(['__construct'], array_map(fn ($method) => $method->name, $reflection->getMethods()));
            foreach ($reflection->getProperties() as $property) {
                self::assertNotContains($property->name, ['actor', 'organization', 'organizationId', 'permissions', 'session']);
            }
        }
    }
}
