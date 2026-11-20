<?php
declare(strict_types=1);
namespace App\Domain\SitesIntegration;

/** No authenticated transport or operational permission is bound by default. */
final class DenyingPortalLandingPageHeadServiceWitness implements PortalLandingPageHeadServiceWitness
{
    public function permits(string $authenticatedServiceRef, string $tenantRef, string $orderRef, string $siteRef): bool
    {
        return false;
    }
}
