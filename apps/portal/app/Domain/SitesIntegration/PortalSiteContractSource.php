<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

/** Trusted Portal-side provider. No operational implementation in this PR. */
interface PortalSiteContractSource
{
    public function findAuthorizedSiteOrder(string $orderRef): ?SiteContractSnapshot;
}
