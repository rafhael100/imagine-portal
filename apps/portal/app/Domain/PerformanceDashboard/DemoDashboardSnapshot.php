<?php

namespace App\Domain\PerformanceDashboard;

use App\Domain\Organizations\OrganizationId;
use App\Domain\PerformanceOnboarding\DemoOnboardingDraft;
use App\Domain\Proposals\DemoProposalSnapshot;

/**
 * Immutable read-only presentation data, never a representation of real
 * Google/Meta campaign performance, billing or a validated Portal session.
 */
final readonly class DemoDashboardSnapshot
{
    /**
     * @param list<DemoMetric> $metrics
     * @param list<DemoCampaignCard> $campaigns
     * @param list<DemoRequestCard> $requests
     */
    public function __construct(
        public OrganizationId $organizationId,
        public DemoProposalSnapshot $proposal,
        public DemoOnboardingDraft $onboarding,
        public array $metrics,
        public array $campaigns,
        public array $requests,
        public string $source = 'synthetic_demo',
    ) {
    }

    public function isRealTime(): bool
    {
        return false;
    }

    public function canExecuteActions(): bool
    {
        return false;
    }

    public function isForSale(): bool
    {
        return false;
    }
}
