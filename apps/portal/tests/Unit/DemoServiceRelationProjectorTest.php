<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\ClientOverview\DemoServiceRelationPage;
use App\Domain\ClientOverview\DemoServiceRelationProjector;
use App\Domain\Contracts\DemoContractRecord;
use App\Domain\Contracts\DemoContractStage;
use App\Domain\Organizations\OrganizationId;
use App\Domain\ServiceRequests\ServiceRequestSnapshot;
use App\Domain\ServiceRequests\ServiceRequestStatus;
use App\Presentation\DemoContractOverview;
use App\Presentation\DemoRequestInbox;
use App\Presentation\DemoServiceRelationPreview;
use App\Presentation\DemoServiceRequests;
use App\Presentation\JourneyState;
use App\Presentation\ServiceFollowUp;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DemoServiceRelationProjectorTest extends TestCase
{
    private function project(
        ?array $services = null,
        ?array $requests = null,
        ?array $contracts = null,
        ?OrganizationId $org = null,
        int $limit = 10,
        ?string $after = null,
    ): DemoServiceRelationPage {
        return (new DemoServiceRelationProjector())->project(
            $services ?? DemoServiceRequests::services(),
            $requests ?? DemoRequestInbox::examples(),
            $contracts ?? DemoContractOverview::records(),
            $org ?? DemoRequestInbox::organization(),
            $limit,
            $after,
        );
    }

    private function checkBlocked(DemoServiceRelationPage $page, string $reason): void
    {
        self::assertFalse($page->isPreparedPreview());
        self::assertSame($reason, $page->reason);
        self::assertSame([], $page->rows);
        self::assertSame(0, $page->totalServices);
        self::assertSame(0, $page->totalRequests);
        self::assertSame(0, $page->totalContracts);
        self::assertNull($page->nextCursor);
        self::assertFalse($page->authorizesServiceAccess());
        self::assertFalse($page->mayExposeCustomerData());
        self::assertFalse($page->authorizesContractOrPayment());
        self::assertFalse($page->grantsUniversityBenefit());
    }

    public function test_existing_three_services_project_with_one_request_and_contract_each(): void
    {
        $page = DemoServiceRelationPreview::firstPage();
        self::assertTrue($page->isPreparedPreview());
        self::assertSame('demo_prepared', $page->reason);
        self::assertSame(3, $page->totalServices);
        self::assertSame(3, $page->totalRequests);
        self::assertSame(3, $page->totalContracts);
        self::assertCount(3, $page->rows);
        self::assertSame([
            'DEMO-SVC-LANDING',
            'DEMO-SVC-ONEPAGE',
            'DEMO-SVC-PERFORMANCE',
        ], array_column($page->rows, 'reference'));
        self::assertSame([1, 1, 1], array_column($page->rows, 'request_count'));
        self::assertSame([1, 1, 1], array_column($page->rows, 'contract_count'));
        self::assertNull($page->nextCursor);
        self::assertCount(3, DemoServiceRelationPreview::lines());
        self::assertFalse($page->mayExposeCustomerData());
        self::assertFalse($page->authorizesServiceAccess());
        self::assertFalse($page->authorizesContractOrPayment());
        self::assertFalse($page->grantsUniversityBenefit());
        self::assertTrue((new \ReflectionClass(DemoServiceRelationPage::class))->isReadOnly());
    }

    public function test_zero_to_many_relations_are_supported_without_fabricating_access_or_signatures(): void
    {
        $services = DemoServiceRequests::services();
        $services[] = new ServiceFollowUp(
            'DEMO-SVC-ZERO', 'Serviço fictício sem relações', JourneyState::Example,
            'Somente ensaio de zero relações.', 'Sem operação.',
        );
        $requests = DemoRequestInbox::examples();
        $requests[] = new ServiceRequestSnapshot(
            'DEMO-REQ-LANDING-2',
            DemoRequestInbox::organization(),
            'DEMO-SVC-LANDING',
            ServiceRequestStatus::Submitted,
            0,
        );
        $contracts = DemoContractOverview::records();
        $contracts[] = new DemoContractRecord(
            'DEMO-CTR-LANDING-2',
            DemoRequestInbox::organization(),
            'DEMO-SVC-LANDING',
            'Sites Imagine · Landing Page',
            DemoContractStage::Draft,
        );
        $page = $this->project($services, $requests, $contracts);
        self::assertTrue($page->isPreparedPreview());
        self::assertSame(4, $page->totalServices);
        self::assertSame(4, $page->totalRequests);
        self::assertSame(4, $page->totalContracts);
        $byRef = array_column($page->rows, null, 'reference');
        self::assertSame(2, $byRef['DEMO-SVC-LANDING']['request_count']);
        self::assertSame(2, $byRef['DEMO-SVC-LANDING']['contract_count']);
        self::assertSame(0, $byRef['DEMO-SVC-ZERO']['request_count']);
        self::assertSame(0, $byRef['DEMO-SVC-ZERO']['contract_count']);
        self::assertFalse($page->authorizesContractOrPayment());
    }

    public function test_stable_pagination_uses_sorted_service_refs_and_validated_opaque_cursor(): void
    {
        $first = $this->project(limit: 1);
        self::assertSame(['DEMO-SVC-LANDING'], array_column($first->rows, 'reference'));
        self::assertSame('DEMO-SVC-LANDING', $first->nextCursor);
        $second = $this->project(limit: 1, after: $first->nextCursor);
        self::assertSame(['DEMO-SVC-ONEPAGE'], array_column($second->rows, 'reference'));
        self::assertSame('DEMO-SVC-ONEPAGE', $second->nextCursor);
        $third = $this->project(limit: 1, after: $second->nextCursor);
        self::assertSame(['DEMO-SVC-PERFORMANCE'], array_column($third->rows, 'reference'));
        self::assertNull($third->nextCursor);
        self::assertSame(3, $second->totalServices);
        self::assertSame(3, $third->totalContracts);

        $full = $this->project(limit: 20);
        self::assertSame(
            ['DEMO-SVC-LANDING', 'DEMO-SVC-ONEPAGE', 'DEMO-SVC-PERFORMANCE'],
            array_column($full->rows, 'reference'),
        );
    }

    public function test_unknown_or_malformed_cursor_never_returns_partial_rows(): void
    {
        foreach (['DEMO-SVC-UNKNOWN', 'DEMO-SVC-PERFORMANCE'] as $after) {
            $this->checkBlocked($this->project(after: $after), 'unknown_cursor');
        }
        foreach (['', '../customer', "DEMO-SVC-ONEPAGE\n"] as $after) {
            $this->checkBlocked($this->project(after: $after), 'invalid_pagination');
        }
        foreach ([0, -1, 21] as $limit) {
            $this->checkBlocked($this->project(limit: $limit), 'invalid_pagination');
        }
    }

    public function test_any_foreign_organization_is_rejected_even_when_outside_selected_page(): void
    {
        $requests = DemoRequestInbox::examples();
        $requests[] = new ServiceRequestSnapshot(
            'DEMO-REQ-FOREIGN',
            new OrganizationId('DEMO-ORG-FOREIGN'),
            'DEMO-SVC-PERFORMANCE',
            ServiceRequestStatus::InReview,
            1,
        );
        $this->checkBlocked($this->project(requests: $requests, limit: 1), 'foreign_organization');

        $contracts = DemoContractOverview::records();
        $contracts[] = new DemoContractRecord(
            'DEMO-CTR-FOREIGN',
            new OrganizationId('DEMO-ORG-FOREIGN'),
            'DEMO-SVC-ONEPAGE',
            'Sites Imagine · Onepage',
            DemoContractStage::Draft,
        );
        $this->checkBlocked($this->project(contracts: $contracts, limit: 1), 'foreign_organization');
    }

    public function test_duplicate_ids_are_rejected_in_each_relation_set(): void
    {
        $services = DemoServiceRequests::services();
        $services[] = $services[0];
        $this->checkBlocked($this->project(services: $services), 'duplicate_reference');

        $requests = DemoRequestInbox::examples();
        $requests[] = $requests[0];
        $this->checkBlocked($this->project(requests: $requests), 'duplicate_reference');

        $contracts = DemoContractOverview::records();
        $contracts[] = $contracts[0];
        $this->checkBlocked($this->project(contracts: $contracts), 'duplicate_reference');
    }

    public function test_orphaned_relation_or_wrong_service_name_blocks_entire_dataset(): void
    {
        $requests = DemoRequestInbox::examples();
        $requests[] = new ServiceRequestSnapshot(
            'DEMO-REQ-ORPHAN', DemoRequestInbox::organization(),
            'DEMO-SVC-ORPHAN', ServiceRequestStatus::Submitted, 0,
        );
        $this->checkBlocked($this->project(requests: $requests, limit: 1), 'inconsistent_service_reference');

        $contracts = DemoContractOverview::records();
        $contracts[0] = new DemoContractRecord(
            $contracts[0]->reference, $contracts[0]->organizationId,
            $contracts[0]->serviceReference, 'Unrelated product',
            DemoContractStage::Draft,
        );
        $this->checkBlocked($this->project(contracts: $contracts), 'inconsistent_service_reference');
    }

    public function test_invalid_or_unbounded_fixture_collections_deny_atomically(): void
    {
        $services = DemoServiceRequests::services();
        $requests = DemoRequestInbox::examples();
        $contracts = DemoContractOverview::records();

        foreach ([[], ['not-list' => $services[0]], [new \stdClass()], array_fill(0, 51, $services[0])] as $bad) {
            $this->checkBlocked($this->project(services: $bad), 'invalid_fixture_input');
        }
        foreach ([['bad-key' => $requests[0]], [new \stdClass()], array_fill(0, 201, $requests[0])] as $bad) {
            $this->checkBlocked($this->project(requests: $bad), 'invalid_fixture_input');
        }
        foreach ([['bad-key' => $contracts[0]], [new \stdClass()], array_fill(0, 201, $contracts[0])] as $bad) {
            $this->checkBlocked($this->project(contracts: $bad), 'invalid_fixture_input');
        }
        self::assertTrue($this->project(requests: [], contracts: [])->isPreparedPreview());
    }

    public function test_missing_organization_and_unrecognized_block_reason_are_not_permissions(): void
    {
        $projector = new DemoServiceRelationProjector();
        $this->checkBlocked($projector->project([], [], [], null), 'missing_organization');
        $this->expectException(InvalidArgumentException::class);
        DemoServiceRelationPage::blocked('authorized_customer');
    }
}
