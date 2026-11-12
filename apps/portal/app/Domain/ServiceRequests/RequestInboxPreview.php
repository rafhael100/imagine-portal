<?php

declare(strict_types=1);

namespace App\Domain\ServiceRequests;

use App\Domain\Organizations\OrganizationId;

/**
 * In-memory deterministic projection for an UNMOUNTED preview.
 * A supplied OrganizationId is NOT verified identity and MUST NOT become a query
 * authorization or HTTP response filter. Reject mixed tenants, never silently
 * filter foreign records from an unsafe bulk-read.
 */
final class RequestInboxPreview
{
    private const MAX_FIXTURES = 1000;
    private const MAX_PAGE = 50;
    private const MAX_OFFSET = 10000;

    /**
     * @param array<array-key, mixed> $snapshots Untrusted in-memory fixtures.
     */
    public function project(
        array $snapshots,
        OrganizationId $selectedOrganization,
        ?ServiceRequestStatus $status = null,
        int $limit = 20,
        int $offset = 0,
    ): RequestInboxPreviewResult {
        if ($limit < 1 || $limit > self::MAX_PAGE || $offset < 0 || $offset > self::MAX_OFFSET) {
            return RequestInboxPreviewResult::rejected('invalid_preview_pagination');
        }
        if (!array_is_list($snapshots) || count($snapshots) > self::MAX_FIXTURES) {
            return RequestInboxPreviewResult::rejected('invalid_preview_input');
        }

        $seen = [];
        foreach ($snapshots as $snapshot) {
            if (!$snapshot instanceof ServiceRequestSnapshot) {
                return RequestInboxPreviewResult::rejected('invalid_preview_input');
            }
            if (!$snapshot->organizationId->equals($selectedOrganization)) {
                return RequestInboxPreviewResult::rejected('mixed_organization');
            }
            if (isset($seen[$snapshot->requestId])) {
                return RequestInboxPreviewResult::rejected('duplicate_request_reference');
            }
            $seen[$snapshot->requestId] = true;
        }

        $filtered = array_values(array_filter(
            $snapshots,
            static fn (ServiceRequestSnapshot $request): bool =>
                $status === null || $request->status === $status,
        ));

        // No invented chronology or SLA: stable ordering by opaque request ID.
        usort($filtered, static fn (ServiceRequestSnapshot $a, ServiceRequestSnapshot $b): int =>
            strcmp($a->requestId, $b->requestId));

        $total = count($filtered);
        $items = array_slice($filtered, $offset, $limit);
        $nextOffset = $offset + count($items) < $total ? $offset + count($items) : null;

        return RequestInboxPreviewResult::preview($items, $total, $nextOffset);
    }
}
