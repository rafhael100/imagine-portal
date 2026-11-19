<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

/**
 * Future transactional Portal source of the LATEST approved Onepage head.
 *
 * Implementor MUST authenticate the service and verify tenant/order/site,
 * current revision, approval and revocation in official Portal storage.
 * Returning a value object is not, by itself, evidence of that authority.
 */
interface CurrentApprovedOnepageHeadSource
{
    public function readCurrent(
        string $tenantRef,
        string $orderRef,
        string $siteRef,
    ): ?ApprovedOnepageBriefSnapshot;
}
