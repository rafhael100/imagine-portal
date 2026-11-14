<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

/**
 * Read current immutable order-to-release assignment from a trusted backend.
 * Source must enforce IAM/tenant, contract validity and versioned revision.
 * Null means deny; a request cannot furnish its own association.
 */
interface PortalCurrentSiteReleaseAssignmentSource
{
    public function readCurrent(
        string $orderRef, string $siteRef, string $releaseRef, int $editorialRevision
    ): ?PortalSitesReleaseAssignment;
}
