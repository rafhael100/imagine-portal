<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Organizations\OrganizationId;
use App\Domain\ServiceRequests\ServiceRequestSnapshot;
use App\Domain\ServiceRequests\ServiceRequestStatus;
use App\Presentation\DemoActivityFeed;
use App\Presentation\DemoClientOverview;
use App\Presentation\DemoRequestInbox;
use PHPUnit\Framework\TestCase;

final class DemoActivityFeedTest extends TestCase
{
    private function fixtures(): array
    {
        return DemoRequestInbox::examples();
    }

    public function test_feed_reconciles_status_and_service_without_inventing_timestamps(): void
    {
        $rows = DemoActivityFeed::rows();
        self::assertCount(3, $rows);
        self::assertSame('DEMO-REQ-PERFORMANCE', $rows[0][0]);
        self::assertSame('Conferir resolução ilustrativa', $rows[0][3]);
        self::assertSame('Versão fictícia 5', $rows[0][4]);
        self::assertSame('DEMO-REQ-ONEPAGE', $rows[1][0]);
        self::assertSame('Acompanhar revisão fictícia', $rows[1][3]);
        self::assertSame('DEMO-REQ-LANDING', $rows[2][0]);
        self::assertSame('Complementar informações de exemplo', $rows[2][3]);
        self::assertFalse(DemoActivityFeed::authorizesRequestAction());
        self::assertFalse(DemoActivityFeed::claimsChronologicalActivity());
        foreach ($rows as $row) {
            self::assertCount(5, $row);
            self::assertStringNotContainsString('2026-', implode(' ', $row));
            self::assertStringNotContainsString('prazo contratado', implode(' ', $row));
        }
    }

    public function test_foreign_tenant_invalid_type_duplicate_or_missing_request_denies_all_rows(): void
    {
        $summary = DemoClientOverview::summary();
        $org = DemoRequestInbox::organization();
        $requests = $this->fixtures();
        self::assertCount(3, DemoActivityFeed::fromSources($summary, $requests, $org));

        $foreign = $requests;
        $foreign[0] = new ServiceRequestSnapshot(
            'DEMO-REQ-PERFORMANCE', new OrganizationId('DEMO-FOREIGN-ORG'),
            'DEMO-SVC-PERFORMANCE', ServiceRequestStatus::Resolved, 5,
        );
        self::assertSame([], DemoActivityFeed::fromSources($summary, $foreign, $org));

        $duplicate = $requests;
        $duplicate[1] = $requests[0];
        self::assertSame([], DemoActivityFeed::fromSources($summary, $duplicate, $org));

        $wrongService = $requests;
        $wrongService[0] = new ServiceRequestSnapshot(
            'DEMO-REQ-PERFORMANCE', $org, 'DEMO-SVC-MISSING',
            ServiceRequestStatus::Resolved, 5,
        );
        self::assertSame([], DemoActivityFeed::fromSources($summary, $wrongService, $org));

        $wrongStatus = $requests;
        $wrongStatus[0] = new ServiceRequestSnapshot(
            'DEMO-REQ-PERFORMANCE', $org, 'DEMO-SVC-PERFORMANCE',
            ServiceRequestStatus::Closed, 5,
        );
        self::assertSame([], DemoActivityFeed::fromSources($summary, $wrongStatus, $org));

        self::assertSame([], DemoActivityFeed::fromSources($summary, ['not a request', ...$requests], $org));
        self::assertSame([], DemoActivityFeed::fromSources($summary, [], $org));
        self::assertSame([], DemoActivityFeed::fromSources($summary, array_slice($requests, 1), $org));
    }

    public function test_feed_is_deterministic_when_input_order_changes(): void
    {
        $summary=DemoClientOverview::summary();
        $org=DemoRequestInbox::organization();
        $items=$this->fixtures();
        $expected=DemoActivityFeed::fromSources($summary, $items, $org);
        self::assertSame($expected, DemoActivityFeed::fromSources($summary, array_reverse($items), $org));
        self::assertSame($expected, DemoActivityFeed::fromSources($summary, [$items[1],$items[2],$items[0]], $org));
    }

    public function test_request_ref_must_be_only_an_offline_fictional_reference(): void
    {
        $summary=DemoClientOverview::summary();
        $org=DemoRequestInbox::organization();
        $items=$this->fixtures();
        $items[0]=new ServiceRequestSnapshot(
            'PROD-REQ-ALPHA', $org, 'DEMO-SVC-PERFORMANCE',
            ServiceRequestStatus::Resolved, 5,
        );
        self::assertSame([], DemoActivityFeed::fromSources($summary,$items,$org));
    }
}
