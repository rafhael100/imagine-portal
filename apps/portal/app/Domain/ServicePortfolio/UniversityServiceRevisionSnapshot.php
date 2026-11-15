<?php

declare(strict_types=1);

namespace App\Domain\ServicePortfolio;

use App\Domain\Organizations\OrganizationId;
use InvalidArgumentException;

/**
 * Caller-constructible snapshot for a FUTURE independent revocation source.
 * It does NOT attest paid status, ownership, current Portal login or access.
 */
final readonly class UniversityServiceRevisionSnapshot
{
    /**
     * @param array<string,int> $revisions latest source revision per service
     * @param list<string> $revokedReferences server-confirmed revoked service refs
     */
    public function __construct(
        public OrganizationId $organizationId,
        public array $revisions,
        public array $revokedReferences,
        public int $observedAtEpoch,
        public int $expiresAtEpoch,
    ) {
        if ($observedAtEpoch <= 0
            || $expiresAtEpoch <= $observedAtEpoch
            || !array_is_list($revokedReferences)
            || count($revisions) > 50
            || count($revokedReferences) > 50) {
            throw new InvalidArgumentException('Invalid revision source snapshot.');
        }

        foreach ($revisions as $reference => $revision) {
            if (!is_string($reference)
                || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,119}\z/D', $reference) !== 1
                || !is_int($revision) || $revision < 1) {
                throw new InvalidArgumentException('Invalid service revision reference.');
            }
        }

        $revoked = [];
        foreach ($revokedReferences as $reference) {
            if (!is_string($reference)
                || !array_key_exists($reference, $revisions)
                || isset($revoked[$reference])) {
                throw new InvalidArgumentException('Invalid revoked service reference.');
            }
            $revoked[$reference] = true;
        }
    }

    public function provesActiveContract(): bool
    {
        return false;
    }

    public function mayAuthorizeUniversity(): bool
    {
        return false;
    }
}
