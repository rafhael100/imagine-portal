<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Domain\ServiceRequests\ServiceRequestStatus;
use App\Domain\ServiceRequests\ServiceRequestSnapshot;
use App\Domain\ServiceRequests\RequestInboxPreviewResult;

/**
 * F142: in-memory status overview over the same authorized-for-DEMO-only
 * inbox projection used by the requests page.
 *
 * A partial page is NEVER presented as a full status distribution.
 * These counts are neither customer data nor operational workload.
 */
final class DemoRequestInboxStatusBoard
{
    /** @return list<array{0:string,1:string}> */
    public static function rows(): array
    {
        return self::fromPreview(DemoRequestInbox::preview());
    }

    /** @return list<array{0:string,1:string}> */
    public static function fromPreview(RequestInboxPreviewResult $inbox): array
    {
        if (!$inbox->isPreparedPreview()
            || $inbox->nextOffset !== null
            || $inbox->total !== count($inbox->items)
            || $inbox->total > 50) {
            return [];
        }

        // Even a valid typed DTO is not a bearer of tenant authority.
        // The DEMO summary only accepts the one fixed fictional company.
        $seen = [];
        foreach ($inbox->items as $request) {
            if (!$request instanceof ServiceRequestSnapshot
                || !$request->organizationId->equals(DemoRequestInbox::organization())
                || isset($seen[$request->requestId])) {
                return [];
            }
            $seen[$request->requestId] = true;
        }

        $counts = [];
        foreach (ServiceRequestStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }
        foreach ($inbox->items as $request) {
            ++$counts[$request->status->value];
        }

        $rows = [];
        foreach (ServiceRequestStatus::cases() as $status) {
            if ($counts[$status->value] > 0) {
                $rows[] = [$status->label(), (string) $counts[$status->value]];
            }
        }

        return $rows;
    }

    public static function isOperational(): bool
    {
        return false;
    }

    public static function authorizesRequestTransition(): bool
    {
        return false;
    }
}
