<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

/** Default source denies every request until an authoritative implementation is reviewed. */
final class DenyingPortalSiteContractSource implements PortalSiteContractSource
{
    public function findAuthorizedSiteOrder(string $orderRef): ?SiteContractSnapshot
    {
        return null;
    }
}
