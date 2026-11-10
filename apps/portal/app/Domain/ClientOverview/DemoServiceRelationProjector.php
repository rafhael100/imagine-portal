<?php

declare(strict_types=1);

namespace App\Domain\ClientOverview;

use App\Domain\Contracts\DemoContractRecord;
use App\Domain\Organizations\OrganizationId;
use App\Domain\ServiceRequests\ServiceRequestSnapshot;
use App\Presentation\ServiceFollowUp;

/**
 * Untrusted in-memory fixtures only. This is not a repository or authorized
 * tenant read. Validate ALL records before slicing to avoid partial leaks.
 *
 * Unlike F17's deliberately 1:1 mock, a service may have 0..N requests
 * and 0..N contract drafts. No real legal/financial state exists here.
 */
final readonly class DemoServiceRelationProjector
{
    /**
     * @param array<array-key,mixed> $services
     * @param array<array-key,mixed> $requests
     * @param array<array-key,mixed> $contracts
     */
    public function project(
        array $services,
        array $requests,
        array $contracts,
        ?OrganizationId $organization,
        int $limit = 10,
        ?string $after = null,
    ): DemoServiceRelationPage {
        if ($organization === null) {
            return DemoServiceRelationPage::blocked('missing_organization');
        }
        if ($limit < 1 || $limit > 20
            || ($after !== null && preg_match('/\ADEMO-SVC-[A-Z0-9-]{1,40}\z/D', $after) !== 1)) {
            return DemoServiceRelationPage::blocked('invalid_pagination');
        }
        if (!array_is_list($services) || count($services) < 1 || count($services) > 50
            || !array_is_list($requests) || count($requests) > 200
            || !array_is_list($contracts) || count($contracts) > 200) {
            return DemoServiceRelationPage::blocked('invalid_fixture_input');
        }

        $byRef = [];
        foreach ($services as $service) {
            if (!$service instanceof ServiceFollowUp) {
                return DemoServiceRelationPage::blocked('invalid_fixture_input');
            }
            if (isset($byRef[$service->reference])) {
                return DemoServiceRelationPage::blocked('duplicate_reference');
            }
            $byRef[$service->reference] = [
                'reference' => $service->reference,
                'service' => $service->product,
                'request_count' => 0,
                'contract_count' => 0,
            ];
        }

        $requestIds = [];
        foreach ($requests as $request) {
            if (!$request instanceof ServiceRequestSnapshot) {
                return DemoServiceRelationPage::blocked('invalid_fixture_input');
            }
            if (!$request->organizationId->equals($organization)) {
                return DemoServiceRelationPage::blocked('foreign_organization');
            }
            if (isset($requestIds[$request->requestId])) {
                return DemoServiceRelationPage::blocked('duplicate_reference');
            }
            $requestIds[$request->requestId] = true;
            if (!isset($byRef[$request->serviceReference])) {
                return DemoServiceRelationPage::blocked('inconsistent_service_reference');
            }
            $byRef[$request->serviceReference]['request_count']++;
        }

        $contractIds = [];
        foreach ($contracts as $contract) {
            if (!$contract instanceof DemoContractRecord) {
                return DemoServiceRelationPage::blocked('invalid_fixture_input');
            }
            if (!$contract->organizationId->equals($organization)) {
                return DemoServiceRelationPage::blocked('foreign_organization');
            }
            if (isset($contractIds[$contract->reference])) {
                return DemoServiceRelationPage::blocked('duplicate_reference');
            }
            $contractIds[$contract->reference] = true;
            if (!isset($byRef[$contract->serviceReference])
                || $byRef[$contract->serviceReference]['service'] !== $contract->serviceLabel) {
                return DemoServiceRelationPage::blocked('inconsistent_service_reference');
            }
            $byRef[$contract->serviceReference]['contract_count']++;
        }

        // Pagination happens AFTER full validation of every input collection.
        ksort($byRef, SORT_STRING);
        $references = array_keys($byRef);
        $offset = 0;
        if ($after !== null) {
            $position = array_search($after, $references, true);
            if ($position === false) {
                return DemoServiceRelationPage::blocked('unknown_cursor');
            }
            $offset = $position + 1;
            if ($offset >= count($references)) {
                return DemoServiceRelationPage::blocked('unknown_cursor');
            }
        }

        $selected = array_slice($references, $offset, $limit);
        $page = array_map(static fn (string $ref): array => $byRef[$ref], $selected);
        if ($page === []) {
            return DemoServiceRelationPage::blocked('unknown_cursor');
        }
        $nextCursor = $offset + count($page) < count($references)
            ? $selected[count($selected) - 1]
            : null;

        return DemoServiceRelationPage::prepared(
            $page,
            count($services),
            count($requests),
            count($contracts),
            $nextCursor,
        );
    }
}
