<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

/** Default is unavailable until a separately reviewed operational source exists. */
final class DenyingPortalSiteReleaseAssignmentSource implements PortalCurrentSiteReleaseAssignmentSource
{
    public function readCurrent(
        string $orderRef, string $siteRef, string $releaseRef, int $editorialRevision
    ): ?PortalSitesReleaseAssignment {
        return null;
    }
}
