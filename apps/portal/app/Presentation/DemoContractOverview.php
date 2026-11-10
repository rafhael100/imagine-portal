<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Domain\Contracts\DemoContractRecord;
use App\Domain\Contracts\DemoContractStage;

/** Static previews for three independent services, never a sales database. */
final class DemoContractOverview
{
    /** @return list<DemoContractRecord> */
    public static function records(): array
    {
        $organization = DemoRequestInbox::organization();

        return [
            new DemoContractRecord('DEMO-CTR-ONEPAGE', $organization, 'DEMO-SVC-ONEPAGE',
                'Sites Imagine · Onepage', DemoContractStage::Draft),
            new DemoContractRecord('DEMO-CTR-LANDING', $organization, 'DEMO-SVC-LANDING',
                'Sites Imagine · Landing Page', DemoContractStage::UnderReview),
            new DemoContractRecord('DEMO-CTR-PERFORMANCE', $organization, 'DEMO-SVC-PERFORMANCE',
                'Performance AI', DemoContractStage::AwaitingIllustrativeFeedback),
        ];
    }

    /** @return list<list<string>> */
    public static function rows(): array
    {
        return array_map(
            static fn (DemoContractRecord $contract): array => [
                $contract->reference,
                $contract->serviceLabel,
                $contract->stage->label(),
                $contract->signatureLabel(),
            ],
            self::records(),
        );
    }
}
