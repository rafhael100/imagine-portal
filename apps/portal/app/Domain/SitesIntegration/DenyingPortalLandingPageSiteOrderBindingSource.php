<?php
declare(strict_types=1);
namespace App\Domain\SitesIntegration;

/** Default-deny until official transactional authorization and audit are available. */
final readonly class DenyingPortalLandingPageSiteOrderBindingSource implements PortalLandingPageSiteOrderBindingSource
{
    public function readCurrentBinding(
        string $tenantRef, string $orderRef, string $siteRef
    ): ?LandingPageSiteOrderBinding {
        return null;
    }
}
