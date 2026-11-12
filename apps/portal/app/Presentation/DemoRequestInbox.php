<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Domain\Organizations\OrganizationId;
use App\Domain\ServiceRequests\RequestInboxPreview;
use App\Domain\ServiceRequests\RequestInboxPreviewResult;
use App\Domain\ServiceRequests\ServiceRequestSnapshot;
use App\Domain\ServiceRequests\ServiceRequestStatus;

/** Explicitly fictitious, local, side-effect-free data for Blade previews. */
final class DemoRequestInbox
{
    public static function organization(): OrganizationId
    {
        return new OrganizationId('DEMO-ORG-AURORA');
    }

    /** @return list<ServiceRequestSnapshot> */
    public static function examples(): array
    {
        $org = self::organization();

        return [
            new ServiceRequestSnapshot('DEMO-REQ-PERFORMANCE', $org, 'DEMO-SVC-PERFORMANCE', ServiceRequestStatus::Resolved, 5),
            new ServiceRequestSnapshot('DEMO-REQ-LANDING', $org, 'DEMO-SVC-LANDING', ServiceRequestStatus::WaitingOnCustomer, 2),
            new ServiceRequestSnapshot('DEMO-REQ-ONEPAGE', $org, 'DEMO-SVC-ONEPAGE', ServiceRequestStatus::InReview, 3),
        ];
    }

    public static function preview(): RequestInboxPreviewResult
    {
        return (new RequestInboxPreview())->project(self::examples(), self::organization(), limit: 10);
    }

    /** @return list<list<string>> */
    public static function rows(): array
    {
        $inbox = self::preview();
        if (!$inbox->isPreparedPreview()) {
            return [];
        }

        return array_map(
            static fn (ServiceRequestSnapshot $request): array => [
                $request->requestId,
                $request->serviceReference,
                $request->status->label(),
                'Revisão fictícia '.$request->revision,
            ],
            $inbox->items,
        );
    }
}
