<?php

declare(strict_types=1);

namespace App\Domain\RestoreSafety;

use App\Domain\Organizations\OrganizationId;
use InvalidArgumentException;

/**
 * Synthetic observation of a SEPARATE authority. Identity strings are merely
 * fixture labels: they do not prove physical separation or durability.
 */
final readonly class LabExternalWitnessFloor
{
    public function __construct(
        public OrganizationId $organization,
        public string $witnessClusterId,
        public int $sequence,
        public int $sessionFloor,
        public int $membershipFloor,
    ) {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{3,63}\z/D', $witnessClusterId) !== 1
            || $sequence < 1 || $sessionFloor < 1 || $membershipFloor < 1
            || $sequence > 1000000000
            || $sessionFloor > 1000000000 || $membershipFloor > 1000000000) {
            throw new InvalidArgumentException('Invalid laboratory external witness observation.');
        }
    }

    public function matches(self $other): bool
    {
        return $this->organization->equals($other->organization)
            && $this->witnessClusterId === $other->witnessClusterId
            && $this->sequence === $other->sequence
            && $this->sessionFloor === $other->sessionFloor
            && $this->membershipFloor === $other->membershipFloor;
    }
}
