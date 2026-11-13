<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\ClientOverview\DemoOverviewComposer;
use App\Domain\ClientOverview\DemoOverviewResult;
use App\Domain\Contracts\DemoContractRecord;
use App\Domain\Organizations\OrganizationId;
use App\Domain\ServiceRequests\ServiceRequestSnapshot;
use App\Presentation\DemoClientOverview;
use App\Presentation\DemoContractOverview;
use App\Presentation\DemoRequestInbox;
use App\Presentation\DemoServiceRequests;
use PHPUnit\Framework\TestCase;

final class DemoClientOverviewReconciliationTest extends TestCase
{
    private function compose(array $services, array $requests, array $contracts): DemoOverviewResult
    {
        return (new DemoOverviewComposer())->compose(
            $services, $requests, $contracts, DemoRequestInbox::organization()
        );
    }

    public function test_three_independent_fictional_services_reconcile_without_entitlements(): void
    {
        $result = DemoClientOverview::summary();
        self::assertTrue($result->isConsistent());
        self::assertSame('demo_consistent', $result->reason);
        self::assertSame(3, $result->serviceCount);
        self::assertSame(3, $result->requestCount);
        self::assertSame(3, $result->contractCount);
        self::assertCount(3, $result->rows);
        self::assertCount(3, DemoClientOverview::rows());
        self::assertEquals(DemoClientOverview::rows(), DemoClientOverview::rows());
        self::assertFalse($result->mayExposeCustomerData());
        self::assertFalse($result->authorizesServiceAccess());
        self::assertFalse($result->authorizesContractOrPayment());
        self::assertTrue((new \ReflectionClass(DemoOverviewResult::class))->isReadOnly());
    }

    public function test_foreign_company_blocks_every_row_and_all_counts(): void
    {
        $services = DemoServiceRequests::services();
        $requests = DemoRequestInbox::examples();
        $contracts = DemoContractOverview::records();
        $first = $requests[0];
        $requests[0] = new ServiceRequestSnapshot(
            $first->requestId, new OrganizationId('DEMO-ORG-FOREIGN'),
            $first->serviceReference, $first->status, $first->revision
        );
        $result = $this->compose($services, $requests, $contracts);
        self::assertSame('foreign_organization', $result->reason);
        self::assertSame([], $result->rows);
        self::assertSame(0, $result->serviceCount);
        self::assertSame(0, $result->requestCount);
        self::assertSame(0, $result->contractCount);
    }

    public function test_unknown_duplicate_or_inconsistent_links_fail_entire_aggregation(): void
    {
        $services = DemoServiceRequests::services();
        $requests = DemoRequestInbox::examples();
        $contracts = DemoContractOverview::records();
        self::assertSame('missing_service_relation',
            $this->compose($services, array_slice($requests, 1), $contracts)->reason);
        self::assertSame('duplicate_reference',
            $this->compose($services, [$requests[0], $requests[0]], $contracts)->reason);

        $contract = $contracts[0];
        $contracts[0] = new DemoContractRecord(
            $contract->reference, $contract->organizationId, $contract->serviceReference,
            'Wrong fictional product', $contract->stage
        );
        self::assertSame('inconsistent_service_reference',
            $this->compose($services, $requests, $contracts)->reason);
    }

    public function test_partial_or_untyped_input_never_produces_an_optimistic_dashboard(): void
    {
        $services = DemoServiceRequests::services();
        $requests = DemoRequestInbox::examples();
        $contracts = DemoContractOverview::records();
        foreach ([
            [[], $requests, $contracts],
            [['incorrect-type'], $requests, $contracts],
            [$services, [], $contracts],
            [$services, $requests, array_fill(0, 51, $contracts[0])],
            [[$services[0], $services[0]], $requests, $contracts],
        ] as [$a, $b, $c]) {
            $out = $this->compose($a, $b, $c);
            self::assertFalse($out->isConsistent());
            self::assertSame([], $out->rows);
            self::assertSame(0, $out->serviceCount);
            self::assertFalse($out->authorizesContractOrPayment());
        }
    }
}
