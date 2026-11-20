<?php
declare(strict_types=1);
namespace App\Domain\SitesIntegration;

/** Independent peer ACL for Landing Page; identity must come from verified mTLS. */
interface PortalLandingPageHeadServiceWitness
{
    public function permits(string $authenticatedServiceRef, string $tenantRef, string $orderRef, string $siteRef): bool;
}
