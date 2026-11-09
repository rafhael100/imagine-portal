<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

/** Independent current Portal resource authority. No operational adapter yet. */
interface PortalReviewAuthoritySource
{
    public function findCurrent(string $tenantRef,string $siteRef):?PortalReviewAuthoritySnapshot;
}
