<?php

declare(strict_types=1);

namespace App\Domain\PortalAccess\Authority;

use App\Domain\Organizations\OrganizationId;
use InvalidArgumentException;

/**
 * F155: current, first-party Portal organization review evidence.
 * Constructible by a lab: this is NOT an authenticated SQL or issuer proof.
 */
final readonly class PortalOrganizationSnapshot
{
    public function __construct(
        public OrganizationId $organizationId,
        public bool $active,
        public int $securityRevision,
    ) {
        if ($securityRevision <= 0) {
            throw new InvalidArgumentException('Organization security revision must be positive.');
        }
    }
}
