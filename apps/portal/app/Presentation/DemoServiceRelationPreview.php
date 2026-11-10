<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Domain\ClientOverview\DemoServiceRelationPage;
use App\Domain\ClientOverview\DemoServiceRelationProjector;

/** Local adapter, not an authenticated services/contracts repository. */
final class DemoServiceRelationPreview
{
    public static function firstPage(): DemoServiceRelationPage
    {
        return (new DemoServiceRelationProjector())->project(
            DemoServiceRequests::services(),
            DemoRequestInbox::examples(),
            DemoContractOverview::records(),
            DemoRequestInbox::organization(),
            limit: 10,
        );
    }

    /** @return list<string> */
    public static function lines(): array
    {
        $page = self::firstPage();
        if (!$page->isPreparedPreview()) {
            return [];
        }

        return array_map(
            static fn (array $row): string => $row['service'].' — '
                .$row['request_count'].' solicitação(ões) fictícia(s) e '
                .$row['contract_count'].' contrato(s) ilustrativo(s)',
            $page->rows,
        );
    }
}
