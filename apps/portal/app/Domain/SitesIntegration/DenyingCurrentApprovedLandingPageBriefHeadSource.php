<?php
declare(strict_types=1);
namespace App\Domain\SitesIntegration;

/** No configured official Portal ledger: deny even if upstream admission succeeds. */
final class DenyingCurrentApprovedLandingPageBriefHeadSource implements CurrentApprovedLandingPageBriefHeadSource
{
    public function readCurrent(string $tenantRef, string $orderRef, string $siteRef): ?ApprovedLandingPageBriefHead
    { return null; }
}
