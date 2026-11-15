<?php

namespace App\Domain\PerformanceOnboarding;

use App\Domain\Identity\PortalUserId;
use App\Domain\Organizations\OrganizationId;
use App\Domain\Proposals\DemoProposalId;

/**
 * In-memory synthetic intake. Its receipt does not imply account access,
 * ad platform connection, contractual acceptance or service activation.
 */
final readonly class DemoOnboardingDraft
{
    /**
     * @param list<DemoAdAccount> $adAccounts
     */
    public function __construct(
        public DemoOnboardingId $id,
        public OrganizationId $organizationId,
        public DemoProposalId $proposalId,
        public string $proposalFingerprint,
        public PortalUserId $requestingActorId,
        public DemoBusinessGoal $goal,
        public array $adAccounts,
        public DemoOnboardingStatus $status = DemoOnboardingStatus::AwaitingMockReview,
    ) {
    }

    public function isProvisioned(): bool
    {
        return false;
    }

    public function isExternallyConnected(): bool
    {
        return false;
    }
}
