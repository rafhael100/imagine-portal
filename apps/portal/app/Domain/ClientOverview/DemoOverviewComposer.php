<?php

declare(strict_types=1);

namespace App\Domain\ClientOverview;

use App\Domain\Contracts\DemoContractRecord;
use App\Domain\Organizations\OrganizationId;
use App\Domain\ServiceRequests\ServiceRequestSnapshot;
use App\Presentation\ServiceFollowUp;

/**
 * STRICT and deterministic assembly of already-known fictional fixtures.
 *
 * All inputs are UNTRUSTED for production access: passing this check does not
 * authenticate the caller or prove ownership of any Portal customer resource.
 * Reject inconsistent or mixed organizations instead of returning a partial row.
 */
final class DemoOverviewComposer
{
    /**
     * @param array<array-key, mixed> $services
     * @param array<array-key, mixed> $requests
     * @param array<array-key, mixed> $contracts
     */
    public function compose(
        array $services,
        array $requests,
        array $contracts,
        OrganizationId $organization,
    ): DemoOverviewResult {
        foreach ([$services, $requests, $contracts] as $input) {
            if (!array_is_list($input) || count($input) === 0 || count($input) > 50) {
                return DemoOverviewResult::rejected('invalid_fixture_input');
            }
        }

        $serviceByRef = [];
        foreach ($services as $service) {
            if (!$service instanceof ServiceFollowUp) {
                return DemoOverviewResult::rejected('invalid_fixture_input');
            }
            if (isset($serviceByRef[$service->reference])) {
                return DemoOverviewResult::rejected('duplicate_reference');
            }
            $serviceByRef[$service->reference] = $service;
        }

        $requestByService = [];
        $requestRefs = [];
        foreach ($requests as $request) {
            if (!$request instanceof ServiceRequestSnapshot) {
                return DemoOverviewResult::rejected('invalid_fixture_input');
            }
            if (!$request->organizationId->equals($organization)) {
                return DemoOverviewResult::rejected('foreign_organization');
            }
            if (isset($requestRefs[$request->requestId])) {
                return DemoOverviewResult::rejected('duplicate_reference');
            }
            $requestRefs[$request->requestId] = true;
            if (!isset($serviceByRef[$request->serviceReference])) {
                return DemoOverviewResult::rejected('inconsistent_service_reference');
            }
            if (isset($requestByService[$request->serviceReference])) {
                return DemoOverviewResult::rejected('duplicate_reference');
            }
            $requestByService[$request->serviceReference] = $request;
        }

        $contractByService = [];
        $contractRefs = [];
        foreach ($contracts as $contract) {
            if (!$contract instanceof DemoContractRecord) {
                return DemoOverviewResult::rejected('invalid_fixture_input');
            }
            if (!$contract->organizationId->equals($organization)) {
                return DemoOverviewResult::rejected('foreign_organization');
            }
            if (isset($contractRefs[$contract->reference])) {
                return DemoOverviewResult::rejected('duplicate_reference');
            }
            $contractRefs[$contract->reference] = true;
            if (!isset($serviceByRef[$contract->serviceReference])
                || $serviceByRef[$contract->serviceReference]->product !== $contract->serviceLabel) {
                return DemoOverviewResult::rejected('inconsistent_service_reference');
            }
            if (isset($contractByService[$contract->serviceReference])) {
                return DemoOverviewResult::rejected('duplicate_reference');
            }
            $contractByService[$contract->serviceReference] = $contract;
        }

        $rows = [];
        foreach ($serviceByRef as $ref => $service) {
            if (!isset($requestByService[$ref], $contractByService[$ref])) {
                return DemoOverviewResult::rejected('missing_service_relation');
            }
            $rows[] = [
                'reference' => $ref,
                'service' => $service->product,
                'request_status' => $requestByService[$ref]->status->label(),
                'contract_status' => $contractByService[$ref]->stage->label(),
            ];
        }

        return DemoOverviewResult::consistent($rows);
    }
}
