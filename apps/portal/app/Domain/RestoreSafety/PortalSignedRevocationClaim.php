<?php

declare(strict_types=1);

namespace App\Domain\RestoreSafety;

/**
 * Authenticated CLAIM of an observed revocation floor, NOT evidence that the
 * signer is truly external, durable, current, or ordered with PostgreSQL.
 */
final readonly class PortalSignedRevocationClaim
{
    public function __construct(
        public string $eventId,
        public LabExternalWitnessFloor $floor,
    ) {
    }

    public function canAcknowledgeDurableRevocation(): bool
    {
        return false;
    }

    public function canAuthorizeServing(): bool
    {
        return false;
    }
}
