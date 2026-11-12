<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Domain\Contracts\DemoContractRecord;
use App\Domain\Contracts\DemoContractStage;
use App\Domain\Organizations\OrganizationId;

/**
 * Independent fictional documents for the internal UI, no database/API.
 * This preview never proves an agreement, payment or service entitlement.
 */
final class DemoContractOverview
{
    /** @return list<DemoContractRecord> */
    public static function records(): array
    {
        $org = new OrganizationId('DEMO-ORG-AURORA');

        return [
            new DemoContractRecord(
                'DEMO-CTR-ONEPAGE', $org, 'DEMO-SVC-ONEPAGE',
                'Sites Imagine · Onepage', DemoContractStage::Draft,
            ),
            new DemoContractRecord(
                'DEMO-CTR-LANDING', $org, 'DEMO-SVC-LANDING',
                'Sites Imagine · Landing Page', DemoContractStage::UnderReview,
            ),
            new DemoContractRecord(
                'DEMO-CTR-PERFORMANCE', $org, 'DEMO-SVC-PERFORMANCE',
                'Performance AI', DemoContractStage::AwaitingIllustrativeFeedback,
            ),
        ];
    }

    /** @return list<list<string>> */
    public static function rows(): array
    {
        return array_map(
            static fn (DemoContractRecord $item): array => [
                $item->reference,
                $item->serviceLabel,
                $item->stage->label(),
                $item->signatureLabel(),
            ],
            self::records(),
        );
    }
}
