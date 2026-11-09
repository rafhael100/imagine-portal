<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

/** Until trusted Portal authentication, resource membership and contract exist, DENY. */
final class DenyingPortalReviewAuthoritySource implements PortalReviewAuthoritySource
{
    public function findCurrent(string $tenantRef,string $siteRef):?PortalReviewAuthoritySnapshot
    {
        return null;
    }
}
