<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

/** Default refusal; no mTLS identity or tenant authorization provider bound. */
final class DenyingPortalOnepageHeadServiceWitness implements PortalOnepageHeadServiceWitness
{
    public function permits(
        string $authenticatedServiceRef, string $tenantRef,
        string $orderRef, string $siteRef,
    ): bool {
        return false;
    }
}
