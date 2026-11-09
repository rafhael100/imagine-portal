<?php

declare(strict_types=1);

namespace App\Labs\F31;

use App\Labs\F24\F24LabSessionProof;

/**
 * The floor is only a synthetic second-DB observation, not a proof of
 * durable independence or a production authorization decision.
 */
final readonly class F31ExternalFloorSnapshot
{
    public function __construct(
        public string $organization,
        public int $sessionFloor,
        public int $membershipFloor,
        public int $sequenceNumber,
    ) {
    }

    public function accepts(F24LabSessionProof $proof): bool
    {
        return $proof->selectedOrganization()->value === $this->organization
            && $proof->syntheticSessionRevision() >= $this->sessionFloor
            && $proof->syntheticMembershipRevision() >= $this->membershipFloor;
    }

    public function matches(self $other): bool
    {
        return $this->organization === $other->organization
            && $this->sessionFloor === $other->sessionFloor
            && $this->membershipFloor === $other->membershipFloor
            && $this->sequenceNumber === $other->sequenceNumber;
    }
}
