<?php

namespace App\Domain\PortalAccess\Authority;

use App\Domain\Identity\PortalUserId;
use InvalidArgumentException;

/**
 * Synthetic, caller-constructible snapshot for boundary composition tests.
 *
 * THIS IS NOT AN AUTHENTICATED PRINCIPAL. No signed issuer, verified factor,
 * durable session store, current revision, trusted clock or concurrency
 * control can be established by constructing this object.
 */
final readonly class PortalSessionSnapshot
{
    public function __construct(
        public PortalUserId $actorId,
        public bool $active,
        public bool $mfaVerified,
        public int $issuedAt,
        public int $expiresAt,
        public int $accountRevision,
        public int $sessionAccountRevision,
    ) {
        if ($issuedAt <= 0
            || $expiresAt <= $issuedAt
            || $accountRevision <= 0
            || $sessionAccountRevision <= 0) {
            throw new InvalidArgumentException('Invalid synthetic session snapshot.');
        }
    }

    public function passesSyntheticFreshnessChecks(int $now): bool
    {
        return $this->active
            && $this->mfaVerified
            && $now >= $this->issuedAt
            && $now < $this->expiresAt
            && $this->accountRevision === $this->sessionAccountRevision;
    }
}
