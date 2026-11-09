<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\ClientOverview\DemoOverviewComposer;
use App\Domain\ClientOverview\DemoOverviewResult;
use App\Domain\Contracts\DemoContractRecord;
use App\Domain\Contracts\DemoContractStage;
use App\Domain\Organizations\OrganizationId;
use App\Domain\ServiceRequests\ServiceRequestSnapshot;
use App\Domain\ServiceRequests\ServiceRequestStatus;
use App\Presentation\DemoClientOverview;
use App\Presentation\DemoContractOverview;
use App\Presentation\DemoRequestInbox;
use App\Presentation\DemoServiceRequests;
use App\Presentation\JourneyState;
use App\Presentation\ServiceFollowUp;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DemoClientOverviewConsistencyTest extends TestCase
{
    private function compose(array $services, array $requests, array $contracts): DemoOverviewResult
    {
        return (new DemoOverviewComposer())->compose(
            $services, $requests, $contracts, DemoRequestInbox::organization(),
        );
    }

    public function test_demo_catalogs_are_consistent_and_each_service_has_one_request_and_contract(): void
    {
        $summary = DemoClientOverview::summary();
        self::assertTrue($summary->isConsistent());
        self::assertSame('demo_consistent', $summary->reason);
        self::assertSame(3, $summary->serviceCount);
        self::assertSame(3, $summary->requestCount);
        self::assertSame(3, $summary->contractCount);
        self::assertSame(3, count($summary->rows));
        self::assertCount(3, DemoClientOverview::rows());
        self::assertSame([
            'DEMO-SVC-ONEPAGE', 'DEMO-SVC-LANDING', 'DEMO-SVC-PERFORMANCE',
        ], array_column($summary->rows, 'reference'));
        self::assertSame('Sites Imagine · Onepage', $summary->rows[0]['service']);
        self::assertSame('Sites Imagine · Landing Page', $summary->rows[1]['service']);
        self::assertSame('Performance AI', $summary->rows[2]['service']);
        self::assertFalse($summary->mayExposeCustomerData());
        self::assertFalse($summary->authorizesServiceAccess());
        self::assertFalse($summary->authorizesContractOrPayment());
        self::assertTrue((new \ReflectionClass(DemoOverviewResult::class))->isReadOnly());
    }

    public function test_cross_tenant_request_or_contract_causes_atomic_rejection(): void
    {
        $services = DemoServiceRequests::services();
        $requests = DemoRequestInbox::examples();
        $contracts = DemoContractOverview::records();
        $foreign = new OrganizationId('DEMO-ORG-FOREIGN');

        $requests[1] = new ServiceRequestSnapshot(
            $requests[1]->requestId,
            $foreign,
            $requests[1]->serviceReference,
            $requests[1]->status,
            $requests[1]->revision,
        );
        $result = $this->compose($services, $requests, $contracts);
        $this->assertNoRecords($result, 'foreign_organization');

        $requests = DemoRequestInbox::examples();
        $contracts[1] = new DemoContractRecord(
            $contracts[1]->reference,
            $foreign,
            $contracts[1]->serviceReference,
            $contracts[1]->serviceLabel,
            $contracts[1]->stage,
        );
        $this->assertNoRecords($this->compose($services, $requests, $contracts), 'foreign_organization');
    }

    public function test_mismatched_services_are_rejected_instead_of_silently_hidden(): void
    {
        $services = DemoServiceRequests::services();
        $requests = DemoRequestInbox::examples();
        $contracts = DemoContractOverview::records();

        $requests[0] = new ServiceRequestSnapshot(
            'DEMO-REQ-UNKNOWN', DemoRequestInbox::organization(),
            'DEMO-SVC-UNKNOWN', ServiceRequestStatus::Draft, 0,
        );
        $this->assertNoRecords($this->compose($services, $requests, $contracts), 'inconsistent_service_reference');

        $requests = DemoRequestInbox::examples();
        $contracts[0] = new DemoContractRecord(
            'DEMO-CTR-UNKNOWN', DemoRequestInbox::organization(),
            'DEMO-SVC-UNKNOWN', 'Produto fictício', DemoContractStage::Draft,
        );
        $this->assertNoRecords($this->compose($services, $requests, $contracts), 'inconsistent_service_reference');

        $contracts = DemoContractOverview::records();
        $contracts[0] = new DemoContractRecord(
            $contracts[0]->reference, $contracts[0]->organizationId,
            $contracts[0]->serviceReference, 'Serviço incoerente',
            DemoContractStage::Draft,
        );
        $this->assertNoRecords($this->compose($services, $requests, $contracts), 'inconsistent_service_reference');
    }

    public function test_missing_relations_and_duplicate_identifiers_are_rejected(): void
    {
        $services = DemoServiceRequests::services();
        $requests = DemoRequestInbox::examples();
        $contracts = DemoContractOverview::records();

        $this->assertNoRecords($this->compose($services, array_slice($requests, 1), $contracts), 'missing_service_relation');
        $this->assertNoRecords($this->compose($services, $requests, array_slice($contracts, 1)), 'missing_service_relation');

        $dupeServices = $services;
        $dupeServices[] = $services[0];
        $this->assertNoRecords($this->compose($dupeServices, $requests, $contracts), 'duplicate_reference');

        $dupeRequests = $requests;
        $dupeRequests[] = $requests[0];
        $this->assertNoRecords($this->compose($services, $dupeRequests, $contracts), 'duplicate_reference');

        $dupeContracts = $contracts;
        $dupeContracts[] = $contracts[0];
        $this->assertNoRecords($this->compose($services, $requests, $dupeContracts), 'duplicate_reference');
    }

    public function test_invalid_unbounded_or_nonlist_fixtures_return_no_partial_data(): void
    {
        $services = DemoServiceRequests::services();
        $requests = DemoRequestInbox::examples();
        $contracts = DemoContractOverview::records();

        foreach ([[], ['bad-key' => $services[0]], [new \stdClass()]] as $bad) {
            $this->assertNoRecords($this->compose($bad, $requests, $contracts), 'invalid_fixture_input');
            $this->assertNoRecords($this->compose($services, $bad, $contracts), 'invalid_fixture_input');
            $this->assertNoRecords($this->compose($services, $requests, $bad), 'invalid_fixture_input');
        }
        $overLimit = array_fill(0, 51, $services[0]);
        $this->assertNoRecords($this->compose($overLimit, $requests, $contracts), 'invalid_fixture_input');
    }

    public function test_ready_result_cannot_be_mistaken_for_a_permission_or_payment(): void
    {
        $result = DemoClientOverview::summary();
        self::assertTrue($result->isConsistent());
        self::assertFalse($result->authorizesServiceAccess());
        self::assertFalse($result->authorizesContractOrPayment());

        $this->expectException(InvalidArgumentException::class);
        DemoOverviewResult::rejected('customer_authorized');
    }

    private function assertNoRecords(DemoOverviewResult $result, string $reason): void
    {
        self::assertFalse($result->isConsistent());
        self::assertSame($reason, $result->reason);
        self::assertSame([], $result->rows);
        self::assertSame(0, $result->serviceCount);
        self::assertSame(0, $result->requestCount);
        self::assertSame(0, $result->contractCount);
        self::assertFalse($result->mayExposeCustomerData());
        self::assertFalse($result->authorizesServiceAccess());
        self::assertFalse($result->authorizesContractOrPayment());
    }
}
