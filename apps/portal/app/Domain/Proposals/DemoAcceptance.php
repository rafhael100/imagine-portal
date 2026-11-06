<?php

namespace App\Domain\Proposals;

use App\Domain\Identity\PortalUserId;
use App\Domain\Organizations\OrganizationId;

/**
 * In-memory evidence of an EXPLICIT acknowledgement of a synthetic proposal.
 * NOT a legal signature, order, payment authorization or actual subscription.
 */
final readonly class DemoAcceptance
{
    public function __construct(
        public DemoProposalId $proposalId,
        public OrganizationId $organizationId,
        public PortalUserId $acknowledgingActorId,
        public string $presentedFingerprint,
        public string $acknowledgedDemoTermsVersion,
    ) {
    }

    public function isLegallyBinding(): bool
    {
        return false;
    }

    public function triggersBilling(): bool
    {
        return false;
    }

    public function triggersProvisioning(): bool
    {
        return false;
    }
}
