<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

/**
 * Candidate separately held monotonic authorization epoch per order.
 * NOT an operational ledger, contract approval, IAM or live revocation.
 */
interface PortalSiteCommercialEpochSource
{
    public function currentEpochFor(string $orderRef): ?int;
}
