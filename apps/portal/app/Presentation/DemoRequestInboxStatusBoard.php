<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Domain\ServiceRequests\ServiceRequestStatus;

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
        $inbox = DemoRequestInbox::preview();
        if (!$inbox->isPreparedPreview()
            || $inbox->nextOffset !== null
            || $inbox->total !== count($inbox->items)) {
            return [];
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
