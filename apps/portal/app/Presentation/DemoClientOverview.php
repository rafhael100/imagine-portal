<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Domain\ClientOverview\DemoOverviewComposer;
use App\Domain\ClientOverview\DemoOverviewResult;

/** Read-only aggregate of three independently defined synthetic catalogues. */
final class DemoClientOverview
{
    public static function summary(): DemoOverviewResult
    {
        return (new DemoOverviewComposer())->compose(
            DemoServiceRequests::services(),
            DemoRequestInbox::examples(),
            DemoContractOverview::records(),
            DemoRequestInbox::organization(),
        );
    }

    /** @return list<list<string>> */
    public static function rows(): array
    {
        $summary = self::summary();
        if (!$summary->isConsistent()) {
            return [];
        }

        return array_map(
            static fn (array $row): array => [
                $row['reference'], $row['service'], $row['request_status'], $row['contract_status'],
            ],
            $summary->rows,
        );
    }
}
