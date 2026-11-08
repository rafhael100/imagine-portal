<?php

namespace App\Domain\PerformanceDashboard;

/** No published, active or externally connected campaign status in P02-D. */
enum DemoCampaignStage: string
{
    case MockDraft = 'mock_draft';
    case MockReview = 'mock_review';

    public function label(): string
    {
        return match ($this) {
            self::MockDraft => 'Rascunho fictício',
            self::MockReview => 'Revisão simulada',
        };
    }
}
