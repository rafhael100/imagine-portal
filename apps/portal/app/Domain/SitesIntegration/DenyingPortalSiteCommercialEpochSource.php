<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

/** Default-deny: no independent trusted commercial authorization epoch. */
final class DenyingPortalSiteCommercialEpochSource implements PortalSiteCommercialEpochSource
{
    public function currentEpochFor(string $orderRef): ?int
    {
        return null;
    }
}
