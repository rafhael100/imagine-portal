<?php
declare(strict_types=1);
namespace App\Domain\SitesIntegration;

/**
 * Independent, future Portal transactionally authorized read of the latest
 * approved AND unrevoked Landing brief for this exact tenant/order/site.
 * Implementations must establish source revision, authenticated approval actor,
 * current revocation epoch and bytes atomically from official Portal storage.
 * Request selectors are not authority. No operational implementation exists.
 */
interface CurrentApprovedLandingPageBriefHeadSource
{
    public function readCurrent(string $tenantRef, string $orderRef, string $siteRef): ?ApprovedLandingPageBriefHead;
}
