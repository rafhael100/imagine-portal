<?php
declare(strict_types=1);
namespace App\Domain\SitesIntegration;

/**
 * Trusted independent tenant/order/site ownership and current contract source.
 * Request selectors alone confer no authority. Not bound to any operational DB.
 */
interface PortalLandingPageSiteOrderBindingSource
{
    public function readCurrentBinding(
        string $tenantRef, string $orderRef, string $siteRef
    ): ?LandingPageSiteOrderBinding;
}
